<?php

namespace App\Contracts;

use App\Enums\PredictionClass;
use App\Exceptions\InferenceException;
use Illuminate\Http\UploadedFile;

/**
 * Transport boundary to the standalone inference service.
 *
 * Implementations translate transport level problems into InferenceException
 * subclasses and return the decoded payload untouched, leaving schema
 * validation to the caller.
 */
interface InferenceClient
{
    /**
     * @return array<string, mixed> Decoded payload exactly as the service sent it.
     *
     * @throws InferenceException
     */
    public function classify(UploadedFile $image): array;

    /**
     * Ask the service to turn a prediction into a recommendation.
     *
     * @return array<string, mixed> Decoded payload exactly as the service sent it.
     *
     * @throws InferenceException
     */
    public function recommend(PredictionClass $class, float $confidence, string $locale): array;

    /**
     * Forward one thumbs-up (+1) or thumbs-down (-1) to the RL agent.
     *
     * @return array<string, mixed> Decoded payload exactly as the service sent it.
     *
     * @throws InferenceException
     */
    public function feedback(string $recommendationId, int $reward): array;
}
