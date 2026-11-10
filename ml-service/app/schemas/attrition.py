from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class AttritionObservation(BaseModel):
    model_config = ConfigDict(extra="forbid")

    year_level: int = Field(ge=1, le=4)
    gpa: float = Field(ge=1.0, le=5.0)
    failed_units: int = Field(ge=0)
    dropped_units: int = Field(ge=0)
    is_irregular: int = Field(ge=0, le=1)
    consecutive_terms: int = Field(ge=1)
    attrited: int = Field(ge=0, le=1)


class AttritionTarget(BaseModel):
    model_config = ConfigDict(extra="forbid")

    student_id: int = Field(ge=1)
    year_level: int = Field(ge=1, le=4)
    gpa: float = Field(ge=1.0, le=5.0)
    failed_units: int = Field(ge=0)
    dropped_units: int = Field(ge=0)
    is_irregular: int = Field(ge=0, le=1)
    consecutive_terms: int = Field(ge=1)


class AttritionPredictionInput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    feature_schema_version: Literal["v1"] = "v1"
    observations: list[AttritionObservation] = Field(min_length=1)
    targets: list[AttritionTarget] = Field(min_length=1)


class AttritionPredictionRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    data: AttritionPredictionInput


class StudentAttritionForecast(BaseModel):
    model_config = ConfigDict(extra="forbid")

    student_id: int
    risk_probability: float = Field(ge=0.0, le=1.0)
    risk_band: Literal["low", "medium", "high", "critical"]
    explanations: list[str] = Field(default_factory=list)


class AttritionModelMetrics(BaseModel):
    model_config = ConfigDict(extra="forbid")

    training_observation_count: int = Field(ge=0)
    validation_observation_count: int = Field(ge=0)
    accuracy: float | None = Field(default=None, ge=0.0, le=1.0)


class AttritionPredictionData(BaseModel):
    model_config = ConfigDict(extra="forbid")

    model_version: Literal["attrition-xgboost-v1"]
    feature_schema_version: Literal["v1"]
    strategy: Literal["xgboost", "heuristic_baseline"]
    metrics: AttritionModelMetrics
    predictions: list[StudentAttritionForecast]


class AttritionPredictionResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    data: AttritionPredictionData
