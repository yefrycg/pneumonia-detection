"""Preprocessing contract: grayscale, resize to 150x150, scale to [0, 1]."""

from __future__ import annotations

import io

import numpy as np
import pytest
from PIL import Image

from app.preprocessing import (
    ImageTooLargeError,
    InvalidImageError,
    decode_image,
    to_input_tensor,
)
from app.settings import Settings


def encode(image: Image.Image, fmt: str = "PNG") -> bytes:
    buffer = io.BytesIO()
    image.save(buffer, format=fmt)

    return buffer.getvalue()


def make_settings(**overrides) -> Settings:
    return Settings(**overrides)


def test_tensor_matches_the_model_input_shape() -> None:
    settings = make_settings()
    tensor = to_input_tensor(Image.new("L", (640, 480), 128), settings)

    assert tensor.shape == (1, 150, 150, 1)
    assert tensor.dtype == np.float32


def test_pixels_are_scaled_into_the_zero_one_range() -> None:
    settings = make_settings()
    image = Image.new("L", (150, 150), 255)
    image.paste(0, (0, 0, 75, 150))

    tensor = to_input_tensor(image, settings)

    assert float(tensor.min()) == pytest.approx(0.0)
    assert float(tensor.max()) == pytest.approx(1.0)
    assert float(tensor.min()) >= 0.0
    assert float(tensor.max()) <= 1.0


def test_white_maps_to_one_and_black_to_zero() -> None:
    settings = make_settings()

    white = to_input_tensor(Image.new("L", (150, 150), 255), settings)
    black = to_input_tensor(Image.new("L", (150, 150), 0), settings)

    assert float(white.mean()) == pytest.approx(1.0)
    assert float(black.mean()) == pytest.approx(0.0)


def test_colour_images_are_flattened_to_a_single_channel() -> None:
    settings = make_settings()
    tensor = to_input_tensor(Image.new("RGB", (200, 200), (255, 0, 0)), settings)

    assert tensor.shape == (1, 150, 150, 1)


def test_images_with_alpha_decode_without_error() -> None:
    settings = make_settings()
    raw = encode(Image.new("RGBA", (64, 64), (255, 255, 255, 0)))
    tensor = to_input_tensor(decode_image(raw, settings), settings)

    assert tensor.shape == (1, 150, 150, 1)


def test_a_truncated_file_is_rejected_instead_of_half_decoded() -> None:
    settings = make_settings()
    truncated = encode(Image.new("L", (128, 128), 200))[:40]

    with pytest.raises(InvalidImageError):
        decode_image(truncated, settings)


def test_random_bytes_are_rejected() -> None:
    with pytest.raises(InvalidImageError):
        decode_image(b"this is not an image", make_settings())


def test_an_empty_upload_is_rejected() -> None:
    with pytest.raises(InvalidImageError):
        decode_image(b"", make_settings())


def test_an_image_beyond_the_dimension_limit_is_rejected() -> None:
    settings = make_settings(max_image_dimension=100)

    with pytest.raises(ImageTooLargeError):
        decode_image(encode(Image.new("L", (200, 200), 0)), settings)


def test_disabling_scaling_leaves_raw_pixel_values() -> None:
    settings = make_settings(normalization="none")
    tensor = to_input_tensor(Image.new("L", (150, 150), 255), settings)

    assert float(tensor.max()) == pytest.approx(255.0)
