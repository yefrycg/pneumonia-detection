<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Feedback is a single +1/-1 vote tied to an unexpired recommendation id.
 */
final class RecommendationFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'recommendation_id' => ['required', 'uuid'],

            'reward' => ['required', 'integer', Rule::in([1, -1])],
        ];
    }

    public function attributes(): array
    {
        return [
            'recommendation_id' => __('analyzer.recommendation.fields.recommendation_id'),
            'reward' => __('analyzer.recommendation.fields.reward'),
        ];
    }
}
