"""
Turns raw upload bytes into the tensor the CNN expects.

This module is the only place that knows how a radiograph becomes numbers, so
the exact steps (grayscale, resize, scaling) are stated once and can be tested
without a model or a web server.
"""

from __future__ import annotations

import io

import numpy as np
from PIL import Image, ImageFile, UnidentifiedImageError

from app.settings import Settings

# A truncated file must fail loudly instead of decoding into a half image that
# would silently produce a confident, wrong prediction.
ImageFile.LOAD_TRUNCATED_IMAGES = False

# Guard against decompression bombs: a tiny file can otherwise declare an
# enormous canvas and exhaust memory before any size check sees the dimensions.
Image.MAX_IMAGE_PIXELS = 64_000_000


class InvalidImageError(ValueError):
    """Raised when the bytes cannot be decoded as a usable image."""


class ImageTooLargeError(ValueError):
    """Raised when the declared pixel dimensions exceed the configured limit."""


def decode_image(raw: bytes, settings: Settings) -> Image.Image:
    """
    Decode upload bytes into a Pillow image.

    ``load()`` is called explicitly because Pillow defers decoding until the
    pixels are touched; without it a corrupt file would slip through and only
    explode later inside NumPy.
    """
    if not raw:
        raise InvalidImageError("The uploaded file is empty.")

    try:
        image = Image.open(io.BytesIO(raw))
        image.load()
    except UnidentifiedImageError as error:
        raise InvalidImageError("The file is not a recognizable image.") from error
    except (OSError, ValueError) as error:
        raise InvalidImageError("The image is corrupt or incomplete.") from error

    width, height = image.size

    if width < 1 or height < 1:
        raise InvalidImageError("The image has no pixels.")

    if width > settings.max_image_dimension or height > settings.max_image_dimension:
        raise ImageTooLargeError(
            f"The image is {width}x{height}, larger than the "
            f"{settings.max_image_dimension}px limit."
        )

    return image


def to_input_tensor(image: Image.Image, settings: Settings) -> np.ndarray:
    """
    Convert a decoded image into a float32 tensor of shape ``(1, H, W, C)``.

    Order matters: convert to grayscale first, then resize, so the resize does
    not average colour channels into noise.
    """
    prepared = image

    if settings.grayscale:
        # ``L`` is 8-bit luminance, matching the single channel the model was
        # trained on. ``convert`` also flattens PNG alpha against black, which
        # is the safe default for a radiograph.
        prepared = prepared.convert("L")
    elif prepared.mode not in ("RGB", "L"):
        prepared = prepared.convert("RGB")

    prepared = prepared.resize(
        (settings.input_width, settings.input_height),
        resample=Image.Resampling.BILINEAR,
    )

    array = np.asarray(prepared, dtype=np.float32)

    if settings.grayscale:
        array = array.reshape(settings.input_height, settings.input_width, 1)

    if settings.normalization == "div_255":
        array = array / 255.0

    return np.expand_dims(array, axis=0)
