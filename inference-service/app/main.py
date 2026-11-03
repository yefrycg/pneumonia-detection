"""
HTTP entrypoint for the radiograph classifier.

The service is deliberately small: decode an uploaded image, run the CNN, and
answer with the probability of each class. It holds no state between requests
and never writes an upload to permanent storage.

Two optional, independent capabilities sit behind the prediction: a small
instruction-tuned LLM that writes the user-facing recommendation and a
contextual bandit that chooses the recommendation emphasis from a closed
catalogue. Neither can break ``/predict``; both degrade to deterministic text.
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from typing import AsyncIterator

from fastapi import Depends, FastAPI, File, Request, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.concurrency import run_in_threadpool

from app.llm import QwenRecommendationLLM
from app.model import ModelNotReadyError, ModelRunner
from app.preprocessing import ImageTooLargeError, InvalidImageError, decode_image, to_input_tensor
from app.recommendation import RecommendationService
from app.rl import (
    CATALOG,
    ContextualBandit,
    RecommendationAlreadyUsedError,
    RecommendationNotFoundError,
    RecommendationStore,
)
from app.schemas import (
    ErrorDetail,
    ErrorResponse,
    FeedbackRequest,
    FeedbackResponse,
    HealthResponse,
    ModelInfo,
    PredictResponse,
    RecommendationRequest,
    RecommendationResponse,
    RlStatusResponse,
)
from app.settings import Settings, get_settings

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
logger = logging.getLogger("inference")


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[None]:
    settings = get_settings()
    runner = ModelRunner(settings)
    app.state.runner = runner

    # A missing artifact must not stop the process: /health keeps reporting the
    # problem and /predict answers with a clean 503.
    runner.load()

    # RL state loads from disk at startup because it is tiny. The LLM is only
    # instantiated, never loaded: pulling Qwen on CPU would add seconds to every
    # cold start and is unnecessary until the first recommendation request.
    bandit = ContextualBandit(settings)
    store = RecommendationStore(settings)
    llm = QwenRecommendationLLM(settings)

    app.state.bandit = bandit
    app.state.recommendation_store = store
    app.state.llm = llm
    app.state.recommender = RecommendationService(llm, bandit, store, settings)

    yield


app = FastAPI(
    title="Pneumonia Inference Service",
    version="1.1.0",
    description="Experimental CNN classifier for chest radiographs with LLM recommendations and RL feedback.",
    lifespan=lifespan,
)


def get_runner(request: Request) -> ModelRunner:
    return request.app.state.runner


def get_bandit(request: Request) -> ContextualBandit:
    return request.app.state.bandit


def get_recommender(request: Request) -> RecommendationService:
    return request.app.state.recommender


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
async def health(
    request: Request,
    runner: ModelRunner = Depends(get_runner),
    bandit: ContextualBandit = Depends(get_bandit),
) -> HealthResponse:
    llm = getattr(request.app.state, "llm", None)

    return HealthResponse(
        status="ok" if runner.ready else "degraded",
        model_loaded=runner.ready,
        model_name=runner.name,
        llm_loaded=bool(getattr(llm, "ready", False)),
        rl_loaded=bandit is not None,
        rl_feedback_count=bandit.feedback_count if bandit is not None else 0,
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


@app.post(
    "/recommend",
    response_model=RecommendationResponse,
    responses={422: {"model": ErrorResponse}, 503: {"model": ErrorResponse}},
)
async def recommend(
    payload: RecommendationRequest,
    recommender: RecommendationService = Depends(get_recommender),
):
    if payload.predicted_class not in CATALOG:
        return error_response(422, "UNSUPPORTED_CLASS", "The predicted class is not supported.")

    try:
        outcome = await run_in_threadpool(
            recommender.recommend,
            payload.predicted_class,
            payload.confidence,
            payload.locale,
        )
    except Exception:
        logger.exception("Recommendation generation failed.")

        return error_response(500, "UNEXPECTED", "Recommendation generation failed.")

    response = RecommendationResponse(**outcome)

    return JSONResponse(status_code=200, content=response.model_dump(by_alias=True))


@app.post(
    "/rl/feedback",
    response_model=FeedbackResponse,
    responses={
        404: {"model": ErrorResponse},
        409: {"model": ErrorResponse},
        422: {"model": ErrorResponse},
    },
)
async def feedback(
    payload: FeedbackRequest,
    recommender: RecommendationService = Depends(get_recommender),
):
    try:
        outcome = await run_in_threadpool(
            recommender.feedback,
            str(payload.recommendation_id),
            payload.reward,
        )
    except RecommendationNotFoundError:
        return error_response(
            404,
            "RECOMMENDATION_NOT_FOUND",
            "The recommendation id is unknown or expired.",
        )
    except RecommendationAlreadyUsedError:
        return error_response(
            409,
            "FEEDBACK_ALREADY_RECORDED",
            "Feedback was already recorded for this recommendation.",
        )
    except Exception:
        logger.exception("Recording RL feedback failed.")

        return error_response(500, "UNEXPECTED", "Recording feedback failed.")

    return JSONResponse(status_code=200, content=FeedbackResponse(**outcome).model_dump())


@app.get("/rl/status", response_model=RlStatusResponse)
async def rl_status(bandit: ContextualBandit = Depends(get_bandit)) -> RlStatusResponse:
    return RlStatusResponse(**bandit.status())


@app.exception_handler(RequestValidationError)
async def validation_error_handler(request: Request, exc: RequestValidationError) -> JSONResponse:
    if request.url.path == "/predict":
        return error_response(422, "INVALID_IMAGE", "A single image file named 'image' is required.")

    if request.url.path == "/rl/feedback":
        return error_response(422, "INVALID_FEEDBACK", "A valid recommendation_id and reward are required.")

    return error_response(422, "INVALID_REQUEST", "The request payload is invalid.")
