from datetime import datetime
from typing import Literal

from pydantic import BaseModel, ConfigDict


class HealthData(BaseModel):
    model_config = ConfigDict(extra="forbid")

    service: Literal["grc-prediction-service"]
    status: Literal["ok"]
    schema_version: Literal["v1"]
    generated_at: datetime


class HealthResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    data: HealthData
