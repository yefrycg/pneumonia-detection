<?php

namespace App\Rules;

use App\Support\ImageInspector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Confirms the upload decodes as an image, not merely that it claims to be
 * one. Runs after the MIME rule, so a wrong type already failed earlier.
 */
final class ReadableImage implements ValidationRule
{
    public function __construct(private readonly ImageInspector $inspector) {}

    /**
     * Rule objects listed in a form request are constructed by hand, so the
     * inspector is resolved from the container here instead of being injected.
     */
    public static function fromContainer(): self
    {
        return new self(app(ImageInspector::class));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $this->inspector->isReadable($value)) {
            $fail(__('analyzer.validation.unreadable_image'));
        }
    }
}
