"""
Prompts, safety rules and deterministic fallback copy for the recommendation service.

The LLM only writes the explanation. It never sees the radiograph and its
output is always treated as untrusted text: it is stripped of reasoning tags,
normalised, cut to a complete sentence and checked for the minimum length
before it reaches Laravel.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

from app.rl import Action

Locale = str
Band = str

THINK_CLOSE_TAG = "<" + "/think>"
THINK_BLOCK = re.compile(r"<think\b[^>]*>.*?" + re.escape(THINK_CLOSE_TAG), re.IGNORECASE | re.DOTALL)
THINK_OPEN = re.compile(r"<think\b[^>]*>", re.IGNORECASE)
THINK_CLOSE = re.compile(re.escape(THINK_CLOSE_TAG), re.IGNORECASE)
CODE_FENCE = re.compile(r"```[a-zA-Z0-9_-]*")
LINE_PREFIX = re.compile(r"^[ \t]*(?:#{1,6}[ \t]*|[-*•][ \t]+|\d{1,2}[.)][ \t]+)", re.MULTILINE)
SENTENCE_END = re.compile(r"[.!?…](?:[\"'”’)\]]+)?(?=\s|$)")

BAND_WORDS = {
    "es": {"low": "baja", "medium": "media", "high": "alta"},
    "en": {"low": "low", "medium": "medium", "high": "high"},
}

SYSTEM_PROMPTS = {
    "es": (
        "Eres un asistente de información de salud respiratoria. Respondes con claridad, "
        "prudencia y empatía. No emites diagnósticos, no recomiendas medicamentos ni dosis "
        "y no sustituyes la valoración de un profesional sanitario. Escribes en español, "
        "en 3 o 4 oraciones completas, sin listas, sin viñetas, sin títulos y sin markdown. "
        "Nunca afirmas que la persona tiene o no tiene una enfermedad: describes una "
        "orientación de apoyo a partir del resultado de un modelo experimental. "
        "Habla directamente a la persona, sin mencionar términos técnicos como 'clase predicha', "
        "'enfoque', 'agente', 'confianza' o 'modelo'."
    ),
    "en": (
        "You are a respiratory health information assistant. You answer clearly, "
        "carefully and with empathy. You do not diagnose, prescribe medication or doses, "
        "and you do not replace a healthcare professional's assessment. You write in "
        "English, in 3 or 4 complete sentences, without lists, bullets, headings or "
        "markdown. You never state that the person does or does not have a disease: you "
        "describe supportive guidance based on an experimental model's result. "
        "Speak directly to the person, without mentioning technical terms like 'predicted class', "
        "'emphasis', 'agent', 'confidence', or 'model'."
    ),
}

CLASS_RULES = {
    "es": {
        "PNEUMONIA": (
            "El resultado sugiere un patrón compatible con neumonía: recomienda buscar "
            "valoración médica pronta y menciona que la dificultad para respirar, la fiebre "
            "persistente o el dolor en el pecho son signos de alarma que requieren atención urgente."
        ),
        "NORMAL": (
            "El resultado no muestra signos compatibles con neumonía: indica que, aun así, "
            "si los síntomas persisten o empeoran, se debe consultar a un profesional de la salud."
        ),
    },
    "en": {
        "PNEUMONIA": (
            "The result suggests a pattern consistent with pneumonia: recommend seeking "
            "prompt medical evaluation and mention that difficulty breathing, persistent fever "
            "or chest pain are warning signs that require urgent care."
        ),
        "NORMAL": (
            "The result does not show signs consistent with pneumonia: say that, even so, "
            "if symptoms persist or worsen, a healthcare professional should be consulted."
        ),
    },
}

UNCERTAINTY_RULES = {
    "es": (
        "La confianza del modelo en esta imagen es baja: menciona explícitamente la incertidumbre "
        "y la necesidad de confirmación por un profesional."
    ),
    "en": (
        "The model's confidence in this image is low: explicitly mention the uncertainty and the "
        "need for professional confirmation."
    ),
}

FALLBACK_TEXTS = {
    "PNEUMONIA": {
        "es": {
            "opening": (
                "El modelo experimental detectó un patrón compatible con neumonía, por lo "
                "que conviene buscar valoración médica pronta. Si presentas dificultad para "
                "respirar, fiebre persistente o dolor en el pecho, acude a atención urgente."
            ),
            "standard": (
                "Mientras esperas, descansa, hidrátate y sigue las indicaciones de un "
                "profesional de la salud."
            ),
            "low": (
                "La confianza del modelo en esta imagen es baja, así que la confirmación "
                "por un profesional es especialmente importante."
            ),
            "closing": (
                "Esta orientación es un apoyo informativo y no sustituye una evaluación "
                "clínica."
            ),
        },
        "en": {
            "opening": (
                "The experimental model detected a pattern consistent with pneumonia, so "
                "prompt medical evaluation is advisable. If you have difficulty breathing, "
                "a persistent fever or chest pain, seek urgent care."
            ),
            "standard": (
                "While you wait, rest, stay hydrated and follow the instructions of a "
                "healthcare professional."
            ),
            "low": (
                "The model's confidence in this image is low, so professional confirmation "
                "is especially important."
            ),
            "closing": (
                "This guidance is supportive information and does not replace a clinical "
                "assessment."
            ),
        },
    },
    "NORMAL": {
        "es": {
            "opening": (
                "El modelo experimental no detectó signos compatibles con neumonía en esta "
                "imagen. Aun así, si los síntomas respiratorios continúan o empeoran, "
                "consulta a un profesional de la salud."
            ),
            "standard": (
                "Mantén medidas de prevención como lavarte las manos y ventilar los "
                "espacios cerrados."
            ),
            "low": (
                "La confianza del modelo en esta imagen es baja, así que la confirmación "
                "por un profesional es especialmente importante."
            ),
            "closing": (
                "Esta orientación es un apoyo informativo y no sustituye una evaluación "
                "clínica."
            ),
        },
        "en": {
            "opening": (
                "The experimental model did not detect signs consistent with pneumonia in "
                "this image. Even so, if respiratory symptoms continue or worsen, consult a "
                "healthcare professional."
            ),
            "standard": (
                "Keep up prevention measures such as washing your hands and ventilating "
                "enclosed spaces."
            ),
            "low": (
                "The model's confidence in this image is low, so professional confirmation "
                "is especially important."
            ),
            "closing": (
                "This guidance is supportive information and does not replace a clinical "
                "assessment."
            ),
        },
    },
}


@dataclass(frozen=True)
class PromptPair:
    system: str
    user: str


def _locale(locale: Locale) -> str:
    return locale if locale in ("es", "en") else "en"


def build_prompts(
    predicted_class: str,
    confidence: float,
    band: Band,
    action: Action,
    locale: Locale,
    min_chars: int = 200,
    max_chars: int = 600,
) -> PromptPair:
    """Build the system and user prompts for one recommendation request."""
    language = _locale(locale)
    percent = round(max(0.0, min(1.0, confidence)) * 100)
    band_word = BAND_WORDS[language].get(band, band)
    class_label = predicted_class.strip().upper()
    class_rule = CLASS_RULES[language].get(class_label, CLASS_RULES[language]["NORMAL"])

    if language == "es":
        user = (
            "Un modelo experimental analizó una radiografía de tórax. "
            "El resultado sugiere "
            f"{'neumonía' if class_label == 'PNEUMONIA' else 'que no hay signos de neumonía'} "
            f"(confianza {percent} %, banda {band_word}). "
            f"Enfoque para la recomendación: {action.label('es')}. "
            f"Pista: {action.hint('es')}\n\n"
            f"{class_rule}\n"
            f"{UNCERTAINTY_RULES['es'] if band == 'low' else ''}\n"
            f"Redacta una recomendación de entre {min_chars} y {max_chars} caracteres, "
            "en 3 o 4 oraciones completas, sin listas, viñetas, títulos ni markdown. "
            "No des diagnósticos, dosis ni medicamentos. No prometas resultados. "
            "Habla directamente a la persona, sin usar términos como 'clase predicha', "
            "'enfoque', 'agente', 'confianza' ni 'modelo'. "
            "Termina todas las oraciones y no dejes frases cortadas."
        )
    else:
        user = (
            "An experimental model analyzed a chest X-ray. "
            f"The result suggests {'pneumonia' if class_label == 'PNEUMONIA' else 'no signs of pneumonia'} "
            f"(confidence {percent} %, {band_word} band). "
            f"Emphasis for this recommendation: {action.label('en')}. "
            f"Hint: {action.hint('en')}\n\n"
            f"{class_rule}\n"
            f"{UNCERTAINTY_RULES['en'] if band == 'low' else ''}\n"
            f"Write a recommendation between {min_chars} and {max_chars} characters, "
            "in 3 or 4 complete sentences, without lists, bullets, headings or markdown. "
            "Do not diagnose, prescribe doses or medications, or promise outcomes. "
            "Speak directly to the person, without using terms like 'predicted class', "
            "'emphasis', 'agent', 'confidence', or 'model'. "
            "Finish every sentence and do not leave a sentence cut off."
        )

    return PromptPair(system=SYSTEM_PROMPTS[language], user=re.sub(r"\n{2,}", "\n\n", user.strip()))


def strip_thinking(text: str) -> str:
    """Remove Qwen3 reasoning blocks, including stray opening/closing tags."""
    without_blocks = THINK_BLOCK.sub(" ", text)

    return THINK_CLOSE.sub(" ", THINK_OPEN.sub(" ", without_blocks))


def normalize_whitespace(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip()


def strip_markdown(text: str) -> str:
    """Turn the most common markdown fragments into plain prose."""
    text = CODE_FENCE.sub(" ", text)
    text = LINE_PREFIX.sub("", text)

    for marker in ("**", "__", "`"):
        text = text.replace(marker, "")

    return text


def trim_to_complete_sentence(text: str) -> str | None:
    """
    Cut a truncated generation back to its last complete sentence.

    ``None`` means no sentence boundary was found, so the caller should retry
    or use the deterministic fallback instead of showing half a sentence.
    """
    matches = list(SENTENCE_END.finditer(text))

    if not matches:
        return None

    return text[: matches[-1].end()].strip()


def postprocess_text(raw: str, min_chars: int = 200) -> str | None:
    """
    Clean a raw generation and return it only when it is safe to display.

    The result is plain prose, free of reasoning tags and markdown, ending on a
    complete sentence and at least ``min_chars`` characters long. Any failure
    returns ``None`` so the caller can retry once and then fall back.
    """
    if not isinstance(raw, str) or not raw.strip():
        return None

    cleaned = strip_markdown(strip_thinking(raw))
    cleaned = normalize_whitespace(cleaned)
    cleaned = trim_to_complete_sentence(cleaned)

    if cleaned is None or len(cleaned) < min_chars:
        return None

    return cleaned


def fallback_text(predicted_class: str, band: Band, locale: Locale) -> str:
    """Deterministic, medically conservative copy used when the LLM fails."""
    language = _locale(locale)
    class_label = predicted_class.strip().upper()
    entry = FALLBACK_TEXTS.get(class_label, FALLBACK_TEXTS["NORMAL"])
    middle = entry[language]["low"] if band == "low" else entry[language]["standard"]

    return " ".join([entry[language]["opening"], middle, entry[language]["closing"]])