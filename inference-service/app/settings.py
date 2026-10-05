"""
Configuration for the inference service.

Every value can be overridden with an ``ML_`` prefixed environment variable so
the service can be tuned without editing code. The defaults describe the CNN
that ships with this project (150x150 single channel, sigmoid output).
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Literal

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
