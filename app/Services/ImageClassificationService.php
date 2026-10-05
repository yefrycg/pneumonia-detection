<?php

namespace App\Services;

use App\Contracts\InferenceClient;
use App\Data\PredictionResult;
use App\Exceptions\InferenceException;
use Illuminate\Http\UploadedFile;

/**
 * Single entry point for classifying a radiograph.
 *
 * Transports the image through the inference boundary and turns the raw
 * payload into a validated result, so controllers never deal with either.
 * Configuration is resolved here and handed down, keeping the data objects
 * free of framework concerns.
 */
final class ImageClassificationService
{
    public function __construct(private readonly InferenceClient $client) {}

    /**
     * @throws InferenceException
     */
    public function classify(UploadedFile $image): PredictionResult
    {
        return PredictionResult::fromArray(
            $this->client->classify($image),
            (string) config('inference.model.name'),
            (float) config('inference.model.tolerance'),
        );
    }
}
