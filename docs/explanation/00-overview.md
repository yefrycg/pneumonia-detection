# Explicación del código: Visión general

## Objetivo
Aplicación experimental para clasificar radiografías de tórax como NORMAL o PNEUMONIA. Separación clara: frontend Laravel+Blade+JS, backend HTTP hacia microservicio FastAPI que ejecuta CNN Keras.

## Flujo end-to-end
1. Usuario sube imagen → validación cliente + servidor
2. Laravel envía multipart a `ML_SERVICE_URL/predict` con nombre neutro
3. FastAPI preprocesa (150×150, grayscale, /255) y ejecuta modelo sigmoid
4. Retorna probabilidades por clase; Laravel valida y renderiza

## Diagrama de flujo
```text
[Browser] --POST /analyze--> [Laravel] --multipart--> [FastAPI] --model--> [CNN]
            <---JSON-------- <---JSON----------------- <---scores---
```

## Tecnologías
- PHP 8.4 / Laravel 13
- Tailwind CSS 4 / Vite / Vanilla JS
- Python 3.12 / FastAPI / TensorFlow 2.20 / Keras 3.15 / Pillow / NumPy
- SQLite (por defecto)

## Privacidad
- Sin almacenamiento en disco ni BD de imágenes
- Streams en memoria (`php://temp`)
- Nombres neutros
- Validación estricta
