"""
Orchestrates the post-CNN pipeline: RL action -> prompt -> LLM -> validated text.

The recommendation is always best-effort. If the model is absent, slow, or
returns something shorter than the contract, this module retries once and then
returns deterministic fallback copy. A failure here can never turn a valid
prediction into an error response.
"""

from __future__ import annotations

import logging

from app.llm import QwenRecommendationLLM
from app.prompts import build_prompts, fallback_text, postprocess_text
from app.rl import (
    ContextualBandit,
    RecommendationStore,
    confidence_band,
    state_key,
)
from app.settings import Settings

logger = logging.getLogger(__name__)


class RecommendationService:
    def __init__(
        self,
        llm: QwenRecommendationLLM,
        bandit: ContextualBandit,
        store: RecommendationStore,
        settings: Settings,
    ) -> None:
        self._llm = llm
        self._bandit = bandit
        self._store = store
        self._settings = settings

    def recommend(self, predicted_class: str, confidence: float, locale: str) -> dict[str, object]:
        state = state_key(predicted_class, confidence, self._settings)
        band = confidence_band(confidence, self._settings)
        selection = self._bandit.choose(state)
        prompts = build_prompts(
            predicted_class=predicted_class,
            confidence=confidence,
            band=band,
            action=selection.action,
            locale=locale,
            min_chars=self._settings.llm_min_chars,
            max_chars=self._settings.llm_max_chars,
        )

        text = self._generate(prompts.system, prompts.user)
        source = "llm" if text is not None else "fallback"

        if text is None:
            text = fallback_text(predicted_class, band, locale)

        recommendation_id = self._store.create(state=state, action_id=selection.action.id)

        return {
            "recommendation_id": recommendation_id,
            "text": text,
            "source": source,
            "locale": locale,
            "predicted_class": predicted_class.strip().upper(),
            "confidence": round(max(0.0, min(1.0, confidence)), 4),
            "confidence_band": band,
            "state": state,
            "action": {
                "id": selection.action.id,
                "label": selection.action.label(locale),
                "hint": selection.action.hint(locale),
            },
            "rl": {
                "q_value": round(selection.q_value, 4),
                "is_exploration": selection.is_exploration,
                "feedback_count": self._bandit.feedback_count,
                "ranked_actions": [
                    {
                        "id": ranked.action.id,
                        "label": ranked.action.label(locale),
                        "q_value": round(ranked.q_value, 4),
                    }
                    for ranked in self._bandit.ranked(state)
                ],
            },
        }

    def feedback(self, recommendation_id: str, reward: int) -> dict[str, object]:
        record = self._store.consume(recommendation_id)
        result = self._bandit.update(record.state, record.action_id, reward)

        return {
            "old_q": result.old_q,
            "new_q": result.new_q,
            "feedback_count": result.feedback_count,
            "state": result.state,
            "action": result.action_id,
        }

    def status(self) -> dict[str, object]:
        return self._bandit.status()

    def _generate(self, system_prompt: str, user_prompt: str) -> str | None:
        """
        Try once, then once more; return ``None`` if neither attempt is usable.

        ``llm_enabled=false`` is a supported deployment mode: the assistant
        still answers with the reviewed fallback copy.
        """
        if not self._settings.llm_enabled:
            return None

        for attempt in range(2):
            try:
                raw = self._llm.generate(system_prompt, user_prompt)
            except Exception as error:  # noqa: BLE001 - every LLM failure is recoverable
                logger.warning(
                    "Recommendation LLM attempt %d failed (%s).",
                    attempt + 1,
                    type(error).__name__,
                )
                continue

            text = postprocess_text(raw, self._settings.llm_min_chars)

            if text is not None:
                return text

            logger.warning("Recommendation LLM attempt %d did not meet the text contract.", attempt + 1)

        return None
