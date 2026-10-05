# CPU-only image for the inference service. The model artifact is copied in at
# build time; keep it out of the build context and mount it instead if you
# prefer to ship weights separately.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /service

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY models ./models

EXPOSE 8000

# One worker on purpose: the model is loaded once per process and TensorFlow
# does not benefit from more than one per core. Scale with replicas, not
# workers.
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
