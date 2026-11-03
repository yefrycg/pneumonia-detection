"""
Wire format shared with the Laravel application.

The field named ``class`` is a Python keyword, so it is declared as
``predicted_class`` with an alias and serialized back to ``class``. Keeping the
alias here means the contract lives in one place instead of being assembled by
hand in the endpoint.
"""

from __future__ import annotations

from typing import Literal
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, field_validator


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
    llm_loaded: bool = False
    rl_loaded: bool = False
    rl_feedback_count: int = 0


class RecommendationRequest(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    predicted_class: str = Field(alias="class", min_length=1, max_length=32)
    confidence: float = Field(ge=0.0, le=1.0)
    locale: Literal["es", "en"] = "es"

    @field_validator("predicted_class")
    @classmethod
    def normalize_class(cls, value: str) -> str:
        return value.strip().upper()


class ActionInfo(BaseModel):
    id: str
    label: str
    hint: str


class RankedAction(BaseModel):
    id: str
    label: str
    q_value: float


class RlInsight(BaseModel):
    q_value: float
    is_exploration: bool
    feedback_count: int = Field(ge=0)
    ranked_actions: list[RankedAction]


class RecommendationResponse(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    success: bool = True
    recommendation_id: str
    text: str
    source: Literal["llm", "fallback"]
    locale: Literal["es", "en"]
    predicted_class: str = Field(alias="class")
    confidence: float
    confidence_band: Literal["low", "medium", "high"]
    state: str
    action: ActionInfo
    rl: RlInsight


class FeedbackRequest(BaseModel):
    recommendation_id: UUID
    reward: Literal[1, -1]


class FeedbackResponse(BaseModel):
    success: bool = True
    old_q: float
    new_q: float
    feedback_count: int = Field(ge=0)
    state: str
    action: str


class RlStatusResponse(BaseModel):
    success: bool = True
    alpha: float
    epsilon: float
    feedback_count: int
    q_table: dict[str, dict[str, float]]


class ErrorDetail(BaseModel):
    code: str
    message: str


class ErrorResponse(BaseModel):
    success: bool = False
    error: ErrorDetail
