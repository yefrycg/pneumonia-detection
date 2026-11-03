<?php

namespace App\Exceptions;

/**
 * The RL service rejected a feedback submission, for example because the
 * recommendation id expired or was already rated.
 *
 * Only the service's stable error code is trusted; the message is localised.
 */
final class RecommendationFeedbackException extends InferenceException
{
    public function __construct(
        string $message,
        private readonly string $serviceCode,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('The recommendation id is unknown or expired.', 'RECOMMENDATION_NOT_FOUND', 404);
    }

    public static function alreadyRecorded(): self
    {
        return new self('Feedback was already recorded for this recommendation.', 'FEEDBACK_ALREADY_RECORDED', 409);
    }

    public static function rejected(): self
    {
        return new self('The feedback could not be recorded.', 'FEEDBACK_REJECTED', 422);
    }

    public function errorCode(): string
    {
        return $this->serviceCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [...parent::context(), 'http_status' => $this->status];
    }
}
