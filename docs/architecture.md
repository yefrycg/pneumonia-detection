# Architecture

## Overview

Two processes, two languages, one internal HTTP contract. Laravel never loads a
model; FastAPI owns both the Keras CNN and the optional recommendation/RL stack.

```text
Browser
  │  POST /analyze (multipart)
  ▼
Laravel (Blade + validation + rate limit)
  │  POST /predict (multipart, neutral filename)
  ▼
FastAPI ──▶ Keras CNN (150×150 grayscale, div_255, sigmoid)
  │
  └── JSON probabilities ──▶ Laravel validates DTO ──▶ Browser renders result

Browser
  │  POST /recommend (JSON class + confidence + locale)
  ▼
Laravel (validation + throttle) ──▶ FastAPI
                                      │
                                      ├─ contextual bandit: state = CLASS:band
                                      │   ε-greedy action from closed catalogue
                                      ├─ prompt (ES/EN) ──▶ Qwen3-0.6B
                                      └─ post-process or deterministic fallback
  ◀── recommendation + RL insight ────┘

Browser
  │  👍/👎 POST /recommendation/feedback (CSRF)
  ▼
Laravel ──▶ FastAPI ──▶ Q(s,a) ← Q(s,a) + α·(r − Q(s,a))
                         └─ atomic Q-table JSON update
```

The two calls are independent by design: `/analyze` must stay fast and must not
become unavailable when the LLM is slow, missing or broken. The browser shows the
prediction immediately and requests the recommendation separately.

## Layers

### Laravel

- `PredictionController` / `AnalyzeImageRequest` / `PredictionResult` — existing
  CNN flow, unchanged in shape.
- `RecommendationController` / `RecommendationRequest` — validates `class`,
  `confidence` and `locale` against configuration.
- `RecommendationFeedbackController` / `RecommendationFeedbackRequest` — accepts
  one UUID and a `+1`/`-1` reward.
- `RecommendationService` — orchestration; delegates transport to
  `HttpInferenceClient` and shape validation to `Recommendation`/`FeedbackResult`.
- `InferenceClient` contract — `classify()`, `recommend()`, `feedback()`.
- `config/inference.php` — paths, separate timeouts, rate limits, minimum text
  length and the existing model contract.

### FastAPI

- `app/main.py` — lifespan wiring, endpoints, JSON error mapping.
- `app/model.py` / `app/preprocessing.py` — unchanged CNN pipeline.
- `app/rl.py` — action catalogue, priors, ε-greedy bandit, Q-table persistence,
  in-memory recommendation-id store with TTL.
- `app/prompts.py` — bilingual prompts, safety rules, thinking/markdown cleanup,
  complete-sentence trimming and deterministic fallback copy.
- `app/llm.py` — lazy, thread-safe Qwen wrapper (`USE_TF=0` before importing
  Transformers, so it does not probe the Keras/TensorFlow stack).
- `app/recommendation.py` — ties RL → prompt → LLM (one retry) → fallback.

## Recommendation flow

1. Laravel validates the prediction values and calls `POST /recommend`.
2. FastAPI maps `confidence` to `low` (`< 0.70`), `medium` (`0.70–0.90`) or
   `high` (`>= 0.90`) and forms `state = CLASS:band`.
3. The bandit picks an action with ε-greedy: with probability ε (default 0.15)
   it explores uniformly, otherwise it picks the highest Q-value (ties broken
   randomly).
4. The prompt includes class, confidence percentage, band, action label/hint and
   the medical rules. Low confidence explicitly asks for uncertainty and
   professional confirmation.
5. Qwen generates up to `ML_LLM_MAX_NEW_TOKENS` tokens with thinking disabled.
   Post-processing strips `<think>` blocks and markdown, merges whitespace, cuts
   to the last complete sentence and enforces `ML_LLM_MIN_CHARS`.
6. A failed/too-short generation is retried once, then replaced with reviewed
   fallback copy. The response still succeeds with `source: "fallback"`.
7. The service stores `id → (state, action)` in memory (TTL 1 hour) and returns
   `recommendation_id` plus Q-values and the action ranking.

## RL update

```text
Q(s, a) ← Q(s, a) + α · (r − Q(s, a))

s = PNEUMONIA:high / PNEUMONIA:medium / PNEUMONIA:low
    NORMAL:high / NORMAL:medium / NORMAL:low
a ∈ closed catalogue for the state's class
r = +1 (👍) or −1 (👎)
α = 0.2, ε = 0.15 by default
```

This is a **single-step contextual bandit**: there is no next state, no episode
return and therefore no discount factor. `γ` is documented as unused rather than
silently omitted.

Priors give `seek_medical_evaluation` a small head start for `PNEUMONIA` and
`routine_prevention`/`symptom_watch` for `NORMAL`. Learning is persisted as
`{"version":1,"feedback_count":N,"q":{...}}` using a lock, a temporary file and
`os.replace`, so a crash cannot leave a half-written table. Unknown keys and
corrupt files are ignored on load.

## State and privacy boundaries

- Recommendation ids are random UUIDs; feedback is accepted once per id.
- The Q-table file holds only Q-values and a counter. No image, prompt, generated
  text, confidence, locale or clinical field is persisted.
- Laravel keeps the analyze upload in `php://temp` and streams it with a neutral
  filename. No database writes are involved in either feature.
- Error payloads expose stable codes and localized messages, never service paths
  or model internals.

## Failure modes

| Failure | Effect |
| --- | --- |
| CNN artifact missing | `/health` degraded; `/predict` returns `MODEL_UNAVAILABLE` |
| LLM missing/disabled/too slow | Recommendation returns reviewed fallback text |
| LLM output truncated | Retried once, then fallback |
| Training file unwritable | In-memory Q update rolled back; feedback returns `UNEXPECTED` |
| Recommendation id expired/restart | Feedback returns `RECOMMENDATION_NOT_FOUND` |
| Duplicate vote | `FEEDBACK_ALREADY_RECORDED`; UI keeps buttons disabled |
