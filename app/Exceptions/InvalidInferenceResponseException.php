<?php

namespace App\Exceptions;

/**
 * The inference service answered, but not with something this application
 * can treat as a prediction.
 */
final class InvalidInferenceResponseException extends InferenceException
{
    public function __construct(string $message, private readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    public static function malformed(string $reason): self
    {
        return new self('The inference service returned an unusable payload: '.$reason, $reason);
    }

    public function errorCode(): string
    {
        return 'INVALID_MODEL_RESPONSE';
    }

    public function httpStatus(): int
    {
        return 502;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [...parent::context(), 'reason' => $this->reason];
    }
}
