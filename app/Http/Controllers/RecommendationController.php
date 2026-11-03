<?php

namespace App\Http\Controllers;

use App\Enums\PredictionClass;
use App\Http\Requests\RecommendationRequest;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;

/**
 * Turns a validated prediction into a short, localized recommendation.
 */
final class RecommendationController extends Controller
{
    public function __invoke(RecommendationRequest $request, RecommendationService $service): JsonResponse
    {
        $recommendation = $service->recommend(
            PredictionClass::from((string) $request->validated('class')),
            (float) $request->validated('confidence'),
            (string) $request->validated('locale'),
        );

        return response()->json([
            'success' => true,
            'recommendation' => $recommendation->toArray(),
        ]);
    }
}
