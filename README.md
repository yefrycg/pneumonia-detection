# Pneumonia Detection

Experimental web application that classifies chest X-rays as **NORMAL** or **PNEUMONIA**.
The Laravel/Blade front end handles the upload and the UI; a standalone Python
service owns the trained convolutional neural network and is called over HTTP.
After a prediction, the same service writes a short, localized recommendation
with **Qwen3-0.6B** and a **contextual-bandit agent** adjusts how that
recommendation is framed based on the user's 👍/👎 feedback.

> **Not a medical device.** The predictions are for demonstration only and must not
> be used for diagnosis. Uploaded radiographs are never persisted.

## Architecture

Two processes, two languages, one HTTP contract:

```
Browser ──multipart──▶ Laravel (Blade UI + validation) ──multipart──▶ FastAPI ──▶ Keras CNN
   ▲                          │                                          │
   └──────── JSON ◀───────────┴──────────────── JSON ◀───────────────────┘

Browser ──JSON──▶ Laravel ──JSON──▶ FastAPI ──▶ bandit (ε-greedy) ──▶ Qwen3-0.6B
   ▲                 │                 │
   └──── JSON ◀──────┴───── JSON ◀─────┘

Browser ──👍/👎──▶ Laravel ──JSON──▶ FastAPI ──▶ Q(s,a) update + Q-table file
```

- **Laravel** validates the upload, streams it to the service under a neutral
  filename, validates the returned probabilities, and renders the result.
- **FastAPI** decodes the image, applies the model's preprocessing contract, runs
  the CNN, and returns one probability per configured class.
- The recommendation is a **separate, slower request**. `/analyze` stays fast and
  the card fills in when `/recommend` answers; an LLM failure only switches the
  text to a reviewed deterministic fallback.
- Feedback updates a small Q-table. The agent chooses the recommendation's
  *emphasis* from a closed catalogue; it never chooses diagnoses, doses or
  clinical advice.
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

### Recommendation and reinforcement learning

`POST /predict` is unchanged. The browser then sends the validated class and
confidence to `POST /recommend`, which:

1. derives the state `s = <CLASS>:<band>`, where the band is `low` (`< 0.70`),
   `medium` (`0.70–0.90`) or `high` (`>= 0.90`);
2. asks the contextual bandit for an action with ε-greedy exploration
   (`ε = 0.15` by default) over a closed, medically reviewed catalogue
   (5 actions for `PNEUMONIA`, 4 for `NORMAL`);
3. builds a localized prompt (ES/EN) with the class, confidence, band and chosen
   emphasis, and asks **Qwen/Qwen3-0.6B** for 3–4 plain sentences;
4. strips reasoning tags/markdown, cuts to the last complete sentence, and
   requires at least 200 characters. If the model is unavailable, slow, or does
   not meet that contract after one retry, reviewed fallback copy is returned
   with `source: "fallback"`.

Feedback is recorded once per recommendation id and applies the single-step
bandit update:

```text
Q(s, a) ← Q(s, a) + α · (reward − Q(s, a))
reward = +1 (👍) or −1 (👎), α = 0.2 by default
```

Because the interaction is a single step, there is no next state and the
discount factor `γ` is intentionally unused. The Q-table and a feedback counter
are the only persisted RL state; they contain no images, prompts or clinical
data.

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

`requirements.txt` now includes PyTorch and Transformers for the recommendation
LLM. The Qwen checkpoint is **not** bundled: it is downloaded from Hugging Face
the first time `/recommend` is called, then reused from the local cache. If the
machine has no network access or no room for the model, set
`ML_LLM_ENABLED=false`; the card still works with the deterministic fallback.

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
| `ML_SERVICE_RECOMMEND_PATH` | `/recommend` | Recommendation endpoint path |
| `ML_SERVICE_FEEDBACK_PATH` | `/rl/feedback` | Feedback endpoint path |
| `ML_SERVICE_CONNECT_TIMEOUT` | `5` | Connection timeout (seconds) |
| `ML_SERVICE_TIMEOUT` | `30` | Prediction response timeout (seconds) |
| `ML_SERVICE_RECOMMEND_TIMEOUT` | `90` | Recommendation timeout; must exceed the prediction timeout |
| `ML_SERVICE_FEEDBACK_TIMEOUT` | `15` | Feedback timeout (seconds) |
| `ML_MAX_UPLOAD_KB` | `10240` | Maximum upload size |
| `ML_MAX_DIMENSION` | `4096` | Maximum image width/height |
| `ML_RATE_LIMIT` | `20` | Analyses allowed per client per window |
| `ML_RECOMMENDATION_RATE_LIMIT` | `10` | Recommendations allowed per client per window |
| `ML_FEEDBACK_RATE_LIMIT` | `30` | Feedback votes allowed per client per window |
| `ML_RECOMMENDATION_MIN_CHARS` | `200` | Reject shorter recommendation text |
| `ML_MODEL_NAME` | `modelo_neumonia_cnn` | Name reported back in the response |
| `ML_PROBABILITY_TOLERANCE` | `0.01` | Accepted drift when probabilities must sum to 1 |

The service reads its own `ML_*` variables (see `inference-service/.env.example`).
Besides `ML_MODEL_PATH`, the preprocessing contract and the class names, the two
new groups are:

| Variable | Default | Purpose |
| --- | --- | --- |
| `ML_LLM_ENABLED` | `true` | Set `false` to skip the LLM and always use fallback copy |
| `ML_LLM_MODEL_ID` | `Qwen/Qwen3-0.6B` | Hugging Face checkpoint |
| `ML_LLM_DEVICE` | `cpu` | `cpu`, `cuda` or another Transformers device |
| `ML_LLM_DTYPE` | `float32` | `float32`, `bfloat16` or `float16` |
| `ML_LLM_MAX_NEW_TOKENS` | `240` | Generation budget |
| `ML_LLM_TEMPERATURE` | `0.7` | Sampling temperature |
| `ML_LLM_TOP_P` | `0.8` | Nucleus sampling |
| `ML_LLM_TOP_K` | `20` | Top-k sampling |
| `ML_LLM_MIN_CHARS` / `ML_LLM_MAX_CHARS` | `200` / `600` | Requested text contract |
| `ML_RL_QTABLE_PATH` | `data/rl_qtable.json` | Git-ignored Q-table file |
| `ML_RL_ALPHA` | `0.2` | Learning rate α |
| `ML_RL_EPSILON` | `0.15` | Exploration probability |
| `ML_RL_LOW_CONFIDENCE_THRESHOLD` | `0.70` | Lower band boundary |
| `ML_RL_HIGH_CONFIDENCE_THRESHOLD` | `0.90` | Upper band boundary |
| `ML_RL_RECOMMENDATION_TTL_SECONDS` | `3600` | How long an id accepts feedback |
| `ML_RL_MAX_PENDING_RECOMMENDATIONS` | `1000` | In-memory id cap before eviction |
| `ML_RL_SEED` | unset | Optional seed for reproducible exploration |

## Verifying the inference service

Check the service directly — `/health` reports liveness **and** whether the model
artifact loaded:

```bash
curl http://127.0.0.1:8000/health
# {"status":"ok","model_loaded":true,"model_name":"modelo_neumonia_cnn",
#  "llm_loaded":false,"rl_loaded":true,"rl_feedback_count":0}
```

- `"model_loaded": true` means the `.keras` artifact was found and loaded by Keras;
  predictions will work.
- `"status":"degraded","model_loaded":false` means the process is up but the
  artifact is missing or failed to load. Verify the file exists at
  `inference-service/models/modelo_neumonia_cnn.keras` (or the path in
  `ML_MODEL_PATH`) and reinstall the TensorFlow/Keras dependencies. Check the
  uvicorn console for a `Model artifact not found at ...` warning.
- `llm_loaded` becomes `true` only after the first recommendation loads Qwen; it
  stays `false` while the lazy model has not been used or after a load failure
  (fallback copy is still served).
- `rl_loaded` confirms the Q-table was instantiated and
  `rl_feedback_count` reports how many votes have been applied.

The RL agent can be inspected directly:

```bash
curl http://127.0.0.1:8000/rl/status
```

## HTTP API

### Laravel

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/` | Analyzer page (bilingual ES/EN, light/dark) |
| `POST` | `/analyze` | Upload `image` and receive a prediction (throttled) |
| `POST` | `/recommend` | JSON `{class, confidence, locale}` → recommendation + RL insight (throttled) |
| `POST` | `/recommendation/feedback` | JSON `{recommendation_id, reward}` → Q-value update (throttled, CSRF) |
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

Successful `/recommend` response (the FastAPI `recommendation_id` is exposed as
`id` so the browser can rate it):

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
        { "id": "seek_medical_evaluation", "label": "Prompt medical evaluation", "q_value": 0.25 }
      ]
    }
  }
}
```

Successful `/recommendation/feedback` response:

```json
{
  "success": true,
  "feedback": { "old_q": 0.25, "new_q": 0.45, "feedback_count": 1 }
}
```

Error responses use `{ "success": false, "error": { "code", "message", "fields"? } }`
with codes `VALIDATION_ERROR` (422), `INVALID_MODEL_RESPONSE` (502),
`MODEL_UNAVAILABLE` (503), `MODEL_TIMEOUT` (504), `RATE_LIMITED` (429),
`SESSION_EXPIRED` (419), `RECOMMENDATION_NOT_FOUND` (404),
`FEEDBACK_ALREADY_RECORDED` (409), `FEEDBACK_REJECTED` (422) and
`UNEXPECTED` (500).

### Inference service

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/health` | Liveness, CNN/LLM/RL flags and feedback count |
| `POST` | `/predict` | Multipart `image` field; returns `class` and `probabilities` |
| `POST` | `/recommend` | JSON `{class, confidence, locale}`; returns text + RL insight |
| `POST` | `/rl/feedback` | JSON `{recommendation_id, reward}`; updates the Q-table |
| `GET` | `/rl/status` | α, ε, feedback count and the full Q-table |

The service reports `MODEL_UNAVAILABLE` (503) when the artifact is missing or
fails to load, and `INVALID_IMAGE` (413/422) when the upload cannot be decoded.
`/recommend` reports `UNSUPPORTED_CLASS` (422) for an unknown class;
`/rl/feedback` reports `RECOMMENDATION_NOT_FOUND` (404),
`FEEDBACK_ALREADY_RECORDED` (409) or `INVALID_FEEDBACK` (422). An LLM failure
never surfaces there: the response succeeds with `source: "fallback"`.

## Testing

```bash
# Laravel (Pest) — 90 tests, 277 assertions
php artisan test --compact

# Inference service (pytest) — 68 tests
cd inference-service
.venv/Scripts/python -m pytest
```

The Python suite uses a `FakeLLM` and temporary Q-table, so it never downloads
Qwen or touches the real learning file.

To watch Q-values converge without the UI or the model:

```bash
cd inference-service
.venv/Scripts/python scripts/simulate_feedback.py
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

### The recommendation card says fallback / `/health` shows `llm_loaded: false`

This is expected until the first `/recommend` call loads Qwen. Check the uvicorn
log for the `Recommendation LLM unavailable (...)` warning. Common causes:

- PyTorch/Transformers are missing: reinstall `pip install -r requirements.txt`.
- The Hugging Face download failed (no network, proxy, or disk space). Retry once
  connectivity is available; the service deliberately disables the LLM for the
  rest of the process after a failure so requests do not hang.
- You set `ML_LLM_ENABLED=false`: remove it or set `true` to use the model.
- CPU/RAM are too limited: keep `ML_LLM_DEVICE=cpu`, try
  `ML_LLM_DTYPE=bfloat16` (or `float16`) and lower `ML_LLM_MAX_NEW_TOKENS`.

The UI is designed to keep working in this state: the text comes from reviewed
fallback copy and `source` is reported as `fallback`.

### Feedback returns 404/409, or the buttons stay disabled

- `RECOMMENDATION_NOT_FOUND` means the in-memory id expired
  (`ML_RL_RECOMMENDATION_TTL_SECONDS`, default one hour) or the service
  restarted. Analyze the image again to get a new recommendation.
- `FEEDBACK_ALREADY_RECORDED` means that id was already rated; one vote is
  accepted per recommendation.
- If the Q-table file cannot be written, the service rolls the in-memory update
  back and reports `UNEXPECTED`. Make sure `inference-service/data/` is writable
  (it is created automatically) or point `ML_RL_QTABLE_PATH` somewhere writable.
- The feedback route is CSRF-protected and throttled (`ML_FEEDBACK_RATE_LIMIT`);
  reload the page after a long idle period if the session expired.

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
The recommendation id store only keeps an in-memory `state`/`action` pair for
one hour by default, and the persisted Q-table contains only floating-point
Q-values plus a feedback counter. Generated text, prompts, images and clinical
values are never written to the RL file.
