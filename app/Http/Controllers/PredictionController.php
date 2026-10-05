<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeImageRequest;
use App\Services\ImageClassificationService;
use Illuminate\Http\JsonResponse;

/**
 * Accepts one radiograph and returns the experimental prediction.
 *
 * The controller only wires request to service to response; validation,
 * transport, schema checking and rendering all live elsewhere.
 */
final class PredictionController extends Controller
{
    public function __invoke(AnalyzeImageRequest $request, ImageClassificationService $classifier): JsonResponse
    {
        $result = $classifier->classify($request->file('image'));

        return response()->json([
            'success' => true,
            'prediction' => $result->toArray(),
        ]);
    }
}
