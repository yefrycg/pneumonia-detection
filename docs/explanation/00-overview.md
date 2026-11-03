# Explicación del código: Visión general

## Objetivo
Aplicación experimental para clasificar radiografías de tórax como NORMAL o PNEUMONIA. Separación clara: frontend Laravel+Blade+JS, backend HTTP hacia microservicio FastAPI que ejecuta la CNN Keras. Después de la predicción, el servicio genera una recomendación breve con Qwen3-0.6B y un agente de bandit contextual ajusta el enfoque según el feedback 👍/👎.

## Flujo end-to-end
1. Usuario sube imagen → validación cliente + servidor
2. Laravel envía multipart a `ML_SERVICE_URL/predict` con nombre neutro
3. FastAPI preprocesa (150×150, grayscale, /255) y ejecuta el modelo sigmoid
4. Retorna probabilidades por clase; Laravel valida y renderiza de inmediato
5. En paralelo, el navegador llama a `POST /recommend` con clase/confianza/locale
6. FastAPI elige una acción con ε-greedy, construye el prompt, genera con Qwen y
   valida el texto; si falla, usa copy determinista de respaldo
7. El usuario puede votar una vez; `POST /recommendation/feedback` actualiza
   `Q(s,a)` y persiste la Q-table

## Diagrama de flujo
```text
[Browser] --POST /analyze--> [Laravel] --multipart--> [FastAPI] --model--> [CNN]
            <---JSON-------- <---JSON----------------- <---scores---

[Browser] --POST /recommend--> [Laravel] --JSON--> [FastAPI]
                                                   |--> bandit (estado = clase:banda)
                                                   |--> Qwen3-0.6B --> post-proceso/fallback
            <---JSON + RL------ <---JSON----------|

[Browser] --👍/👎--> [Laravel] --JSON--> [FastAPI] --> Q(s,a) actualizada + JSON
```

## Decisiones clave
- `/analyze` no espera al LLM: la tarjeta de recomendación tiene su propia carga,
  errores y throttle. Un fallo del LLM nunca rompe la predicción.
- El estado del bandit es solo `clase + banda de confianza`; la acción es el
  énfasis de la recomendación, nunca un diagnóstico, dosis o medicamento.
- La Q-table guarda únicamente valores Q y un contador. No se persisten imágenes,
  prompts, texto generado ni datos clínicos.
- El modelo se carga de forma perezosa y con lock; `USE_TF=0` evita que
  Transformers sondee TensorFlow/Keras en el mismo proceso.

## Fórmula RL
```text
Q(s, a) ← Q(s, a) + α · (r − Q(s, a))
r = +1 (👍) / −1 (👎), α = 0.2, ε = 0.15
```
Bandit de un solo paso: no hay estado siguiente, así que `γ` no se usa.

## Tecnologías
- PHP 8.4 / Laravel 13
- Tailwind CSS 4 / Vite / Vanilla JS
- Python 3.12 / FastAPI / TensorFlow 2.20 / Keras 3.15 / Pillow / NumPy
- PyTorch + Transformers + Qwen/Qwen3-0.6B (CPU por defecto)
- SQLite (por defecto)

## Privacidad
- Sin almacenamiento en disco ni BD de imágenes
- Streams en memoria (`php://temp`)
- Nombres neutros
- Validación estricta
- Ids de recomendación en memoria con TTL; feedback de un solo uso
- Q-table git-ignored con solo valores Q y contador
