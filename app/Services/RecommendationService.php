<?php

namespace App\Services;

use App\Contracts\InferenceClient;
use App\Data\FeedbackResult;
use App\Data\Recommendation;
use App\Enums\PredictionClass;
use App\Exceptions\InferenceException;

/**
 * Single entry point for the LLM recommendation and its RL feedback.
 *
 * Transport and schema validation stay outside the controllers, mirroring the
 * existing classification pipeline.
 */
final class RecommendationService
{
    public function __construct(private readonly InferenceClient $client) {}

    /**
     * @throws InferenceException
     */
    public function recommend(PredictionClass $class, float $confidence, string $locale): Recommendation
    {
        return Recommendation::fromArray(
            $this->client->recommend($class, $confidence, $locale),
            (int) config('inference.recommendation.min_chars', 200),
        );
    }

    /**
     * @throws InferenceException
     */
    public function feedback(string $recommendationId, int $reward): FeedbackResult
    {
        return FeedbackResult::fromArray(
            $this->client->feedback($recommendationId, $reward),
        );
    }
}
