# Seguridad, privacidad y buenas prácticas

## Uploads
- Solo JPG/JPEG/PNG, tamaño máx 10MB, dimensiones máx 4096×4096
- Validación MIME + contenido (GD)
- Nombre neutro (`radiograph.jpg`), sin nombre original
- Memoria (`php://temp`), nunca `store()` ni BD

## HTTP
- CSRF en el formulario y en `/recommend` / `/recommendation/feedback`
  (`X-CSRF-TOKEN`)
- Rate limiting separado: `analysis`, `recommendation`, `feedback`
- Respuestas JSON para `/analyze`, `/recommend` y `/recommendation/*`, sin leak
  de paths internos
- Timeouts configurables y mapeo seguro de errores; un fallo del LLM degrada a
  texto de respaldo, no a un error de predicción
- El frontend inserta texto generado con `textContent`, nunca `innerHTML`

## Recomendación y RL
- El id es un UUID; el feedback se acepta una sola vez y expira en memoria
  (`ML_RL_RECOMMENDATION_TTL_SECONDS`, 1 hora por defecto)
- La acción pertenece a un catálogo cerrado por clase; el servidor valida estado,
  acción y reward (`+1`/`-1`)
- Los prompts prohíben diagnósticos, dosis, medicamentos y promesas de resultado
- La Q-table se escribe con lock y `os.replace` atómico; solo contiene valores Q
  y un contador, nunca imágenes, prompts, texto, confianza ni datos clínicos
- El archivo está git-ignored (`inference-service/data/`)
- El LLM se carga perezosamente con lock y `USE_TF=0`; un fallo de carga se
  registra sin exponer detalles al usuario

## Config
- `.env` no commiteado (modelos ignorados)
- `inference-service/.env` separado
- No logs de datos clínicos
- El checkpoint de Qwen no se commitea; se descarga en la caché de Hugging Face
- El archivo `.keras` y la Q-table no se versionan

## Recomendaciones despliegue
- `APP_DEBUG=false`, `APP_ENV=production`
- HTTPS + cookies seguros (`SESSION_SECURE_COOKIE=true`)
- Actualizar dependencias
- Revisar CSP si se añade inline
- Model artifact en volumen seguro
- Mantener Laravel y FastAPI en una red privada; el servicio no tiene auth propia
- Si se ejecuta más de un worker de FastAPI, la Q-table y los ids en memoria son
  por proceso: usar un solo worker o mover el estado a un almacén compartido
