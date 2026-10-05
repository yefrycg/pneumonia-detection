# Architecture
Browser → Laravel /analyze (multipart) → FastAPI /predict → Keras CNN (150×150 grayscale, div_255, sigmoid). Laravel validates DTO schema.
