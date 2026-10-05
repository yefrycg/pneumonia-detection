"""
Wire format shared with the Laravel application.

The field named ``class`` is a Python keyword, so it is declared as
``predicted_class`` with an alias and serialized back to ``class``. Keeping the
alias here means the contract lives in one place instead of being assembled by
hand in the endpoint.
"""

from __future__ import annotations

from pydantic import BaseModel, ConfigDict, Field


class ModelInfo(BaseModel):
    name: str


class PredictionPayload(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    predicted_class: str = Field(alias="class", description="Winning class label.")
    probabilities: dict[str, float] = Field(
        description="Probability for every configured class, summing to 1."
    )


class PredictResponse(BaseModel):
    success: bool = True
    model: ModelInfo
    prediction: PredictionPayload


class HealthResponse(BaseModel):
    status: str
    model_loaded: bool
    model_name: str


class ErrorDetail(BaseModel):
    code: str
    message: str


class ErrorResponse(BaseModel):
    success: bool = False
    error: ErrorDetail
