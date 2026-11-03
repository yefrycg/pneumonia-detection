# FastAPI: Servicio de inferencia

## Archivos
- `app/settings.py` – configuración `ML_*`, incluidos `ML_LLM_*` y `ML_RL_*`
- `app/schemas.py` – modelos Pydantic (response con alias `class`)
- `app/preprocessing.py` – decodificación + resize 150×150 grayscale + /255
- `app/model.py` – carga perezosa Keras `.keras`
- `app/llm.py` – carga perezosa y thread-safe de Qwen3-0.6B (`USE_TF=0`)
- `app/prompts.py` – prompts ES/EN, limpieza de `<think>`/markdown, fallback
- `app/rl.py` – catálogo de acciones, bandit contextual, Q-table, ids con TTL
- `app/recommendation.py` – orquesta RL → prompt → LLM → fallback y feedback
- `app/main.py` – endpoints, lifespan y manejo de errores

## Endpoints
- `GET /health` → estado + `model_loaded`, `model_name`, `llm_loaded`,
  `rl_loaded`, `rl_feedback_count`
- `POST /predict` → recibe `image` (UploadFile), retorna predicción
- `POST /recommend` → `{class, confidence, locale}`; retorna texto, `source`,
  estado, acción, Q-value y ranking
- `POST /rl/feedback` → `{recommendation_id, reward}`; actualiza `Q(s,a)`
- `GET /rl/status` → α, ε, contador y Q-table completa

`/predict` no cambia. `lifespan` crea `ContextualBandit`, `RecommendationStore`,
`QwenRecommendationLLM` y `RecommendationService`; el LLM no se carga hasta la
primera petición de recomendación.

## Preprocesamiento
```python
img = Image.open(io.BytesIO(raw)).convert("L")
img = img.resize((150,150), Image.LANCZOS)
arr = np.array(img, dtype=np.float32)/255.0
tensor = arr.reshape((1,150,150,1))
```

## Modelo
- Espera `(None,150,150,1)`, salida `sigmoid` (1 escalar)
- `ModelRunner.predict` retorna `probs` con claves `NORMAL`, `PNEUMONIA` derivadas desde configuración

## Pipeline de recomendación
1. `state_key()` convierte clase + confianza en `PNEUMONIA:high`, etc.
2. `ContextualBandit.choose()` aplica ε-greedy (0.15 por defecto) sobre el
   catálogo de esa clase.
3. `build_prompts()` arma system + user en ES/EN con clase, confianza, banda,
   acción y reglas médicas. La banda `low` añade la regla de incertidumbre.
4. `QwenRecommendationLLM.generate()` usa el chat template con
   `enable_thinking=False`, sampling 0.7/0.8/20 y `max_new_tokens` configurable.
5. `postprocess_text()` elimina bloques `<think>`, markdown y espacios extra,
   corta en la última oración completa y exige `ML_LLM_MIN_CHARS` (200).
6. Si falla, se reintenta una vez; después se usa `fallback_text()`.
7. `RecommendationStore.create()` guarda `id → (state, action)` en memoria con TTL.

## RL
```text
Q(s,a) ← Q(s,a) + α·(r − Q(s,a))
r = +1 / −1, α = 0.2, ε = 0.15
```
Bandit de un paso: no hay siguiente estado, por lo que `γ` no participa. Los
priors favorecen ligeramente `seek_medical_evaluation` en PNEUMONIA. La Q-table
se guarda con lock, archivo temporal y `os.replace`; al cargar se ignoran claves
desconocidas y archivos corruptos. El feedback solo se acepta una vez por id.

## Errores
- `INVALID_IMAGE` (422/413)
- `MODEL_UNAVAILABLE` (503)
- `UNSUPPORTED_CLASS` (422 en `/recommend`)
- `RECOMMENDATION_NOT_FOUND` (404), `FEEDBACK_ALREADY_RECORDED` (409),
  `INVALID_FEEDBACK` (422)
- `UNEXPECTED` (500)

Un fallo del LLM **no** es un error HTTP: se responde `200` con
`source: "fallback"`.

## Ejemplo respuesta predicción
```json
{"success":true,"model":{"name":"modelo_neumonia_cnn"},"prediction":{"class":"PNEUMONIA","probabilities":{"NORMAL":0.07,"PNEUMONIA":0.93}}}
```

## Ejemplo respuesta recomendación
```json
{
  "success": true,
  "recommendation_id": "7f0e6a4a-6a9f-4f1c-9d0c-2f9e3e6b0a11",
  "text": "The experimental model detected a pattern consistent with pneumonia…",
  "source": "llm",
  "locale": "en",
  "class": "PNEUMONIA",
  "confidence": 0.93,
  "confidence_band": "high",
  "state": "PNEUMONIA:high",
  "action": {"id": "seek_medical_evaluation", "label": "Prompt medical evaluation", "hint": "…"},
  "rl": {
    "q_value": 0.25,
    "is_exploration": false,
    "feedback_count": 0,
    "ranked_actions": [{"id": "seek_medical_evaluation", "label": "…", "q_value": 0.25}]
  }
}
```
