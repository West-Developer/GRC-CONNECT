from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_attrition_prediction_returns_valid_risk_forecasts() -> None:
    response = client.post(
        "/internal/v1/attrition/predict",
        json={
            "data": {
                "feature_schema_version": "v1",
                "observations": [
                    {
                        "year_level": 1,
                        "gpa": 1.5,
                        "failed_units": 0,
                        "dropped_units": 0,
                        "is_irregular": 0,
                        "consecutive_terms": 2,
                        "attrited": 0,
                    },
                    {
                        "year_level": 2,
                        "gpa": 1.75,
                        "failed_units": 0,
                        "dropped_units": 0,
                        "is_irregular": 0,
                        "consecutive_terms": 3,
                        "attrited": 0,
                    },
                    {
                        "year_level": 1,
                        "gpa": 3.75,
                        "failed_units": 9,
                        "dropped_units": 3,
                        "is_irregular": 1,
                        "consecutive_terms": 1,
                        "attrited": 1,
                    },
                    {
                        "year_level": 2,
                        "gpa": 4.0,
                        "failed_units": 12,
                        "dropped_units": 6,
                        "is_irregular": 1,
                        "consecutive_terms": 2,
                        "attrited": 1,
                    },
                ],
                "targets": [
                    {
                        "student_id": 42,
                        "year_level": 1,
                        "gpa": 3.8,
                        "failed_units": 9,
                        "dropped_units": 3,
                        "is_irregular": 1,
                        "consecutive_terms": 1,
                    }
                ],
            }
        },
    )

    assert response.status_code == 200
    body = response.json()["data"]
    assert body["model_version"] == "attrition-xgboost-v1"
    assert body["strategy"] == "xgboost"
    assert len(body["predictions"]) == 1
    forecast = body["predictions"][0]
    assert forecast["student_id"] == 42
    assert 0.0 <= forecast["risk_probability"] <= 1.0
    assert forecast["risk_band"] in ["low", "medium", "high", "critical"]
    assert len(forecast["explanations"]) > 0
