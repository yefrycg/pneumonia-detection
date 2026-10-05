# Seguridad, privacidad y buenas prácticas

## Uploads
- Solo JPG/JPEG/PNG, tamaño máx 10MB, dimensiones máx 4096×4096
- Validación MIME + contenido (GD)
- Nombre neutro (`radiograph.jpg`), sin nombre original
- Memoria (`php://temp`), nunca `store()` ni BD

## HTTP
- CSRF en formulario
- Rate limiting `analysis`
- Respuestas JSON para `/analyze`, sin leak de paths internos
- Timeouts configurables, mapeo errores seguro

## Config
- `.env` no commiteado (modelos ignorados)
- `inference-service/.env` separado
- No logs de datos clínicos

## Recomendaciones despliegue
- `APP_DEBUG=false`, `APP_ENV=production`
- HTTPS + cookies seguros (`SESSION_SECURE_COOKIE=true`)
- Actualizar dependencias
- Revisar CSP si se añade inline
- Model artifact en volumen seguro
