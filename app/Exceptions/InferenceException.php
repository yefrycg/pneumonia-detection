<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base class for every failure originating in the inference boundary.
 *
 * Carries a stable error code used by the UI and localized in the
 * `errors` language file, plus a report context that deliberately excludes
 * anything derived from the radiograph itself.
 */
abstract class InferenceException extends RuntimeException
{
    /**
     * Stable, translatable identifier for this failure.
     */
    abstract public function errorCode(): string;

    /**
     * Status returned to the browser for this failure.
     */
    abstract public function httpStatus(): int;

    /**
     * Structured data merged into the log entry when the exception is reported.
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [
            'error_code' => $this->errorCode(),
            'model' => config('inference.model.name'),
        ];
    }

    /**
     * Translation key for the message shown to the end user.
     */
    public function translationKey(): string
    {
        return 'errors.inference.'.$this->errorCode();
    }
}
