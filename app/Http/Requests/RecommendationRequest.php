<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the prediction values before a recommendation is requested.
 *
 * The class list and supported locales come from configuration, so adding a
 * class to the model contract automatically extends this boundary.
 */
final class RecommendationRequest extends FormRequest
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
            'class' => ['required', 'string', Rule::in(config('inference.classes'))],

            'confidence' => ['required', 'numeric', 'between:0,1'],

            'locale' => [
                'required',
                'string',
                Rule::in(config('app.supported_locales', ['en', 'es'])),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'class' => __('analyzer.recommendation.fields.class'),
            'confidence' => __('analyzer.recommendation.fields.confidence'),
        ];
    }
}
