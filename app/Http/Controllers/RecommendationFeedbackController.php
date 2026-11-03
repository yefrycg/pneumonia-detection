<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecommendationFeedbackRequest;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;

/**
 * Records one thumbs-up or thumbs-down for the RL agent.
 */
final class RecommendationFeedbackController extends Controller
{
    public function __invoke(
        RecommendationFeedbackRequest $request,
        RecommendationService $service,
    ): JsonResponse {
        $result = $service->feedback(
            (string) $request->validated('recommendation_id'),
            (int) $request->validated('reward'),
        );

        return response()->json([
            'success' => true,
            'feedback' => $result->toArray(),
        ]);
    }
}
