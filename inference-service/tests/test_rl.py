"""Contextual bandit, Q-table persistence and one-shot recommendation ids."""

from __future__ import annotations

import json

import pytest

from app.rl import (
    CATALOG,
    ContextualBandit,
    RecommendationAlreadyUsedError,
    RecommendationNotFoundError,
    RecommendationStore,
    confidence_band,
    state_key,
)
from app.settings import Settings


def settings_for(tmp_path, **overrides) -> Settings:
    return Settings(rl_qtable_path=tmp_path / "rl_qtable.json", rl_seed=7, **overrides)


def test_state_key_uses_the_configured_confidence_bands(tmp_path) -> None:
    settings = settings_for(tmp_path)

    assert confidence_band(0.69, settings) == "low"
    assert confidence_band(0.70, settings) == "medium"
    assert confidence_band(0.89, settings) == "medium"
    assert confidence_band(0.90, settings) == "high"
    assert state_key("pneumonia", 0.95, settings) == "PNEUMONIA:high"


def test_catalog_is_closed_unique_and_localised() -> None:
    assert len(CATALOG["PNEUMONIA"]) <= 5
    assert len(CATALOG["NORMAL"]) <= 5

    for actions in CATALOG.values():
        ids = [action.id for action in actions]

        assert len(ids) == len(set(ids))
        assert all(action.label("es") and action.label("en") for action in actions)
        assert all(action.hint("es") and action.hint("en") for action in actions)


def test_greedy_policy_exploits_the_highest_prior(tmp_path) -> None:
    bandit = ContextualBandit(settings_for(tmp_path))
    selection = bandit.choose("PNEUMONIA:high", epsilon=0.0)

    assert selection.action.id == "seek_medical_evaluation"
    assert selection.is_exploration is False
    assert selection.q_value == pytest.approx(0.25)


def test_epsilon_one_marks_the_choice_as_exploration(tmp_path) -> None:
    bandit = ContextualBandit(settings_for(tmp_path))
    selection = bandit.choose("NORMAL:low", epsilon=1.0)

    assert selection.is_exploration is True
    assert selection.action.id in {action.id for action in CATALOG["NORMAL"]}


def test_epsilon_greedy_is_reproducible_with_a_fixed_seed(tmp_path) -> None:
    first = ContextualBandit(settings_for(tmp_path))
    second = ContextualBandit(settings_for(tmp_path))

    picks_first = [first.choose("PNEUMONIA:medium", epsilon=0.5).action.id for _ in range(20)]
    picks_second = [second.choose("PNEUMONIA:medium", epsilon=0.5).action.id for _ in range(20)]

    assert picks_first == picks_second


def test_update_follows_the_documented_q_learning_rule(tmp_path) -> None:
    bandit = ContextualBandit(settings_for(tmp_path, rl_alpha=0.2))
    old_q = bandit.choose("PNEUMONIA:high", epsilon=0.0).q_value

    result = bandit.update("PNEUMONIA:high", "seek_medical_evaluation", reward=1)

    assert result.old_q == pytest.approx(old_q)
    assert result.new_q == pytest.approx(old_q + 0.2 * (1 - old_q))
    assert result.feedback_count == 1
    assert bandit.choose("PNEUMONIA:high", epsilon=0.0).q_value == pytest.approx(result.new_q)


def test_reward_must_be_plus_or_minus_one(tmp_path) -> None:
    bandit = ContextualBandit(settings_for(tmp_path))

    with pytest.raises(ValueError):
        bandit.update("NORMAL:low", "symptom_watch", reward=0)

    with pytest.raises(ValueError):
        bandit.update("NORMAL:low", "symptom_watch", reward=2)


def test_unknown_state_or_action_is_rejected(tmp_path) -> None:
    bandit = ContextualBandit(settings_for(tmp_path))

    with pytest.raises(ValueError):
        bandit.update("COVID:high", "seek_medical_evaluation", reward=1)

    with pytest.raises(ValueError):
        bandit.update("NORMAL:low", "seek_medical_evaluation", reward=1)


def test_q_table_and_counter_survive_a_restart(tmp_path) -> None:
    path = tmp_path / "rl_qtable.json"
    settings = Settings(rl_qtable_path=path, rl_seed=7)
    bandit = ContextualBandit(settings)

    result = bandit.update("PNEUMONIA:high", "warning_signs", reward=1)

    reloaded = ContextualBandit(settings)

    assert reloaded.feedback_count == 1
    assert reloaded.ranked("PNEUMONIA:high")
    assert next(
        ranked.q_value
        for ranked in reloaded.ranked("PNEUMONIA:high")
        if ranked.action.id == "warning_signs"
    ) == pytest.approx(result.new_q)

    payload = json.loads(path.read_text(encoding="utf-8"))

    assert set(payload) == {"version", "feedback_count", "q"}
    assert payload["feedback_count"] == 1
    assert not (tmp_path / "rl_qtable.json.tmp").exists()


def test_a_corrupt_q_table_does_not_stop_the_agent(tmp_path) -> None:
    path = tmp_path / "rl_qtable.json"
    path.write_text("{not json", encoding="utf-8")

    bandit = ContextualBandit(Settings(rl_qtable_path=path))

    assert bandit.feedback_count == 0
    assert bandit.choose("NORMAL:high", epsilon=0.0).action.id in {
        action.id for action in CATALOG["NORMAL"]
    }


def test_feedback_is_accepted_only_once_per_recommendation(tmp_path) -> None:
    store = RecommendationStore(settings_for(tmp_path))
    recommendation_id = store.create("NORMAL:low", "symptom_watch")

    record = store.consume(recommendation_id)

    assert record.state == "NORMAL:low"
    assert record.action_id == "symptom_watch"

    with pytest.raises(RecommendationAlreadyUsedError):
        store.consume(recommendation_id)


def test_unknown_and_expired_ids_are_rejected(tmp_path) -> None:
    now = [1000.0]
    settings = settings_for(tmp_path, rl_recommendation_ttl_seconds=60)
    store = RecommendationStore(settings, clock=lambda: now[0])

    with pytest.raises(RecommendationNotFoundError):
        store.consume("00000000-0000-4000-8000-000000000000")

    recommendation_id = store.create("NORMAL:high", "routine_prevention")
    now[0] += 61

    with pytest.raises(RecommendationNotFoundError):
        store.consume(recommendation_id)


def test_store_keeps_only_state_and_action(tmp_path) -> None:
    store = RecommendationStore(settings_for(tmp_path))
    recommendation_id = store.create("PNEUMONIA:medium", "warning_signs")
    record = store.consume(recommendation_id)

    assert set(vars(record)) == {"state", "action_id", "created_at", "used"}
