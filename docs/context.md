# Project Context

## Overview
Pneumonia Detection is an experimental, bilingual (ES/EN) web app that classifies chest X-rays (JPG/JPEG/PNG) as NORMAL or PNEUMONIA. 

- **Frontend**: Laravel 13 + Blade + Tailwind CSS 4 + vanilla JS (Vite)
- **Backend**: PHP 8.4, Laravel 13.34.0
- **Inference**: FastAPI + TensorFlow/Keras (Python 3.12) exposing REST `/predict`, `/recommend`, `/rl/feedback`, `/rl/status` and `/health`
- **LLM**: `Qwen/Qwen3-0.6B` via Transformers, lazy/thread-safe, thinking disabled, deterministic fallback when unavailable
- **RL**: single-step contextual bandit (class + confidence band → emphasis action), ε-greedy 0.15, α 0.2, Q-table persisted as git-ignored JSON
- **Security/Privacy**: no image persistence, neutral filenames, memory-only uploads, CSRF, rate limiting, JSON errors, validation of MIME/contents; RL file stores only Q-values and a counter
- **Internationalization**: `app()->getLocale()` with `en/es`, middleware `SetLocale`, language selector; prompts and recommendations are localized
- **Theme**: light/dark with anti-FOUC script
