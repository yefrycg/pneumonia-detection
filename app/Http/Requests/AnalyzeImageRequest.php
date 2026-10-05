<?php

namespace App\Http\Requests;

use App\Rules\ReadableImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Validates the upload at the request boundary.
 *
 * Types are given as extensions so Laravel resolves the content based `mimes`
 * rule instead of trusting the declared content type header, and the original
 * filename is constrained separately as defence in depth.
 */
final class AnalyzeImageRequest extends FormRequest
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
        $upload = config('inference.upload');

        return [
            'image' => [
                'required',
                File::types($upload['allowed_extensions'])
                    ->extensions($upload['allowed_extensions'])
                    ->max($upload['max_kb']),
                // Rule objects are not constructor injected by the validation
                // factory, so the inspector is resolved where the rule is built.
                ReadableImage::fromContainer(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'image' => __('analyzer.fields.image'),
        ];
    }
}
