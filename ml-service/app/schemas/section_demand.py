from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class DemandObservation(BaseModel):
    model_config = ConfigDict(extra="forbid")

    cohort_size: int = Field(ge=0)
    enrolled_count: int = Field(ge=0)
    section_count: int = Field(ge=0)
    offered_capacity: int = Field(ge=0)
    year_level: int = Field(ge=1, le=4)
    semester: Literal["1st", "2nd"]


class DemandTarget(BaseModel):
    model_config = ConfigDict(extra="forbid")

    key: str = Field(min_length=1, max_length=160)
    cohort_size: int = Field(ge=0)
    section_count: int = Field(ge=0)
    recommended_capacity: int = Field(ge=1)
    year_level: int = Field(ge=1, le=4)
    semester: Literal["1st", "2nd"]


class SectionDemandPredictionInput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    feature_schema_version: Literal["v1", "v2"]
    observations: list[DemandObservation] = Field(min_length=1)
    targets: list[DemandTarget] = Field(min_length=1)


class SectionDemandPredictionRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    data: SectionDemandPredictionInput


class DemandForecast(BaseModel):
    model_config = ConfigDict(extra="forbid")

    key: str
    predicted_demand: float = Field(ge=0)
    confidence_lower: float = Field(ge=0)
    confidence_upper: float = Field(ge=0)
    suggested_section_count: int = Field(ge=0)


class DemandModelMetrics(BaseModel):
    """Quality information that makes an advisory model run auditable."""

    model_config = ConfigDict(extra="forbid")

    training_observation_count: int = Field(ge=0)
    validation_observation_count: int = Field(ge=0)
    mae: float | None = Field(default=None, ge=0)
    rmse: float | None = Field(default=None, ge=0)


class SectionDemandPredictionData(BaseModel):
    model_config = ConfigDict(extra="forbid")

    model_version: Literal["section-demand-rf-v1", "section-demand-rf-v2"]
    feature_schema_version: Literal["v1", "v2"]
    strategy: Literal["random_forest", "historical_baseline"]
    metrics: DemandModelMetrics
    forecasts: list[DemandForecast]


class SectionDemandPredictionResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    data: SectionDemandPredictionData
