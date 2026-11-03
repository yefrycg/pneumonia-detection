"""
Configuration for the inference service.

Every value can be overridden with an ``ML_`` prefixed environment variable so
the service can be tuned without editing code. The defaults describe the CNN
that ships with this project (150x150 single channel, sigmoid output) plus the
optional recommendation LLM and the contextual-bandit agent.

The LLM and RL settings are prefixed ``ML_LLM_*`` and ``ML_RL_*`` in the
environment because pydantic-settings combines the global ``ML_`` prefix with
the field name.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic import Field, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        env_prefix="ML_",
        extra="ignore",
    )

    # Artifact ---------------------------------------------------------------
    model_name: str = "modelo_neumonia_cnn"
    model_path: Path = Path("models/modelo_neumonia_cnn.keras")

    # Preprocessing ----------------------------------------------------------
    input_width: int = 150
    input_height: int = 150
    grayscale: bool = True
    normalization: Literal["div_255", "none"] = "div_255"

    # Output contract --------------------------------------------------------
    # A sigmoid head emits a single scalar for ``positive_class``; the other
    # class is its complement. Index meanings are never inferred from an array
    # position, only from these two names.
    positive_class: str = "PNEUMONIA"
    negative_class: str = "NORMAL"
    decision_threshold: float = 0.5

    # Uploads ----------------------------------------------------------------
    max_upload_mb: int = 10
    max_image_dimension: int = 4096
    allowed_media_types: tuple[str, ...] = ("image/jpeg", "image/png")

    # Server -----------------------------------------------------------------
    host: str = "127.0.0.1"
    port: int = 8000

    # Recommendation LLM -----------------------------------------------------
    # Qwen3 ships a "thinking" mode. It is disabled in the chat template and
    # stripped again in post-processing, because a short user-facing paragraph
    # is all this service needs and CPU latency is the main constraint.
    llm_enabled: bool = True
    llm_model_id: str = "Qwen/Qwen3-0.6B"
    llm_device: str = "cpu"
    llm_dtype: Literal["float32", "bfloat16", "float16"] = "float32"
    llm_max_new_tokens: int = Field(default=240, ge=32, le=1024)
    llm_temperature: float = Field(default=0.7, gt=0.0, le=2.0)
    llm_top_p: float = Field(default=0.8, gt=0.0, le=1.0)
    llm_top_k: int = Field(default=20, ge=0)
    llm_min_chars: int = Field(default=200, ge=1)
    llm_max_chars: int = Field(default=600, ge=200)

    # Contextual bandit RL ---------------------------------------------------
    # The Q-table is the only persisted state. It never contains images,
    # prompts, generated text or any other user-provided value.
    rl_qtable_path: Path = Path("data/rl_qtable.json")
    rl_alpha: float = Field(default=0.2, gt=0.0, le=1.0)
    rl_epsilon: float = Field(default=0.15, ge=0.0, le=1.0)
    rl_low_confidence_threshold: float = Field(default=0.70, ge=0.0, le=1.0)
    rl_high_confidence_threshold: float = Field(default=0.90, ge=0.0, le=1.0)
    rl_recommendation_ttl_seconds: int = Field(default=3600, ge=60)
    rl_max_pending_recommendations: int = Field(default=1000, ge=10)
    rl_seed: int | None = None

    @model_validator(mode="after")
    def validate_thresholds(self) -> "Settings":
        if self.rl_low_confidence_threshold >= self.rl_high_confidence_threshold:
            raise ValueError(
                "rl_low_confidence_threshold must be lower than "
                "rl_high_confidence_threshold"
            )

        return self

    @property
    def channels(self) -> int:
        return 1 if self.grayscale else 3

    @property
    def input_shape(self) -> tuple[int, int, int]:
        return (self.input_height, self.input_width, self.channels)

    @property
    def max_upload_bytes(self) -> int:
        return self.max_upload_mb * 1024 * 1024

    @property
    def class_names(self) -> tuple[str, str]:
        return (self.negative_class, self.positive_class)


@lru_cache
def get_settings() -> Settings:
    return Settings()
