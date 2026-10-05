<?php

namespace App\Exceptions;

use Throwable;

/**
 * The inference service did not answer within the configured timeout.
 */
final class InferenceTimeoutException extends InferenceException
{
    public function __construct(string $message, private readonly ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function afterSeconds(int $seconds, ?Throwable $previous = null): self
    {
        return new self("The inference service did not respond within {$seconds} seconds.", $previous);
    }

    public function errorCode(): string
    {
        return 'MODEL_TIMEOUT';
    }

    public function httpStatus(): int
    {
        return 504;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [...parent::context(), 'timeout_seconds' => config('inference.timeout')];
    }
}
