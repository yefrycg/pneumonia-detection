"""
HTTP contract tests.

The model is replaced with a stub so the suite runs without TensorFlow or the
.keras artifact; the real preprocessing still runs, which is what these tests
are checking at the boundary.
"""

from __future__ import annotations

import io
from typing import Any

import numpy as np
import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.main import app


class FakeRunner:
    """Minimal stand-in that records the tensor it was handed."""

    def __init__(self, scalar: float = 0.82, ready: bool = True) -> None:
        self._scalar = scalar
        self._ready = ready
        self.name = "fake-model"
        self.seen_shapes: list[tuple[int, ...]] = []

    @property
    def ready(self) -> bool:
        return self._ready

    def predict(self, tensor: np.ndarray) -> dict[str, float]:
        self.seen_shapes.append(tuple(tensor.shape))
        positive = self._scalar

        return {"PNEUMONIA": positive, "NORMAL": 1.0 - positive}

    def predicted_class(self, probabilities: dict[str, float]) -> str:
        return max(probabilities, key=probabilities.__getitem__)


def png_bytes(size: tuple[int, int] = (300, 200), mode: str = "L") -> bytes:
    buffer = io.BytesIO()
    Image.new(mode, size, 180).save(buffer, format="PNG")

    return buffer.getvalue()


@pytest.fixture
def runner() -> FakeRunner:
    return FakeRunner()


@pytest.fixture
def client(runner: FakeRunner):
    with TestClient(app) as test_client:
        app.state.runner = runner

        yield test_client


def test_health_reports_a_loaded_model(client: TestClient) -> None:
    body = client.get("/health").json()

    assert body["status"] == "ok"
    assert body["model_loaded"] is True
    assert body["model_name"] == "fake-model"


def test_health_still_answers_when_the_model_is_missing(client: TestClient) -> None:
    app.state.runner = FakeRunner(ready=False)

    body = client.get("/health").json()

    assert body["status"] == "degraded"
    assert body["model_loaded"] is False


def test_predict_returns_the_laravel_contract(client: TestClient, runner: FakeRunner) -> None:
    response = client.post(
        "/predict",
        files={"image": ("scan.png", png_bytes(), "image/png")},
    )

    assert response.status_code == 200

    body = response.json()

    assert body["success"] is True
    assert body["model"]["name"] == "fake-model"
    assert body["prediction"]["class"] == "PNEUMONIA"
    assert set(body["prediction"]["probabilities"]) == {"NORMAL", "PNEUMONIA"}
    assert sum(body["prediction"]["probabilities"].values()) == pytest.approx(1.0)


def test_predict_hands_the_runner_the_expected_tensor_shape(
    client: TestClient, runner: FakeRunner
) -> None:
    client.post("/predict", files={"image": ("scan.png", png_bytes((640, 480)), "image/png")})

    assert runner.seen_shapes == [(1, 150, 150, 1)]


def test_predict_reports_the_negative_class_below_the_threshold(
    client: TestClient,
) -> None:
    app.state.runner = FakeRunner(scalar=0.05)

    body = client.post(
        "/predict",
        files={"image": ("scan.png", png_bytes(), "image/png")},
    ).json()

    assert body["prediction"]["class"] == "NORMAL"


def test_predict_rejects_bytes_that_are_not_an_image(client: TestClient) -> None:
    response = client.post(
        "/predict",
        files={"image": ("scan.png", b"definitely not an image", "image/png")},
    )

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_IMAGE"


def test_predict_rejects_a_truncated_image(client: TestClient) -> None:
    response = client.post(
        "/predict",
        files={"image": ("scan.png", png_bytes()[:40], "image/png")},
    )

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_IMAGE"


def test_predict_rejects_an_unsupported_content_type(client: TestClient) -> None:
    response = client.post(
        "/predict",
        files={"image": ("scan.gif", png_bytes(), "image/gif")},
    )

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_IMAGE"


def test_predict_rejects_a_missing_file(client: TestClient) -> None:
    response = client.post("/predict")

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_IMAGE"


def test_predict_answers_503_when_no_model_is_loaded(client: TestClient) -> None:
    app.state.runner = FakeRunner(ready=False)

    response = client.post(
        "/predict",
        files={"image": ("scan.png", png_bytes(), "image/png")},
    )

    assert response.status_code == 503
    assert response.json()["error"]["code"] == "MODEL_UNAVAILABLE"


def test_predict_rejects_an_image_over_the_dimension_limit(client: TestClient) -> None:
    response = client.post(
        "/predict",
        files={"image": ("scan.png", png_bytes((5000, 100)), "image/png")},
    )

    assert response.status_code == 413
    assert response.json()["error"]["code"] == "INVALID_IMAGE"
