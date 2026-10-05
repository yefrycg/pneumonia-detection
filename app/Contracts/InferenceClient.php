<?php

namespace App\Contracts;

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
}
