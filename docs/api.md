# API

All JSON errors use `{ "success": false, "error": { "code", "message", "fields"? } }`.
The browser-facing routes live in the `web` middleware group (session + CSRF);
the inference service is internal and has no authentication.

## Laravel

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/` | Analyzer UI (ES/EN, light/dark) |
| `POST` | `/analyze` | Multipart `image`; returns the CNN prediction (throttle `analysis`) |
| `POST` | `/recommend` | JSON prediction values → recommendation + RL insight (throttle `recommendation`) |
| `POST` | `/recommendation/feedback` | JSON `recommendation_id` + `reward` (throttle `feedback`, CSRF) |
| `GET` | `/locale/{locale}` | Switch language (`en`, `es`) |

### `POST /analyze`

```json
{
  "success": true,
  "prediction": {
    "class": "PNEUMONIA",
    "confidence": 0.93,
    "model": "modelo_neumonia_cnn",
    "probabilities": [
      { "class": "NORMAL", "probability": 0.07, "percentage": 7.0 },
      { "class": "PNEUMONIA", "probability": 0.93, "percentage": 93.0 }
    ]
  }
}
```

The frontend calls this first. It never waits for the recommendation, so a slow
LLM cannot delay the prediction.

### `POST /recommend`

Request:

```json
{ "class": "PNEUMONIA", "confidence": 0.93, "locale": "en" }
```

Response:

```json
{
  "success": true,
  "recommendation": {
    "id": "7f0e6a4a-6a9f-4f1c-9d0c-2f9e3e6b0a11",
    "text": "The experimental model detected a pattern consistent with pneumonia…",
    "source": "llm",
    "state": "PNEUMONIA:high",
    "action": { "id": "seek_medical_evaluation", "label": "Prompt medical evaluation" },
    "rl": {
      "q_value": 0.25,
      "is_exploration": false,
      "feedback_count": 0,
      "ranked_actions": [
        { "id": "seek_medical_evaluation", "label": "Prompt medical evaluation", "q_value": 0.25 },
        { "id": "warning_signs", "label": "Warning signs", "q_value": 0.1 }
      ]
    }
  }
}
```

`source` is `llm` or `fallback`. The text is validated to be at least
`ML_RECOMMENDATION_MIN_CHARS` (default 200) characters before Laravel accepts it.
`class`, `confidence` and `locale` are validated again by Laravel; the FastAPI
service validates them too.

### `POST /recommendation/feedback`

Request (a UUID from `/recommend` and a reward of `+1` or `-1`):

```json
{ "recommendation_id": "7f0e6a4a-6a9f-4f1c-9d0c-2f9e3e6b0a11", "reward": 1 }
```

Response:

```json
{
  "success": true,
  "feedback": { "old_q": 0.25, "new_q": 0.45, "feedback_count": 1 }
}
```

Only the first vote for an id is accepted. The browser disables both buttons
after a successful vote.

### Error codes

| HTTP | Code | Meaning |
| --- | --- | --- |
| `422` | `VALIDATION_ERROR` | Invalid image, class, confidence, locale or feedback fields |
| `429` | `RATE_LIMITED` | Per-client throttle exceeded |
| `419` | `SESSION_EXPIRED` | CSRF token expired |
| `503` | `MODEL_UNAVAILABLE` | CNN artifact missing or service unreachable |
| `504` | `MODEL_TIMEOUT` | Prediction or recommendation timed out |
| `502` | `INVALID_MODEL_RESPONSE` | Payload did not match the contract |
| `404` | `RECOMMENDATION_NOT_FOUND` | Feedback id unknown, expired or lost on restart |
| `409` | `FEEDBACK_ALREADY_RECORDED` | This recommendation was already rated |
| `422` | `FEEDBACK_REJECTED` | Service rejected the feedback payload |
| `500` | `UNEXPECTED` | Unhandled failure; internal details are not leaked |

## Inference service

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/health` | `status`, `model_loaded`, `model_name`, `llm_loaded`, `rl_loaded`, `rl_feedback_count` |
| `POST` | `/predict` | Multipart `image`; returns `class` and per-class probabilities |
| `POST` | `/recommend` | Same core payload as Laravel's recommendation request |
| `POST` | `/rl/feedback` | `{recommendation_id, reward}` → `old_q`, `new_q`, `feedback_count`, `state`, `action` |
| `GET` | `/rl/status` | `alpha`, `epsilon`, `feedback_count`, full `q_table` |

`/health` stays cheap: the LLM is lazy, so `llm_loaded` is `false` until the
first recommendation. `/rl/status` exposes the learning state for debugging and
the optional simulation script, not for the browser UI.

Additional service-side codes: `UNSUPPORTED_CLASS` (422 on `/recommend`),
`RECOMMENDATION_NOT_FOUND` (404), `FEEDBACK_ALREADY_RECORDED` (409) and
`INVALID_FEEDBACK` (422 on `/rl/feedback`). An LLM failure is not an error:
`/recommend` returns `200` with `source: "fallback"`.
