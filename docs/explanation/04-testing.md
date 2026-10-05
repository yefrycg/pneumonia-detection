# Tests

## Laravel (Pest)
- Feature: `AnalyzeImageTest` (17) + `AnalyzerPageTest` (11) → HTTP::fake, validaciones, errores, rate limit, sin persistencia
- Unit: `ImageInspectorTest` (6), `PredictionResultTest` (13)
Total: 48 tests, 142 assertions

Ejecutar:
```bash
php artisan test --compact
```

## Python (pytest)
- `test_preprocessing.py` – resize/normalize/formato
- `test_api.py` – health/predict + errores, con FakeRunner
Total: 21 tests

Ejecutar:
```bash
cd inference-service
.venv/Scripts/python -m pytest
```
