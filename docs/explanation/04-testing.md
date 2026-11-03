# Tests

## Laravel (Pest)

Total: **90 tests, 277 assertions**.

| Archivo | Tests | Cobertura |
| --- | --- | --- |
| `tests/Feature/AnalyzeImageTest.php` | 17 | Predicción, nombre neutro, validaciones, timeout/errores, throttle, sin disco |
| `tests/Feature/AnalyzerPageTest.php` | 13 | Página, disclaimer, CSRF, formatos, locale, tarjeta de recomendación y elementos que busca el JS |
| `tests/Feature/RecommendationTest.php` | 22 | `/recommend` éxito/fallback/validación/timeout/503/error de contrato/throttle; feedback éxito, 404, 409, 422, validación y throttle |
| `tests/Feature/ExampleTest.php` | 1 | Aplicación responde |
| `tests/Unit/Data/PredictionResultTest.php` | 12 | Esquema y distribución de probabilidades |
| `tests/Unit/Data/RecommendationTest.php` | 18 | DTO de recomendación/feedback, UUID, texto mínimo, `source`, acción, bloque RL y ranking |
| `tests/Unit/Support/ImageInspectorTest.php` | 6 | Imágenes válidas, corruptas y límites de dimensión |
| `tests/Unit/ExampleTest.php` | 1 | Sanity check |

Los tests de recomendación usan `Http::fake()` / `Http::fakeSequence()` y no
necesitan el servicio Python levantado. También comprueban que los mensajes de
error no filtran rutas internas y que el rate limit devuelve `429`.

Ejecutar:

```bash
php artisan test --compact
```

## Python (pytest)

Total: **68 tests**.

| Archivo | Tests | Cobertura |
| --- | --- | --- |
| `tests/test_api.py` | 11 | `/health`, `/predict`, errores y validación de imagen con `FakeRunner` |
| `tests/test_preprocessing.py` | 10 | Resize, normalización, formatos y límites |
| `tests/test_prompts.py` | 22 | Prompts ES/EN, reglas por clase, banda baja, limpieza de `<think>`/markdown, oraciones completas, longitud mínima y fallback |
| `tests/test_rl.py` | 13 | Bandas, estados, catálogo, priors, ε-greedy (0 y 1), actualización, persistencia atómica, archivo corrupto, TTL y feedback único |
| `tests/test_recommendation_api.py` | 12 | `/recommend`, `/rl/feedback` (404/409/422), `/rl/status`, `/health`, `FakeLLM` y fallback |

Ningún test descarga Qwen ni usa la Q-table real: se inyecta un `FakeLLM` y un
path temporal. Esto mantiene la suite rápida y determinista.

Ejecutar:

```bash
cd inference-service
.venv/Scripts/python -m pytest
```
