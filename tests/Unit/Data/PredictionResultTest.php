<?php

use App\Data\PredictionResult;
use App\Enums\PredictionClass;
use App\Exceptions\InvalidInferenceResponseException;

function payload(array $probabilities, string $class = 'PNEUMONIA', ?string $model = 'test-model'): array
{
    return array_filter([
        'model' => $model === null ? null : ['name' => $model],
        'prediction' => [
            'class' => $class,
            'probabilities' => $probabilities,
        ],
    ], static fn (mixed $value): bool => $value !== null);
}

it('builds a result from a valid two class payload', function () {
    $result = PredictionResult::fromArray(
        payload(['NORMAL' => 0.12, 'PNEUMONIA' => 0.88], model: 'modelo_neumonia_cnn'),
        'fallback-model',
        0.01,
    );

    expect($result->predictedClass)->toBe(PredictionClass::Pneumonia)
        ->and($result->modelName)->toBe('modelo_neumonia_cnn')
        ->and($result->probabilityFor(PredictionClass::Normal))->toBe(0.12)
        ->and($result->probabilityFor(PredictionClass::Pneumonia))->toBe(0.88)
        ->and($result->confidence())->toBe(0.88);
});

it('falls back to the configured model name when the payload omits it', function () {
    $result = PredictionResult::fromArray(
        payload(['NORMAL' => 1.0, 'PNEUMONIA' => 0.0], model: null),
        'fallback-model',
        0.01,
    );

    expect($result->modelName)->toBe('fallback-model');
});

it('orders probabilities by the canonical class order', function () {
    $result = PredictionResult::fromArray(
        payload(['PNEUMONIA' => 0.7, 'NORMAL' => 0.3], class: 'NORMAL'),
        'fallback-model',
        0.01,
    );

    expect(array_map(
        static fn ($probability) => $probability->label(),
        $result->ordered(),
    ))->toBe(['NORMAL', 'PNEUMONIA']);
});

it('resolves the predicted class regardless of surrounding whitespace or case', function () {
    $result = PredictionResult::fromArray(
        payload(['NORMAL' => 0.0, 'PNEUMONIA' => 1.0], class: '  pneumonia '),
        'fallback-model',
        0.01,
    );

    expect($result->predictedClass)->toBe(PredictionClass::Pneumonia);
});

it('serialises to a display ready shape', function () {
    $result = PredictionResult::fromArray(
        payload(['NORMAL' => 0.384, 'PNEUMONIA' => 0.616], class: 'PNEUMONIA'),
        'fallback-model',
        0.01,
    );

    expect($result->toArray())->toBe([
        'class' => 'PNEUMONIA',
        'confidence' => 0.616,
        'model' => 'test-model',
        'probabilities' => [
            ['class' => 'NORMAL', 'probability' => 0.384, 'percentage' => 38.4],
            ['class' => 'PNEUMONIA', 'probability' => 0.616, 'percentage' => 61.6],
        ],
    ]);
});

it('rejects a payload without a prediction object', function () {
    PredictionResult::fromArray(['success' => true], 'fallback-model');
})->throws(InvalidInferenceResponseException::class);

it('rejects a payload whose class is not supported', function () {
    PredictionResult::fromArray(
        payload(['NORMAL' => 0.5, 'PNEUMONIA' => 0.5], class: 'COVID'),
        'fallback-model',
    );
})->throws(InvalidInferenceResponseException::class);

it('rejects probabilities that do not cover the configured classes', function () {
    PredictionResult::fromArray(
        ['prediction' => ['class' => 'PNEUMONIA', 'probabilities' => ['NORMAL' => 1.0]]],
        'fallback-model',
    );
})->throws(InvalidInferenceResponseException::class);

it('rejects probabilities containing unknown classes', function () {
    PredictionResult::fromArray(
        ['prediction' => [
            'class' => 'PNEUMONIA',
            'probabilities' => ['NORMAL' => 0.5, 'PNEUMONIA' => 0.4, 'COVID' => 0.1],
        ]],
        'fallback-model',
    );
})->throws(InvalidInferenceResponseException::class);

it('rejects a probability outside the unit interval', function () {
    PredictionResult::fromArray(
        payload(['NORMAL' => 1.4, 'PNEUMONIA' => -0.4]),
        'fallback-model',
    );
})->throws(InvalidInferenceResponseException::class);

it('rejects a non numeric probability', function () {
    PredictionResult::fromArray(
        ['prediction' => [
            'class' => 'PNEUMONIA',
            'probabilities' => ['NORMAL' => 'high', 'PNEUMONIA' => 1.0],
        ]],
        'fallback-model',
    );
})->throws(InvalidInferenceResponseException::class);

it('rejects probabilities that do not form a distribution', function () {
    PredictionResult::fromArray(
        payload(['NORMAL' => 0.3, 'PNEUMONIA' => 0.3]),
        'fallback-model',
    );
})->throws(InvalidInferenceResponseException::class);
