"""
HTTP entrypoint for the radiograph classifier.

The service is deliberately small: decode an uploaded image, run the CNN, and
answer with the probability of each class. It holds no state between requests
and never writes an upload to permanent storage.
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from typing import AsyncIterator

from fastapi import Depends, FastAPI, File, Request, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.concurrency import run_in_threadpool

from app.model import ModelNotReadyError, ModelRunner
from app.preprocessing import ImageTooLargeError, InvalidImageError, decode_image, to_input_tensor
from app.schemas import ErrorDetail, ErrorResponse, HealthResponse, ModelInfo, PredictResponse
from app.settings import Settings, get_settings

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
logger = logging.getLogger("inference")


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[None]:
    runner = ModelRunner(get_settings())
    app.state.runner = runner

    # A missing artifact must not stop the process: /health keeps reporting the
    # problem and /predict answers with a clean 503.
    runner.load()

    yield


app = FastAPI(
    title="Pneumonia Inference Service",
    version="1.0.0",
    description="Experimental CNN classifier for chest radiographs.",
    lifespan=lifespan,
)


def get_runner(request: Request) -> ModelRunner:
    return request.app.state.runner


def error_response(status: int, code: str, message: str) -> JSONResponse:
    body = ErrorResponse(error=ErrorDetail(code=code, message=message))

    return JSONResponse(status_code=status, content=body.model_dump())


async def read_upload(upload: UploadFile, settings: Settings) -> bytes:
    """
    Read the upload in bounded chunks so an oversized body is rejected before
    it is buffered in memory.
    """
    chunks: list[bytes] = []
    total = 0

    while chunk := await upload.read(64 * 1024):
        total += len(chunk)

        if total > settings.max_upload_bytes:
            raise ImageTooLargeError(
                f"The image exceeds the {settings.max_upload_mb} MB limit."
            )

        chunks.append(chunk)

    return b"".join(chunks)


@app.get("/health", response_model=HealthResponse)
async def health(runner: ModelRunner = Depends(get_runner)) -> HealthResponse:
    return HealthResponse(
        status="ok" if runner.ready else "degraded",
        model_loaded=runner.ready,
        model_name=runner.name,
    )


@app.post(
    "/predict",
    response_model=PredictResponse,
    responses={
        422: {"model": ErrorResponse},
        413: {"model": ErrorResponse},
        503: {"model": ErrorResponse},
    },
)
async def predict(
    image: UploadFile = File(...),
    runner: ModelRunner = Depends(get_runner),
    settings: Settings = Depends(get_settings),
):
    if not runner.ready:
        return error_response(503, "MODEL_UNAVAILABLE", "The model is not loaded.")

    if image.content_type is not None and image.content_type not in settings.allowed_media_types:
        return error_response(422, "INVALID_IMAGE", "Unsupported image type.")

    try:
        raw = await read_upload(image, settings)
        decoded = decode_image(raw, settings)
        tensor = to_input_tensor(decoded, settings)
    except ImageTooLargeError as error:
        return error_response(413, "INVALID_IMAGE", str(error))
    except InvalidImageError as error:
        return error_response(422, "INVALID_IMAGE", str(error))

    try:
        probabilities = await run_in_threadpool(runner.predict, tensor)
    except ModelNotReadyError:
        return error_response(503, "MODEL_UNAVAILABLE", "The model is not loaded.")
    except Exception:
        logger.exception("Inference failed for an uploaded image.")

        return error_response(500, "UNEXPECTED", "Inference failed.")

    payload = PredictResponse(
        model=ModelInfo(name=runner.name),
        prediction={
            "class": runner.predicted_class(probabilities),
            "probabilities": probabilities,
        },
    )

    return JSONResponse(status_code=200, content=payload.model_dump(by_alias=True))


@app.exception_handler(RequestValidationError)
async def validation_error_handler(request: Request, exc: RequestValidationError) -> JSONResponse:
    return error_response(422, "INVALID_IMAGE", "A single image file named 'image' is required.")
