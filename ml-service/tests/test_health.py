from datetime import datetime

from fastapi.testclient import TestClient

from app.main import REQUEST_ID_HEADER, app

client = TestClient(app)


def test_health_returns_versioned_success_envelope() -> None:
    response = client.get("/internal/v1/health")

    assert response.status_code == 200
    assert response.headers["cache-control"] == "no-store"
    assert response.headers[REQUEST_ID_HEADER]
    assert response.json()["data"] == {
        "service": "grc-prediction-service",
        "status": "ok",
        "schema_version": "v1",
        "generated_at": response.json()["data"]["generated_at"],
    }
    assert datetime.fromisoformat(response.json()["data"]["generated_at"])


def test_health_preserves_a_safe_request_id() -> None:
    response = client.get(
        "/internal/v1/health",
        headers={REQUEST_ID_HEADER: "phase0-health-check"},
    )

    assert response.headers[REQUEST_ID_HEADER] == "phase0-health-check"


def test_health_replaces_an_unsafe_request_id() -> None:
    response = client.get(
        "/internal/v1/health",
        headers={REQUEST_ID_HEADER: "unsafe request id"},
    )

    assert response.headers[REQUEST_ID_HEADER] != "unsafe request id"
    assert response.headers[REQUEST_ID_HEADER]


def test_unknown_route_uses_safe_error_envelope() -> None:
    response = client.get("/internal/v1/missing")

    assert response.status_code == 404
    assert response.json() == {
        "error": {
            "code": "NOT_FOUND",
            "message": "The requested resource was not found.",
            "errors": {},
            "request_id": response.headers[REQUEST_ID_HEADER],
        }
    }
    assert response.headers["cache-control"] == "no-store"


def test_unsupported_method_preserves_protocol_and_operational_headers() -> None:
    response = client.post("/internal/v1/health")

    assert response.status_code == 405
    assert "GET" in response.headers["allow"]
    assert response.headers["cache-control"] == "no-store"
    assert response.json() == {
        "error": {
            "code": "METHOD_NOT_ALLOWED",
            "message": "The requested method is not allowed for this resource.",
            "errors": {},
            "request_id": response.headers[REQUEST_ID_HEADER],
        }
    }


def test_unhandled_error_keeps_the_body_and_header_request_ids_aligned() -> None:
    async def raise_unhandled_error() -> None:
        raise RuntimeError("sensitive test detail")

    route_count = len(app.router.routes)
    app.add_api_route(
        "/internal/v1/test/unhandled",
        raise_unhandled_error,
        methods=["GET"],
        include_in_schema=False,
    )

    try:
        with TestClient(app, raise_server_exceptions=False) as error_client:
            response = error_client.get(
                "/internal/v1/test/unhandled",
                headers={REQUEST_ID_HEADER: "ml-error-correlation"},
            )
    finally:
        del app.router.routes[route_count:]

    assert response.status_code == 500
    assert response.headers[REQUEST_ID_HEADER] == "ml-error-correlation"
    assert response.headers["cache-control"] == "no-store"
    assert response.json() == {
        "error": {
            "code": "INTERNAL_ERROR",
            "message": "The service could not complete the request.",
            "errors": {},
            "request_id": "ml-error-correlation",
        }
    }
    assert "sensitive test detail" not in response.text
