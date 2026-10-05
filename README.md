# Pneumonia Detection

Experimental web application that classifies chest X-rays as **NORMAL** or **PNEUMONIA**.
The Laravel/Blade front end handles the upload and the UI; a standalone Python
service owns the trained convolutional neural network and is called over HTTP.

> **Not a medical device.** The predictions are for demonstration only and must not
> be used for diagnosis. Uploaded radiographs are never persisted.

## Architecture

Two processes, two languages, one HTTP contract:

```
Browser ──multipart──▶ Laravel (Blade UI + validation) ──multipart──▶ FastAPI ──▶ Keras CNN
   ▲                          │                                          │
   └──────── JSON ◀───────────┴──────────────── JSON ◀───────────────────┘
```

- **Laravel** validates the upload, streams it to the service under a neutral
  filename, validates the returned probabilities, and renders the result.
- **FastAPI** decodes the image, applies the model's preprocessing contract, runs
  the CNN, and returns one probability per configured class.
- The model artifact is never loaded by PHP.

### Model contract

| Property | Value |
| --- | --- |
| Artifact | `inference-service/models/modelo_neumonia_cnn.keras` |
| Input | grayscale, `150 × 150`, single channel |
| Normalization | pixels scaled to `[0, 1]` (`div_255`) |
| Output | one sigmoid scalar = `P(PNEUMONIA)`; `P(NORMAL) = 1 - scalar` |
| Positive class | `PNEUMONIA` (label `1`) |
| Negative class | `NORMAL` (label `0`) |

## Requirements

- PHP 8.4 with the `gd`, `curl` and `zip` extensions
- Composer and Node.js 20+
- Python 3.12

## Setup

### 1. Laravel application

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
npm run build          # or: npm run dev
```

### 2. Inference service

```bash
cd inference-service
python -m venv .venv

# Windows
.venv\Scripts\activate
# macOS / Linux
source .venv/bin/activate

pip install -r requirements.txt
cp .env.example .env
```

Place the trained artifact at `inference-service/models/modelo_neumonia_cnn.keras`
(the file is git-ignored). If your artifact lives elsewhere, point
`ML_MODEL_PATH` at it.

### 3. Run both

Start the inference service first (defaults to port `8000`):

```bash
cd inference-service
.venv/Scripts/uvicorn app.main:app --host 127.0.0.1 --port 8000
```

Then the Laravel app on a different port, with the service URL configured:

```bash
ML_SERVICE_URL=http://127.0.0.1:8000 php artisan serve --port=8080
```

Open <http://127.0.0.1:8080>.

You can check the service directly:

```bash
curl http://127.0.0.1:8000/health
# {"status":"ok","model_loaded":true,"model_name":"modelo_neumonia_cnn"}
```

### Windows / Herd: "unable to create a temporary file"

`php artisan serve` runs the PHP built-in server in **reload** mode, which
replaces the server process environment with a whitelist that omits `TMP` and
`TEMP`. On Windows with an empty `upload_tmp_dir`, PHP then falls back to
`C:\Windows\Temp`, and every upload fails with:

```
PHP Request Startup: File upload error - unable to create a temporary file
```

Pick one of these fixes:

- Start the server with `php artisan serve --no-reload` (the built-in server then
  inherits the full environment). This also applies to `composer run dev`, whose
  `server` process uses plain `artisan serve`.
- Set `upload_tmp_dir` to a writable directory in the Herd `php.ini`.
- Serve the app through a Herd site (php-fpm via nginx) instead of `artisan serve`.

## Configuration

Laravel reads these from `.env` via `config/inference.php`:

| Variable | Default | Purpose |
| --- | --- | --- |
| `ML_SERVICE_URL` | `http://127.0.0.1:8000` | Base URL of the inference service |
| `ML_SERVICE_PATH` | `/predict` | Prediction endpoint path |
| `ML_SERVICE_CONNECT_TIMEOUT` | `5` | Connection timeout (seconds) |
| `ML_SERVICE_TIMEOUT` | `30` | Response timeout (seconds) |
| `ML_MAX_UPLOAD_KB` | `10240` | Maximum upload size |
| `ML_MAX_DIMENSION` | `4096` | Maximum image width/height |
| `ML_RATE_LIMIT` | `20` | Analyses allowed per client per window |
| `ML_MODEL_NAME` | `modelo_neumonia_cnn` | Name reported back in the response |
| `ML_PROBABILITY_TOLERANCE` | `0.01` | Accepted drift when probabilities must sum to 1 |

The service reads its own `ML_*` variables (see `inference-service/.env.example`),
including `ML_MODEL_PATH`, the `ML_INPUT_*` / `ML_NORMALIZATION` preprocessing
contract, `ML_POSITIVE_CLASS` / `ML_NEGATIVE_CLASS`, and `ML_HOST` / `ML_PORT`.

## HTTP API

### Laravel

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/` | Analyzer page (bilingual ES/EN, light/dark) |
| `POST` | `/analyze` | Upload `image` and receive a prediction (throttled) |
| `GET` | `/locale/{locale}` | Switch the UI language (`en`, `es`) |

Successful `/analyze` response:

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

Error responses use `{ "success": false, "error": { "code", "message", "fields"? } }`
with codes `VALIDATION_ERROR` (422), `INVALID_MODEL_RESPONSE` (502),
`MODEL_UNAVAILABLE` (503), `MODEL_TIMEOUT` (504), `RATE_LIMITED` (429),
`SESSION_EXPIRED` (419) and `UNEXPECTED` (500).

### Inference service

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/health` | Liveness and whether the model is loaded |
| `POST` | `/predict` | Multipart `image` field; returns `class` and `probabilities` |

The service reports `MODEL_UNAVAILABLE` (503) when the artifact is missing or
fails to load, and `INVALID_IMAGE` (413/422) when the upload cannot be decoded.

## Testing

```bash
# Laravel (Pest)
php artisan test --compact

# Inference service (pytest)
cd inference-service
.venv/Scripts/python -m pytest
```

## Replacing the model

1. Drop the new `.keras` artifact into `inference-service/models/`.
2. Update `ML_MODEL_PATH` (and `ML_MODEL_NAME`).
3. Keep the preprocessing contract in sync: `ML_INPUT_WIDTH`, `ML_INPUT_HEIGHT`,
   `ML_GRAYSCALE`, `ML_NORMALIZATION`, `ML_POSITIVE_CLASS`, `ML_NEGATIVE_CLASS`.
4. Mirror those changes in Laravel's `config/inference.php` (`model` and
   `classes` keys). A unit test asserts the class enum and this config stay in
   sync, and the response DTO rejects probabilities that do not form a
   distribution.

## Privacy

Uploads are read into memory (`php://temp`) and streamed to the service; nothing
is written to disk, stored in the database, or logged with clinical data. The
service receives a neutral filename rather than the one supplied by the client.
