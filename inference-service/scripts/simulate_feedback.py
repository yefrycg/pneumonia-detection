"""
Offline convergence demo for the contextual bandit.

The script uses a temporary Q-table, so it never touches the real learning file
and never downloads Qwen. Run it from the repository root or from
``inference-service``:

    .venv/Scripts/python scripts/simulate_feedback.py

It repeatedly applies +1 to the preferred action and -1 to the others for the
``PNEUMONIA:high`` state. Because the update is a single-step bandit rule, each
Q-value moves towards its reward with rate alpha.
"""

from __future__ import annotations

import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.rl import ContextualBandit, actions_for_state  # noqa: E402
from app.settings import Settings  # noqa: E402

STATE = "PNEUMONIA:high"
PREFERRED_ACTION = "seek_medical_evaluation"
EPISODES = 50


def main() -> None:
    with tempfile.TemporaryDirectory() as directory:
        settings = Settings(
            rl_qtable_path=Path(directory) / "qtable.json",
            rl_seed=7,
        )
        bandit = ContextualBandit(settings)
        actions = actions_for_state(STATE)

        print(f"State: {STATE}")
        print(f"alpha={settings.rl_alpha}, epsilon={settings.rl_epsilon}\n")

        for episode in range(1, EPISODES + 1):
            for action in actions:
                reward = 1 if action.id == PREFERRED_ACTION else -1
                bandit.update(STATE, action.id, reward)

            if episode % 10 == 0:
                values = bandit.status()["q_table"][STATE]
                formatted = ", ".join(
                    f"{action.id}={values[action.id]:+.3f}" for action in actions
                )
                print(f"episode {episode:>2}: {formatted}")

        print(f"\nPersisted feedback count: {bandit.feedback_count}")
        print("The preferred action converges towards +1; the rest towards -1.")


if __name__ == "__main__":
    main()
