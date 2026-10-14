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

> The trained `.keras` artifact is a large binary and is **not committed** to the
> repository (see `.gitignore`). Obtain it separately and place it at
> `inference-service/models/modelo_neumonia_cnn.keras`, or point `ML_MODEL_PATH` at
> wherever it lives. The app starts without it, but every prediction returns
> `MODEL_UNAVAILABLE` until the file is present.

## Requirements

- PHP 8.4 with the `gd`, `curl` and `zip` extensions
- Composer
- Node.js 20+ and npm
- Python 3.12

### Verify the toolchain

Run these after cloning to confirm the versions and extensions the project expects.

**PHP 8.4 and its extensions**

```bash
php -v
php -m
```

Confirm the required extensions are listed:

```bash
# Git Bash
php -m | grep -iE '^(gd|curl|zip)$'

# Windows cmd
php -m | findstr /R /I "^gd$ ^curl$ ^zip$"

# PowerShell
php -m | Select-String -Pattern '^(gd|curl|zip)$'
```

**Composer, Node.js and Python**

```bash
composer --version   # any recent 2.x
node --version       # v20 or newer
npm --version
python --version     # Python 3.12.x
```

> On Windows use `py -3.12 --version` if `python` maps to a different interpreter,
> and make sure `python`/`py` point at 3.12 before creating the virtualenv.

## Setup

### 1. Laravel application

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
npm run build          # or: npm run dev
```

The default `.env` uses SQLite with `SESSION_DRIVER=database`, so two extra steps
are required before the app will serve requests without errors.

**Create the SQLite database file.** It is git-ignored and Laravel will not create
it for you on an existing checkout:

```bash
# Git Bash
touch database/database.sqlite

# Windows cmd
type nul > database\database.sqlite

# PowerShell
New-Item -ItemType File -Path database/database.sqlite
```

**Run the migrations.** This creates the `sessions`, `cache` and `jobs` tables the
default drivers expect:

```bash
php artisan migrate
```

Skipping either step leads to errors such as `Database file at path ... does not
exist` or `SQLSTATE[HY000]: General error: 1 no such table: sessions` (see
[Troubleshooting](#troubleshooting)).

### 2. Inference service

```bash
cd inference-service
python -m venv .venv

# Windows (cmd / PowerShell)
.venv\Scripts\activate

# Windows (Git Bash)
source .venv/Scripts/activate

# macOS / Linux
source .venv/bin/activate

pip install --upgrade pip
pip install -r requirements.txt
cp .env.example .env
```

Place the trained artifact at `inference-service/models/modelo_neumonia_cnn.keras`
(the file is git-ignored and normally must be obtained separately). If your
artifact lives elsewhere, point `ML_MODEL_PATH` at it.

> **Windows only — install TensorFlow with Long Paths enabled.** `tensorflow-cpu`
> brings a deep dependency tree, and `pip install -r requirements.txt` can fail
> with `The system cannot find the path specified`, `[Errno 2]` or a `MAX_PATH`
> / `260`-character message while unpacking wheels. Enable long paths *before*
> recreating the virtualenv:
>
> 1. Enable Long Paths for the machine (PowerShell **as Administrator**):
>
>    ```powershell
>    New-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\FileSystem' `
>      -Name LongPathsEnabled -Value 1 -PropertyType DWord -Force
>    ```
>
>    Alternatively set *Computer Configuration → Administrative Templates →
>    System → Filesystem → Enable Win32 long paths* to **Enabled** in `gpedit.msc`,
>    then reboot if prompted.
> 2. Tell Git to handle long paths (Git Bash, from the repo root):
>
>    ```bash
>    git config --system core.longpaths true
>    ```
> 3. Delete and recreate the virtualenv so the paths are rebuilt cleanly, then
>    reinstall:
>
>    ```bash
>    # Git Bash
>    rm -rf .venv
>    python -m venv .venv
>    source .venv/Scripts/activate
>    pip install --upgrade pip
>    pip install -r requirements.txt
>    ```
>
>    If the failure persists, create the virtualenv closer to the drive root (e.g.
>    `C:\venvs\pneumonia`) and install from there — shorter base paths leave more
>    room under the `MAX_PATH` limit.

### 3. Run both

Start the inference service first (defaults to port `8000`). With the virtualenv
activated, prefer invoking uvicorn through the interpreter module:

```bash
cd inference-service
python -m uvicorn app.main:app --host 127.0.0.1 --port 8000
```

Then the Laravel app on a different port. On Windows start it with
`--no-reload` (see the note below) and make sure the service URL points at the
inference service — add it to `.env`:

```dotenv
ML_SERVICE_URL=http://127.0.0.1:8000
```

```bash
php artisan serve --no-reload --port=8080
```

Open <http://127.0.0.1:8080>.

> Using Laravel Herd? Set `APP_URL=http://127.0.0.1:8080` in `.env` so generated
> links match the port you serve on.

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

## Verifying the inference service

Check the service directly — `/health` reports liveness **and** whether the model
artifact loaded:

```bash
curl http://127.0.0.1:8000/health
# {"status":"ok","model_loaded":true,"model_name":"modelo_neumonia_cnn"}
```

- `"model_loaded": true` means the `.keras` artifact was found and loaded by Keras;
  predictions will work.
- `"status":"degraded","model_loaded":false` means the process is up but the
  artifact is missing or failed to load. Verify the file exists at
  `inference-service/models/modelo_neumonia_cnn.keras` (or the path in
  `ML_MODEL_PATH`) and reinstall the TensorFlow/Keras dependencies. Check the
  uvicorn console for a `Model artifact not found at ...` warning.

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

## Troubleshooting

### `Database file at path [...]/database/database.sqlite does not exist`

The SQLite database file was never created (it is git-ignored). Create it and
migrate:

```bash
touch database/database.sqlite   # Git Bash
php artisan migrate
```

### `SQLSTATE[HY000]: General error: 1 no such table: sessions`

The default session driver is `database` but the migrations have not run. Execute
`php artisan migrate`. The same applies to `no such table: cache` and
`no such table: jobs`.

### TensorFlow install fails with a long-path / `MAX_PATH` error on Windows

`pip install -r requirements.txt` may die while unpacking TensorFlow wheels in a
deeply nested `.venv`. Enable Windows Long Paths and Git long paths, then
**delete and recreate** `.venv` before reinstalling. Full steps are in
[Setup → Inference service](#2-inference-service); if it still fails, create the
virtualenv at a short path such as `C:\venvs\pneumonia`.

### `PHP Request Startup: File upload error - unable to create a temporary file`

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

### Predictions return `MODEL_UNAVAILABLE` / `/health` shows `model_loaded: false`

The `.keras` artifact is not in the repository and must be provided separately.
Place it at `inference-service/models/modelo_neumonia_cnn.keras` or set
`ML_MODEL_PATH`, then restart the service and re-check `/health`.

### `[Errno 10048]` / `address already in use` (or `EADDRINUSE`)

Another process already owns port `8000` (service) or `8080` (Laravel). Stop it or
start on a free port and keep `ML_SERVICE_URL` and the served port in sync, e.g.
`python -m uvicorn app.main:app --port 8001` with
`ML_SERVICE_URL=http://127.0.0.1:8001`.

### `python` not found, wrong Python, or venv activation issues in Git Bash

Use `python -m venv .venv` with an explicit 3.12 interpreter (`py -3.12`), then
activate with `source .venv/Scripts/activate` in Git Bash (not
`.venv/bin/activate`, which is for macOS/Linux). You can always call the
interpreter directly: `.venv/Scripts/python.exe -m uvicorn app.main:app`.

### `Model artifact not found at ...` in the uvicorn log

Same root cause as `MODEL_UNAVAILABLE`: the artifact path is wrong or the file is
absent. `ML_MODEL_PATH` is resolved relative to the directory uvicorn is started
from, so run it from `inference-service/` or use an absolute path.

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
