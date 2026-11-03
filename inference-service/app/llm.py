"""
Lazy, thread-safe wrapper around the recommendation LLM.

Qwen3-0.6B runs on CPU inside this service. The checkpoint is pulled from the
Hugging Face cache on first use rather than at start-up, so ``/health``,
``/predict`` and the whole test suite keep working when the model has never
been downloaded. Every failure is contained here: the caller falls back to the
deterministic copy in :mod:`app.prompts`.

TensorFlow is explicitly disabled before ``transformers`` is imported. The
service already loads Keras 3 for the CNN, and letting transformers probe the
TensorFlow backend would pull a second, incompatible graph stack into the same
process.
"""

from __future__ import annotations

import logging
import os
import threading
from typing import Any

# These must be set before ``transformers`` (or any of its backends) is
# imported. They are harmless if the variables were already configured.
os.environ.setdefault("USE_TF", "0")
os.environ.setdefault("USE_TORCH", "1")
os.environ.setdefault("TRANSFORMERS_VERBOSITY", "error")

from app.settings import Settings

logger = logging.getLogger(__name__)


class LLMUnavailableError(RuntimeError):
    """Raised when the recommendation model is disabled or failed to load."""


class QwenRecommendationLLM:
    """Loads the causal LM once and serialises generation calls."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._tokenizer: Any = None
        self._model: Any = None
        self._lock = threading.RLock()
        self._failed = False

    @property
    def ready(self) -> bool:
        return self._model is not None

    @property
    def model_id(self) -> str:
        return self._settings.llm_model_id

    def load(self) -> bool:
        """
        Load tokenizer and model if needed.

        A missing dependency, an unavailable checkpoint or an out-of-memory
        error disables the LLM for the lifetime of the process, so a broken
        installation cannot make every recommendation request wait for a
        failing download.
        """
        with self._lock:
            if self._model is not None:
                return True

            if self._failed or not self._settings.llm_enabled:
                return False

            try:
                import torch
                from transformers import AutoModelForCausalLM, AutoTokenizer

                tokenizer = AutoTokenizer.from_pretrained(self._settings.llm_model_id)
                dtype = getattr(torch, self._settings.llm_dtype)
                model = AutoModelForCausalLM.from_pretrained(
                    self._settings.llm_model_id,
                    torch_dtype=dtype,
                )
                model.to(self._settings.llm_device)
                model.eval()

                self._tokenizer = tokenizer
                self._model = model

                logger.info("Loaded recommendation LLM '%s'.", self._settings.llm_model_id)

                return True
            except Exception as error:  # pragma: no cover - depends on local install
                self._failed = True
                logger.warning(
                    "Recommendation LLM unavailable (%s); deterministic fallback enabled.",
                    type(error).__name__,
                )

                return False

    def generate(self, system_prompt: str, user_prompt: str) -> str:
        """Generate one short recommendation, with Qwen3 thinking disabled."""
        with self._lock:
            if not self.load():
                raise LLMUnavailableError("The recommendation LLM is not available.")

            import torch

            messages = [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ]

            try:
                prompt = self._tokenizer.apply_chat_template(
                    messages,
                    tokenize=False,
                    add_generation_prompt=True,
                    enable_thinking=False,
                )
            except TypeError:
                # Older tokenizer implementations do not expose the flag; the
                # post-processor still removes any reasoning block they emit.
                prompt = self._tokenizer.apply_chat_template(
                    messages,
                    tokenize=False,
                    add_generation_prompt=True,
                )

            inputs = self._tokenizer(prompt, return_tensors="pt").to(self._model.device)

            with torch.inference_mode():
                output = self._model.generate(
                    **inputs,
                    max_new_tokens=self._settings.llm_max_new_tokens,
                    do_sample=True,
                    temperature=self._settings.llm_temperature,
                    top_p=self._settings.llm_top_p,
                    top_k=self._settings.llm_top_k,
                    pad_token_id=self._tokenizer.eos_token_id,
                )

            generated = output[0][inputs["input_ids"].shape[-1]:]

            return self._tokenizer.decode(generated, skip_special_tokens=True)
