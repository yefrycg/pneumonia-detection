"""
Owns the trained CNN and turns a tensor into class probabilities.

Keras is imported lazily so the web layer, its tests and the health check can
run without TensorFlow installed. The first ``load()`` pulls the heavy stack in;
until then ``ready`` stays ``False`` and predictions are refused rather than
crashing the process.
"""

from __future__ import annotations

import logging
import threading
from pathlib import Path
from typing import Any

import numpy as np

from app.settings import Settings

logger = logging.getLogger(__name__)


class ModelNotReadyError(RuntimeError):
    """Raised when a prediction is requested before a model is available."""


class ModelRunner:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._model: Any = None
        self._lock = threading.Lock()

    @property
    def ready(self) -> bool:
        return self._model is not None

    @property
    def name(self) -> str:
        return self._settings.model_name

    def load(self) -> bool:
        """
        Load the artifact if the file exists.

        Returns ``True`` when a model is ready. A missing or unreadable file is
        logged and reported, not raised: the service must keep answering
        ``/health`` so an operator can see what is wrong.
        """
        path = Path(self._settings.model_path)

        if not path.is_file():
            logger.warning("Model artifact not found at %s; predictions are disabled.", path)

            return False

        try:
            import keras
        except ImportError:
            logger.exception("Keras/TensorFlow is not installed; predictions are disabled.")

            return False

        try:
            # ``compile=False`` skips the loss/optimizer, which inference does
            # not need and which may reference custom objects we do not ship.
            self._model = keras.models.load_model(path, compile=False)
            logger.info("Loaded model '%s' from %s.", self._settings.model_name, path)
        except Exception:
            logger.exception("Failed to load the model artifact at %s.", path)
            self._model = None

            return False

        return True

    def predict(self, tensor: np.ndarray) -> dict[str, float]:
        """
        Run a forward pass and map the output onto the configured classes.

        The network ends in a single sigmoid unit trained to emit 1 for
        ``positive_class``, so the scalar is that class's probability and the
        other class is its complement.
        """
        if self._model is None:
            raise ModelNotReadyError("No model is loaded.")

        # TensorFlow sessions are not reliably safe under concurrent calls, and
        # the endpoint already runs this in a thread pool.
        with self._lock:
            raw = self._model.predict(tensor, verbose=0)

        scalar = float(np.asarray(raw).reshape(-1)[0])

        if not 0.0 <= scalar <= 1.0:
            raise ModelNotReadyError(f"The model returned an out-of-range probability: {scalar}.")

        positive = scalar
        negative = 1.0 - scalar

        probabilities = {
            self._settings.positive_class: round(positive, 6),
            self._settings.negative_class: round(negative, 6),
        }

        # Renormalize the tiny rounding drift so the contract "sums to 1" holds
        # for the strict validator on the Laravel side.
        total = sum(probabilities.values())

        if total > 0:
            probabilities = {label: value / total for label, value in probabilities.items()}

        return probabilities

    def predicted_class(self, probabilities: dict[str, float]) -> str:
        """Return the label with the highest probability."""
        return max(probabilities, key=probabilities.__getitem__)
