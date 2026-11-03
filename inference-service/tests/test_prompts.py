"""Prompt construction, post-processing and deterministic fallback copy."""

from __future__ import annotations

import re

import pytest

from app.prompts import (
    build_prompts,
    fallback_text,
    postprocess_text,
    strip_markdown,
    strip_thinking,
    trim_to_complete_sentence,
)
from app.rl import CATALOG


def action_for(predicted_class: str, action_id: str):
    return next(action for action in CATALOG[predicted_class] if action.id == action_id)


def test_prompts_include_class_confidence_band_and_action_hint_in_spanish() -> None:
    prompts = build_prompts(
        "PNEUMONIA",
        0.93,
        "high",
        action_for("PNEUMONIA", "seek_medical_evaluation"),
        "es",
    )

    # The new prompt uses natural language instead of raw class names
    assert "neumonía" in prompts.user.lower()
    assert "93 %" in prompts.user
    assert "banda alta" in prompts.user
    assert "Evaluación médica oportuna" in prompts.user
    assert "evaluación médica temprana" in prompts.user
    assert "3 o 4 oraciones completas" in prompts.user
    assert "sin listas" in prompts.user
    assert "No emites diagnósticos" in prompts.system
    # Technical terms should only appear in the "do not use" instruction, not in the content
    content_part = prompts.user.split("Redacta una recomendación")[0]
    assert "clase predicha" not in content_part
    assert "enfoque" not in content_part
    assert "agente" not in content_part


def test_prompts_are_localised_in_english() -> None:
    prompts = build_prompts(
        "NORMAL",
        0.61,
        "low",
        action_for("NORMAL", "symptom_watch"),
        "en",
    )

    # The new prompt uses natural language instead of raw class names
    assert "no signs of pneumonia" in prompts.user.lower()
    assert "61 %" in prompts.user
    assert "low band" in prompts.user
    assert "Symptom watch" in prompts.user
    assert "uncertainty" in prompts.user
    assert "complete sentences" in prompts.user
    assert "do not diagnose" in prompts.system.lower()
    # Technical terms should only appear in the "do not use" instruction, not in the content
    content_part = prompts.user.split("Write a recommendation")[0]
    assert "predicted class" not in content_part
    assert "emphasis" not in content_part
    assert "agent" not in content_part


def test_thinking_blocks_and_stray_tags_are_removed() -> None:
    raw = "<think>Internal reasoning that must never reach the user.</think>Text visible."

    assert strip_thinking(raw).strip() == "Text visible."

    assert strip_thinking("<think>Text visible.</think>").strip() == "Text visible."

    assert strip_thinking("Text visible.</think>").strip() == "Text visible."


def test_markdown_bullets_and_code_fences_are_removed() -> None:
    raw = "```text\n## Title\n- First idea.\n- Second idea.\n**Important**: consult.\n```"

    cleaned = strip_markdown(raw)

    assert "```" not in cleaned
    assert "##" not in cleaned
    assert "**" not in cleaned
    assert "First idea." in cleaned


def test_generation_is_cut_back_to_the_last_complete_sentence() -> None:
    raw = ("The model does not detect compatible signs. " * 14) + "This sentence is cut off"
    result = postprocess_text(raw, min_chars=200)

    assert result is not None
    assert result.endswith("signs.")
    assert "cut off" not in result


def test_generation_without_a_sentence_boundary_is_rejected() -> None:
    raw = "word " * 100

    assert trim_to_complete_sentence(raw.strip()) is None
    assert postprocess_text(raw, min_chars=200) is None


def test_short_generation_is_rejected() -> None:
    assert postprocess_text("Too short.", min_chars=200) is None


def test_postprocessed_text_always_meets_the_minimum_length() -> None:
    raw = "<think>hidden</think> " + ("A complete and useful sentence. " * 12)

    result = postprocess_text(raw, min_chars=200)

    assert result is not None
    assert len(result) >= 200
    assert result.endswith(".")
    assert "hidden" not in result


@pytest.mark.parametrize("predicted_class", ["PNEUMONIA", "NORMAL"])
@pytest.mark.parametrize("locale", ["es", "en"])
@pytest.mark.parametrize("band", ["low", "medium", "high"])
def test_fallback_is_long_enough_and_has_four_sentences(
    predicted_class: str, locale: str, band: str
) -> None:
    text = fallback_text(predicted_class, band, locale)

    assert len(text) >= 200
    assert len(re.findall(r"[.!?](?:\s|$)", text)) == 4


def test_fallback_mentions_warning_signs_for_pneumonia() -> None:
    text = fallback_text("PNEUMONIA", "high", "es")

    assert "dificultad para respirar" in text
    assert "atención urgente" in text
    assert "no sustituye una evaluación clínica" in text


def test_fallback_mentions_uncertainty_when_confidence_is_low() -> None:
    text = fallback_text("NORMAL", "low", "en")

    assert "confidence" in text
    assert "professional confirmation" in text