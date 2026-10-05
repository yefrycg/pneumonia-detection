# FastAPI: Servicio de inferencia

## Archivos
- `app/settings.py` – configuración ML_* (pydantic-settings)
- `app/schemas.py` – modelos Pydantic (response con alias `class`)
- `app/preprocessing.py` – decodificación + resize 150×150 grayscale + /255
- `app/model.py` – carga perezosa Keras `.keras`
- `app/main.py` – endpoints + manejo errores

## Endpoints
- `GET /health` → estado + `model_loaded`, `model_name`
- `POST /predict` → recibe `image` (UploadFile), retorna predicción

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

## Errores
- `INVALID_IMAGE` (422/413)
- `MODEL_UNAVAILABLE` (503)
- `UNEXPECTED` (500)

## Ejemplo respuesta
```json
{"success":true,"model":{"name":"modelo_neumonia_cnn"},"prediction":{"class":"PNEUMONIA","probabilities":{"NORMAL":0.07,"PNEUMONIA":0.93}}}
```
