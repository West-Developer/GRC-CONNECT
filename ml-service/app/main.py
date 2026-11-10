import re
from collections.abc import Awaitable, Callable, Mapping
from contextlib import asynccontextmanager
from datetime import UTC, datetime
from typing import Any
from uuid import uuid4

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException
from starlette.responses import Response

from app.schemas.attrition import (
    AttritionPredictionRequest,
    AttritionPredictionResponse,
)
from app.schemas.health import HealthData, HealthResponse
from app.schemas.section_demand import (
    SectionDemandPredictionRequest,
    SectionDemandPredictionResponse,
)
from app.services.attrition import AttritionPredictor
from app.services.section_demand import SectionDemandPredictor

REQUEST_ID_HEADER = "X-Request-ID"
REQUEST_ID_PATTERN = re.compile(r"^[A-Za-z0-9._-]{1,128}$")


@asynccontextmanager
async def lifespan(_: FastAPI) -> Any:
    """Reserve the lifecycle boundary for future approved model loading."""
    yield


app = FastAPI(
    title="GRC Prediction Service",
    description="Private, backend-only prediction contracts for the GRC enrollment platform.",
    version="0.1.0",
    docs_url="/internal/docs",
    openapi_url="/internal/openapi.json",
    redoc_url=None,
    lifespan=lifespan,
)


@app.middleware("http")
async def attach_request_id(
    request: Request,
    call_next: Callable[[Request], Awaitable[Response]],
) -> Response:
    incoming_request_id = request.headers.get(REQUEST_ID_HEADER, "")
    request_id = (
        incoming_request_id if REQUEST_ID_PATTERN.fullmatch(incoming_request_id) else str(uuid4())
    )
    request.state.request_id = request_id

    response = await call_next(request)
    response.headers[REQUEST_ID_HEADER] = request_id
    response.headers["Cache-Control"] = "no-store"

    return response


@app.get(
    "/internal/v1/health",
    response_model=HealthResponse,
    tags=["system"],
    summary="Read the prediction-service liveness state",
)
async def health() -> HealthResponse:
    return HealthResponse(
        data=HealthData(
            service="grc-prediction-service",
            status="ok",
            schema_version="v1",
            generated_at=datetime.now(UTC),
        )
    )


@app.post(
    "/internal/v1/section-demand/predict",
    response_model=SectionDemandPredictionResponse,
    tags=["section-demand"],
    summary="Predict section demand from aggregate historical observations",
)
def predict_section_demand(
    request: SectionDemandPredictionRequest,
) -> SectionDemandPredictionResponse:
    return SectionDemandPredictionResponse(
        data=SectionDemandPredictor().predict(
            request.data.observations,
            request.data.targets,
            request.data.feature_schema_version,
        )
    )


@app.post(
    "/internal/v1/attrition/predict",
    response_model=AttritionPredictionResponse,
    tags=["attrition"],
    summary="Predict student attrition risk using XGBoost",
)
def predict_attrition(
    request: AttritionPredictionRequest,
) -> AttritionPredictionResponse:
    return AttritionPredictionResponse(
        data=AttritionPredictor().predict(
            request.data.observations,
            request.data.targets,
        )
    )


def request_id_for(request: Request) -> str:
    return str(getattr(request.state, "request_id", uuid4()))


def error_response(
    *,
    status_code: int,
    code: str,
    message: str,
    request_id: str,
    errors: dict[str, list[str]] | None = None,
    headers: Mapping[str, str] | None = None,
) -> JSONResponse:
    response_headers = dict(headers or {})
    response_headers[REQUEST_ID_HEADER] = request_id
    response_headers["Cache-Control"] = "no-store"

    return JSONResponse(
        status_code=status_code,
        headers=response_headers,
        content={
            "error": {
                "code": code,
                "message": message,
                "errors": errors or {},
                "request_id": request_id,
            }
        },
    )


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(
    request: Request,
    exception: RequestValidationError,
) -> JSONResponse:
    field_errors: dict[str, list[str]] = {}

    for error in exception.errors():
        location = ".".join(str(segment) for segment in error["loc"] if segment != "body")
        field_errors.setdefault(location or "request", []).append(str(error["msg"]))

    return error_response(
        status_code=422,
        code="VALIDATION_FAILED",
        message="The submitted data is invalid.",
        errors=field_errors,
        request_id=request_id_for(request),
    )


@app.exception_handler(StarletteHTTPException)
async def http_exception_handler(
    request: Request,
    exception: StarletteHTTPException,
) -> JSONResponse:
    if exception.status_code == 404:
        return error_response(
            status_code=404,
            code="NOT_FOUND",
            message="The requested resource was not found.",
            request_id=request_id_for(request),
            headers=exception.headers,
        )

    if exception.status_code == 405:
        return error_response(
            status_code=405,
            code="METHOD_NOT_ALLOWED",
            message="The requested method is not allowed for this resource.",
            request_id=request_id_for(request),
            headers=exception.headers,
        )

    return error_response(
        status_code=exception.status_code,
        code="REQUEST_FAILED",
        message="The request could not be completed.",
        request_id=request_id_for(request),
        headers=exception.headers,
    )


@app.exception_handler(Exception)
async def unhandled_exception_handler(
    request: Request,
    _: Exception,
) -> JSONResponse:
    return error_response(
        status_code=500,
        code="INTERNAL_ERROR",
        message="The service could not complete the request.",
        request_id=request_id_for(request),
    )
