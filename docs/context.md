# Project Context

## Overview
Pneumonia Detection is an experimental, bilingual (ES/EN) web app that classifies chest X-rays (JPG/JPEG/PNG) as NORMAL or PNEUMONIA. 

- **Frontend**: Laravel 13 + Blade + Tailwind CSS 4 + vanilla JS (Vite)
- **Backend**: PHP 8.4, Laravel 13.34.0
- **Inference**: FastAPI + TensorFlow/Keras (Python 3.12) exposing REST `/predict` and `/health`
- **Security/Privacy**: no image persistence, neutral filenames, memory-only uploads, CSRF, rate limiting, JSON errors, validation of MIME/contents
- **Internationalization**: `app()->getLocale()` with `en/es`, middleware `SetLocale`, language selector
- **Theme**: light/dark with anti-FOUC script
