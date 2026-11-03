"""HTTP contract for recommendations and RL feedback.

The real Qwen checkpoint is never downloaded or loaded here: a ``FakeLLM``
stands in, and the Q-table points at a temporary directory.
"""

from __future__ import annotations

import uuid
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.recommendation import RecommendationService
from app.rl import ContextualBandit, RecommendationStore
from app.settings import Settings

GOOD_TEXT = (
    "El modelo experimental detectó un patrón compatible con neumonía y conviene buscar "
    "valoración médica pronta. Si los síntomas empeoran, acude a atención urgente. La "
    "confianza es alta, pero la evaluación profesional sigue siendo necesaria. Esta "
    "recomendación es solo un apoyo informativo."
)


class FakeLLM:
    def __init__(self, text: str = GOOD_TEXT, error: Exception | None = None) -> None:
        self.text = text
        self.error = error
        self.calls: list[tuple[str, str]] = []

    @property
    def ready(self) -> bool:
        return True

    def generate(self, system_prompt: str, user_prompt: str) -> str:
        self.calls.append((system_prompt, user_prompt))

        if self.error is not None:
            raise self.error

        return self.text


@pytest.fixture
def api(tmp_path: Path):
    settings = Settings(rl_qtable_path=tmp_path / "rl_qtable.json", rl_seed=7)
    bandit = ContextualBandit(settings)
    store = RecommendationStore(settings)
    llm = FakeLLM()
    recommender = RecommendationService(llm, bandit, store, settings)

    with TestClient(app) as client:
        app.state.bandit = bandit
        app.state.recommendation_store = store
        app.state.llm = llm
        app.state.recommender = recommender

        yield client, llm, settings, bandit


def recommend(client: TestClient, **overrides) -> dict:
    payload = {"class": "PNEUMONIA", "confidence": 0.95, "locale": "es", **overrides}

    return client.post("/recommend", json=payload).json()


def test_recommend_returns_the_full_contract(api) -> None:
    client, llm, _, _ = api
    body = recommend(client)

    assert body["success"] is True
    assert body["class"] == "PNEUMONIA"
    assert body["state"] == "PNEUMONIA:high"
    assert body["confidence_band"] == "high"
    assert body["source"] == "llm"
    assert len(body["text"]) >= 200
    assert body["locale"] == "es"
    assert uuid.UUID(body["recommendation_id"])
    assert body["action"]["id"] == "seek_medical_evaluation"
    assert body["action"]["label"] == "Evaluación médica oportuna"
    assert body["rl"]["q_value"] == pytest.approx(0.25)
    assert body["rl"]["is_exploration"] is False
    assert body["rl"]["feedback_count"] == 0
    assert len(body["rl"]["ranked_actions"]) == 5
    assert body["rl"]["ranked_actions"][0]["id"] == "seek_medical_evaluation"
    assert len(llm.calls) == 1
    assert "neumonía" in llm.calls[0][1].lower()
    assert "95 %" in llm.calls[0][1]


def test_recommend_uses_the_fallback_when_the_llm_raises(api) -> None:
    client, llm, _, _ = api
    llm.error = RuntimeError("model not available")

    body = recommend(client)

    assert body["source"] == "fallback"
    assert len(body["text"]) >= 200
    assert len(llm.calls) == 2
    assert "dificultad para respirar" in body["text"]


def test_recommend_retries_once_before_using_the_fallback_for_short_text(api) -> None:
    client, llm, _, _ = api
    llm.text = "Demasiado corto."

    body = recommend(client)

    assert body["source"] == "fallback"
    assert len(body["text"]) >= 200
    assert len(llm.calls) == 2


def test_recommend_rejects_an_unknown_class_without_calling_the_llm(api) -> None:
    client, llm, _, _ = api
    response = client.post(
        "/recommend",
        json={"class": "COVID", "confidence": 0.9, "locale": "es"},
    )

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "UNSUPPORTED_CLASS"
    assert llm.calls == []


@pytest.mark.parametrize(
    "payload",
    [
        {"class": "NORMAL", "confidence": 1.5, "locale": "es"},
        {"class": "NORMAL", "confidence": 0.5, "locale": "de"},
        {"class": "", "confidence": 0.5, "locale": "es"},
    ],
)
def test_recommend_validates_the_request(api, payload) -> None:
    client, _, _, _ = api
    response = client.post("/recommend", json=payload)

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_REQUEST"


def test_low_confidence_maps_to_a_low_band(api) -> None:
    client, _, _, _ = api
    body = recommend(client, confidence=0.61)

    assert body["state"] == "PNEUMONIA:low"
    assert body["confidence_band"] == "low"


def test_feedback_updates_the_q_value_and_cannot_be_repeated(api) -> None:
    client, _, settings, _ = api
    recommendation = recommend(client)
    recommendation_id = recommendation["recommendation_id"]
    old_q = recommendation["rl"]["q_value"]

    first = client.post(
        "/rl/feedback",
        json={"recommendation_id": recommendation_id, "reward": 1},
    )

    assert first.status_code == 200
    body = first.json()
    expected = old_q + settings.rl_alpha * (1 - old_q)

    assert body["old_q"] == pytest.approx(old_q)
    assert body["new_q"] == pytest.approx(expected)
    assert body["feedback_count"] == 1

    second = client.post(
        "/rl/feedback",
        json={"recommendation_id": recommendation_id, "reward": -1},
    )

    assert second.status_code == 409
    assert second.json()["error"]["code"] == "FEEDBACK_ALREADY_RECORDED"
    assert settings.rl_qtable_path.is_file()


def test_feedback_rejects_unknown_ids_and_invalid_rewards(api) -> None:
    client, _, _, _ = api

    unknown = client.post(
        "/rl/feedback",
        json={"recommendation_id": str(uuid.uuid4()), "reward": 1},
    )

    assert unknown.status_code == 404
    assert unknown.json()["error"]["code"] == "RECOMMENDATION_NOT_FOUND"

    invalid = client.post(
        "/rl/feedback",
        json={"recommendation_id": str(uuid.uuid4()), "reward": 0},
    )

    assert invalid.status_code == 422
    assert invalid.json()["error"]["code"] == "INVALID_FEEDBACK"


def test_status_reports_alpha_epsilon_counter_and_q_table(api) -> None:
    client, _, _, _ = api
    recommendation = recommend(client)
    client.post(
        "/rl/feedback",
        json={"recommendation_id": recommendation["recommendation_id"], "reward": -1},
    )

    body = client.get("/rl/status").json()

    assert body["alpha"] == pytest.approx(0.2)
    assert body["epsilon"] == pytest.approx(0.15)
    assert body["feedback_count"] == 1
    assert "PNEUMONIA:high" in body["q_table"]
    assert "NORMAL:low" in body["q_table"]


def test_health_adds_the_llm_and_rl_fields(api) -> None:
    client, _, _, bandit = api
    recommendation = recommend(client)
    client.post(
        "/rl/feedback",
        json={"recommendation_id": recommendation["recommendation_id"], "reward": 1},
    )

    body = client.get("/health").json()

    assert body["llm_loaded"] is True
    assert body["rl_loaded"] is True
    assert body["rl_feedback_count"] == bandit.feedback_count == 1
    assert "model_loaded" in body
    assert "model_name" in body
