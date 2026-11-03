"""
Contextual bandit agent for the recommendation emphasis.

The agent chooses *how* the recommendation is framed (the "action") from a
closed, medically reviewed catalogue. It never chooses clinical content, doses
or diagnoses; those rules live in :mod:`app.prompts` and are not negotiable.

State ``s`` = predicted class + confidence band, e.g. ``PNEUMONIA:high``.
Action ``a`` = one catalogue id for that class.
Update: ``Q(s, a) <- Q(s, a) + alpha * (reward - Q(s, a))`` with reward +1/-1.

Each interaction is a single-step decision, so this is a contextual bandit:
there is no next state and the discount factor gamma does not participate.
"""

from __future__ import annotations

import json
import logging
import os
import random
import threading
import time
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable, Literal

from app.settings import Settings

logger = logging.getLogger(__name__)

Band = Literal["low", "medium", "high"]

PNEUMONIA_ACTIONS = (
    (
        "seek_medical_evaluation",
        "Evaluación médica oportuna",
        "Prompt medical evaluation",
        "Enfatiza la importancia de una evaluación médica temprana y evita minimizar los hallazgos.",
        "Emphasize the importance of an early medical evaluation and avoid minimising the findings.",
    ),
    (
        "warning_signs",
        "Signos de alarma",
        "Warning signs",
        "Menciona signos de alarma respiratorios y cuándo buscar atención urgente.",
        "Mention respiratory warning signs and when to seek urgent care.",
    ),
    (
        "supportive_care_while_waiting",
        "Cuidados de apoyo",
        "Supportive care",
        "Sugiere descanso, hidratación y cuidados generales mientras se busca valoración, sin recomendar medicamentos.",
        "Suggest rest, hydration and general care while seeking assessment, without recommending medication.",
    ),
    (
        "follow_medical_treatment",
        "Seguimiento del tratamiento",
        "Follow medical treatment",
        "Insiste en seguir las indicaciones de un profesional y no autoprescribirse.",
        "Stress following a professional's instructions and not self-prescribing.",
    ),
    (
        "prevent_spread",
        "Prevención de contagios",
        "Prevent spread",
        "Incluye medidas generales de higiene y aislamiento responsable si hay síntomas.",
        "Include general hygiene measures and responsible isolation when symptoms are present.",
    ),
)

NORMAL_ACTIONS = (
    (
        "routine_prevention",
        "Prevención cotidiana",
        "Everyday prevention",
        "Refuerza medidas cotidianas de prevención respiratoria y de higiene.",
        "Reinforce everyday respiratory and hygiene prevention measures.",
    ),
    (
        "healthy_habits",
        "Hábitos saludables",
        "Healthy habits",
        "Relaciona el resultado con hábitos generales de salud, descanso e hidratación.",
        "Connect the result with general health habits, rest and hydration.",
    ),
    (
        "symptom_watch",
        "Vigilancia de síntomas",
        "Symptom watch",
        "Explica qué síntomas conviene vigilar y cuándo consultar a un profesional.",
        "Explain which symptoms to watch and when to consult a professional.",
    ),
    (
        "respiratory_care",
        "Cuidado respiratorio",
        "Respiratory care",
        "Sugiere cuidados respiratorios generales y evitar el tabaco o el humo.",
        "Suggest general respiratory care and avoiding tobacco or smoke.",
    ),
)


@dataclass(frozen=True)
class Action:
    id: str
    label_es: str
    label_en: str
    hint_es: str
    hint_en: str

    def label(self, locale: str) -> str:
        return self.label_en if locale == "en" else self.label_es

    def hint(self, locale: str) -> str:
        return self.hint_en if locale == "en" else self.hint_es


CATALOG: dict[str, tuple[Action, ...]] = {
    "PNEUMONIA": tuple(Action(*row) for row in PNEUMONIA_ACTIONS),
    "NORMAL": tuple(Action(*row) for row in NORMAL_ACTIONS),
}

# Small, medically conservative head start. ``seek_medical_evaluation`` is
# slightly preferred for pneumonia because the safe default is to seek care.
PRIORS: dict[tuple[str, str], float] = {
    ("PNEUMONIA", "seek_medical_evaluation"): 0.25,
    ("PNEUMONIA", "warning_signs"): 0.10,
    ("NORMAL", "routine_prevention"): 0.10,
    ("NORMAL", "symptom_watch"): 0.05,
}

BANDS: tuple[Band, ...] = ("low", "medium", "high")


def confidence_band(confidence: float, settings: Settings) -> Band:
    if confidence < settings.rl_low_confidence_threshold:
        return "low"

    if confidence < settings.rl_high_confidence_threshold:
        return "medium"

    return "high"


def state_key(predicted_class: str, confidence: float, settings: Settings) -> str:
    class_label = predicted_class.strip().upper()

    if class_label not in CATALOG:
        raise ValueError(f"Unsupported class for RL: {class_label}")

    return f"{class_label}:{confidence_band(confidence, settings)}"


def actions_for_state(state: str) -> tuple[Action, ...]:
    class_label = state.split(":", 1)[0]

    return CATALOG.get(class_label, ())


@dataclass(frozen=True)
class Selection:
    action: Action
    q_value: float
    is_exploration: bool


@dataclass(frozen=True)
class RankedAction:
    action: Action
    q_value: float


@dataclass(frozen=True)
class UpdateResult:
    state: str
    action_id: str
    old_q: float
    new_q: float
    feedback_count: int


def default_q_table() -> dict[str, dict[str, float]]:
    table: dict[str, dict[str, float]] = {}

    for class_label, actions in CATALOG.items():
        for band in BANDS:
            state = f"{class_label}:{band}"
            table[state] = {
                action.id: float(PRIORS.get((class_label, action.id), 0.0))
                for action in actions
            }

    return table


class ContextualBandit:
    """Epsilon-greedy contextual bandit backed by an atomically saved Q-table."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._path = Path(settings.rl_qtable_path)
        self._lock = threading.RLock()
        self._rng = random.Random(settings.rl_seed)
        self._q: dict[str, dict[str, float]] = default_q_table()
        self._feedback_count = 0

        self._load()

    @property
    def feedback_count(self) -> int:
        with self._lock:
            return self._feedback_count

    @property
    def path(self) -> Path:
        return self._path

    def choose(self, state: str, epsilon: float | None = None) -> Selection:
        """Pick an action with epsilon-greedy exploration."""
        threshold = self._settings.rl_epsilon if epsilon is None else epsilon

        if not 0.0 <= threshold <= 1.0:
            raise ValueError("epsilon must be within [0, 1]")

        with self._lock:
            if state not in self._q:
                raise ValueError(f"Unknown state: {state}")

            q_values = self._q[state]
            action_ids = list(q_values)

            if self._rng.random() < threshold:
                action_id = self._rng.choice(action_ids)
                exploring = True
            else:
                best = max(q_values.values())
                candidates = [action_id for action_id in action_ids if q_values[action_id] == best]
                action_id = self._rng.choice(candidates)
                exploring = False

            action = self._action(state, action_id)

            return Selection(
                action=action,
                q_value=q_values[action_id],
                is_exploration=exploring,
            )

    def ranked(self, state: str) -> list[RankedAction]:
        with self._lock:
            q_values = self._q.get(state, {})
            order = {action.id: index for index, action in enumerate(actions_for_state(state))}

            return [
                RankedAction(action=self._action(state, action_id), q_value=value)
                for action_id, value in sorted(
                    q_values.items(),
                    key=lambda item: (-item[1], order.get(item[0], 999)),
                )
            ]

    def update(self, state: str, action_id: str, reward: int) -> UpdateResult:
        """Apply one feedback update and persist the Q-table."""
        if reward not in (-1, 1):
            raise ValueError("reward must be +1 or -1")

        with self._lock:
            if state not in self._q or action_id not in self._q[state]:
                raise ValueError(f"Unknown state/action pair: {state}/{action_id}")

            old_q = self._q[state][action_id]
            new_q = old_q + self._settings.rl_alpha * (float(reward) - old_q)

            self._q[state][action_id] = new_q
            self._feedback_count += 1

            try:
                self._save()
            except OSError:
                # Keep memory and disk consistent if the write failed.
                self._q[state][action_id] = old_q
                self._feedback_count -= 1
                raise

            return UpdateResult(
                state=state,
                action_id=action_id,
                old_q=old_q,
                new_q=new_q,
                feedback_count=self._feedback_count,
            )

    def status(self) -> dict[str, object]:
        with self._lock:
            return {
                "alpha": self._settings.rl_alpha,
                "epsilon": self._settings.rl_epsilon,
                "feedback_count": self._feedback_count,
                "q_table": {state: dict(values) for state, values in self._q.items()},
            }

    def _action(self, state: str, action_id: str) -> Action:
        for action in actions_for_state(state):
            if action.id == action_id:
                return action

        raise ValueError(f"Unknown action {action_id} for state {state}")

    def _load(self) -> None:
        """Merge a previous Q-table, ignoring unknown keys and corrupt files."""
        if not self._path.is_file():
            return

        try:
            payload = json.loads(self._path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            logger.warning("Ignoring an unreadable RL Q-table at %s.", self._path)

            return

        if not isinstance(payload, dict):
            logger.warning("Ignoring an RL Q-table that is not a JSON object.")

            return

        stored = payload.get("q", {})
        feedback_count = payload.get("feedback_count", 0)

        if isinstance(stored, dict):
            for state, values in stored.items():
                if state not in self._q or not isinstance(values, dict):
                    continue

                for action_id, value in values.items():
                    if action_id in self._q[state] and isinstance(value, (int, float)):
                        self._q[state][action_id] = float(value)

        if isinstance(feedback_count, int) and feedback_count >= 0:
            self._feedback_count = feedback_count

        logger.info(
            "Loaded RL Q-table with %d feedback updates from %s.",
            self._feedback_count,
            self._path,
        )

    def _save(self) -> None:
        """
        Write only the Q-table and the feedback counter.

        A temporary file followed by ``os.replace`` keeps readers from seeing a
        partially written JSON document. No prompts, images or user values are
        ever part of this payload.
        """
        self._path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self._path.with_name(self._path.name + ".tmp")
        payload = {
            "version": 1,
            "feedback_count": self._feedback_count,
            "q": self._q,
        }

        with temporary.open("w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.flush()
            os.fsync(handle.fileno())

        os.replace(temporary, self._path)


class RecommendationNotFoundError(LookupError):
    """The id is unknown or its TTL elapsed."""


class RecommendationAlreadyUsedError(LookupError):
    """Feedback was already recorded for this id."""


@dataclass
class PendingRecommendation:
    state: str
    action_id: str
    created_at: float
    used: bool = False


@dataclass
class RecommendationStore:
    """
    In-memory id -> (state, action) map with a TTL.

    Only the agent's own state/action pair is kept. Generated text and any
    user value live in the response only and are never stored here.
    """

    settings: Settings
    clock: Callable[[], float] = time.monotonic
    _records: dict[str, PendingRecommendation] = field(default_factory=dict, init=False)
    _lock: threading.Lock = field(default_factory=threading.Lock, init=False)

    def create(self, state: str, action_id: str) -> str:
        recommendation_id = str(uuid.uuid4())
        now = self.clock()

        with self._lock:
            self._cleanup(now)

            if len(self._records) >= self.settings.rl_max_pending_recommendations:
                oldest = min(self._records, key=lambda key: self._records[key].created_at)
                self._records.pop(oldest, None)

            self._records[recommendation_id] = PendingRecommendation(
                state=state,
                action_id=action_id,
                created_at=now,
            )

        return recommendation_id

    def consume(self, recommendation_id: str) -> PendingRecommendation:
        now = self.clock()

        with self._lock:
            self._cleanup(now)
            record = self._records.get(recommendation_id)

            if record is None:
                raise RecommendationNotFoundError("Unknown or expired recommendation id.")

            if record.used:
                raise RecommendationAlreadyUsedError("Feedback already recorded for this recommendation.")

            record.used = True

            return record

    def _cleanup(self, now: float) -> None:
        ttl = self.settings.rl_recommendation_ttl_seconds
        expired = [
            recommendation_id
            for recommendation_id, record in self._records.items()
            if now - record.created_at > ttl
        ]

        for recommendation_id in expired:
            self._records.pop(recommendation_id, None)
