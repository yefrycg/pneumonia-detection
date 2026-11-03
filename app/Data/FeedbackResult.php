<?php

namespace App\Data;

use App\Exceptions\InvalidInferenceResponseException;

/**
 * Result of recording one thumbs-up or thumbs-down against a recommendation.
 */
final readonly class FeedbackResult
{
    public function __construct(
        public float $oldQ,
        public float $newQ,
        public int $feedbackCount,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidInferenceResponseException
     */
    public static function fromArray(array $payload): self
    {
        $oldQ = $payload['old_q'] ?? null;
        $newQ = $payload['new_q'] ?? null;
        $feedbackCount = $payload['feedback_count'] ?? null;

        if (! is_numeric($oldQ) || ! is_finite((float) $oldQ)) {
            throw InvalidInferenceResponseException::malformed('missing or invalid "old_q"');
        }

        if (! is_numeric($newQ) || ! is_finite((float) $newQ)) {
            throw InvalidInferenceResponseException::malformed('missing or invalid "new_q"');
        }

        if (! is_int($feedbackCount) || $feedbackCount < 0) {
            throw InvalidInferenceResponseException::malformed('missing or invalid "feedback_count"');
        }

        return new self((float) $oldQ, (float) $newQ, $feedbackCount);
    }

    /**
     * @return array{old_q: float, new_q: float, feedback_count: int}
     */
    public function toArray(): array
    {
        return [
            'old_q' => $this->oldQ,
            'new_q' => $this->newQ,
            'feedback_count' => $this->feedbackCount,
        ];
    }
}
