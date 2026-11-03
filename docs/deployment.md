# Deployment

Local setup lives in the [README](../README.md). For production:

- Run Laravel and FastAPI as separate services; keep the inference service on a
  private network because it has no authentication of its own.
- Set `APP_DEBUG=false`, `APP_ENV=production`, HTTPS and secure session cookies.
- Provide `inference-service/models/modelo_neumonia_cnn.keras` on a persistent
  volume and point `ML_MODEL_PATH` at it.
- Allow outbound HTTPS and cache space for the first Qwen download, or set
  `ML_LLM_ENABLED=false` to serve deterministic fallback copy only.
- Put `ML_RL_QTABLE_PATH` on writable persistent storage. A single FastAPI worker
  keeps the in-memory recommendation ids and Q-table process-local; with multiple
  workers each process learns independently. Use one worker, or move RL state to
  a shared store before scaling out.
- Tune `ML_SERVICE_RECOMMEND_TIMEOUT` above `ML_SERVICE_TIMEOUT` and lower
  `ML_LLM_MAX_NEW_TOKENS` if CPU latency is too high.
- Back up only the small Q-table JSON if you want to preserve learning; it
  contains no images or clinical data.
