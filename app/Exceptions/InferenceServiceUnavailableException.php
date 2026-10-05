<?php

namespace App\Exceptions;

/**
 * The inference service could not be reached or reported a server side
 * failure, so no prediction could be produced.
 */
final class InferenceServiceUnavailableException extends InferenceException
{
    public function __construct(string $message, private readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    public static function unreachable(): self
    {
        return new self('The inference service could not be reached.');
    }

    public static function serverError(int $status): self
    {
        return new self("The inference service responded with status {$status}.", $status);
    }

    public function errorCode(): string
    {
        return 'MODEL_UNAVAILABLE';
    }

    public function httpStatus(): int
    {
        return 503;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [...parent::context(), 'http_status' => $this->httpStatus];
    }
}
