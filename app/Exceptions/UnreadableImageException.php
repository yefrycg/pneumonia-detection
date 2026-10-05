<?php

namespace App\Exceptions;

/**
 * The inference service refused the upload because it could not decode the
 * image, which means the file passed upload validation but is still unusable.
 */
final class UnreadableImageException extends InferenceException
{
    public function __construct(string $message, private readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    public static function rejectedByService(int $status): self
    {
        return new self("The inference service could not decode the image (status {$status}).", $status);
    }

    public function errorCode(): string
    {
        return 'INVALID_IMAGE';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [...parent::context(), 'http_status' => $this->httpStatus];
    }
}
