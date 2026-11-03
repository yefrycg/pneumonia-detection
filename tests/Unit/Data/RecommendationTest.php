<?php

use App\Data\FeedbackResult;
use App\Data\RankedAction;
use App\Data\Recommendation;
use App\Exceptions\InvalidInferenceResponseException;

function recommendationText(): string
{
    return rtrim(str_repeat('Esta recomendación es un apoyo informativo y no sustituye una evaluación clínica. ', 4));
}

function recommendationPayload(array $overrides = []): array
{
    return array_replace([
        'success' => true,
        'recommendation_id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f',
        'text' => recommendationText(),
        'source' => 'llm',
        'state' => 'PNEUMONIA:high',
        'action' => [
            'id' => 'seek_medical_evaluation',
            'label' => 'Evaluación médica oportuna',
        ],
        'rl' => [
            'q_value' => 0.31,
            'is_exploration' => false,
            'feedback_count' => 4,
            'ranked_actions' => [
                ['id' => 'seek_medical_evaluation', 'label' => 'Evaluación médica oportuna', 'q_value' => 0.31],
                ['id' => 'warning_signs', 'label' => 'Signos de alarma', 'q_value' => 0.12],
            ],
        ],
    ], $overrides);
}

it('builds a recommendation from a valid payload', function () {
    $recommendation = Recommendation::fromArray(recommendationPayload());

    expect($recommendation->id)->toBe('6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f')
        ->and($recommendation->source)->toBe('llm')
        ->and($recommendation->isFallback())->toBeFalse()
        ->and($recommendation->state)->toBe('PNEUMONIA:high')
        ->and($recommendation->actionId)->toBe('seek_medical_evaluation')
        ->and($recommendation->qValue)->toBe(0.31)
        ->and($recommendation->feedbackCount)->toBe(4)
        ->and($recommendation->rankedActions)->toHaveCount(2)
        ->and($recommendation->rankedActions[0])->toBeInstanceOf(RankedAction::class);
});

it('serialises the recommendation for the browser', function () {
    $recommendation = Recommendation::fromArray(recommendationPayload());

    expect($recommendation->toArray())->toBe([
        'id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f',
        'text' => recommendationText(),
        'source' => 'llm',
        'state' => 'PNEUMONIA:high',
        'action' => [
            'id' => 'seek_medical_evaluation',
            'label' => 'Evaluación médica oportuna',
        ],
        'rl' => [
            'q_value' => 0.31,
            'is_exploration' => false,
            'feedback_count' => 4,
            'ranked_actions' => [
                ['id' => 'seek_medical_evaluation', 'label' => 'Evaluación médica oportuna', 'q_value' => 0.31],
                ['id' => 'warning_signs', 'label' => 'Signos de alarma', 'q_value' => 0.12],
            ],
        ],
    ]);
});

it('marks fallback text explicitly', function () {
    $recommendation = Recommendation::fromArray(recommendationPayload(['source' => 'fallback']));

    expect($recommendation->isFallback())->toBeTrue();
});

it('rejects a missing or invalid recommendation id', function (mixed $id) {
    Recommendation::fromArray(recommendationPayload(['recommendation_id' => $id]));
})->with([
    'missing' => [null],
    'not a uuid' => ['recommendation-1'],
])->throws(InvalidInferenceResponseException::class);

it('rejects text shorter than the configured minimum', function () {
    Recommendation::fromArray(recommendationPayload(['text' => 'Demasiado corto.']));
})->throws(InvalidInferenceResponseException::class);

it('rejects an unknown source', function () {
    Recommendation::fromArray(recommendationPayload(['source' => 'human']));
})->throws(InvalidInferenceResponseException::class);

it('rejects a missing or invalid action', function (array $action) {
    Recommendation::fromArray(recommendationPayload(['action' => $action]));
})->with([
    'missing id' => [['id' => '', 'label' => 'Enfoque']],
    'missing label' => [['id' => 'seek_medical_evaluation', 'label' => '']],
])->throws(InvalidInferenceResponseException::class);

it('rejects an invalid rl block', function (array $rl) {
    Recommendation::fromArray(recommendationPayload(['rl' => $rl]));
})->with([
    'missing q' => [['is_exploration' => false, 'feedback_count' => 0, 'ranked_actions' => [['id' => 'a', 'label' => 'A', 'q_value' => 0.1]]]],
    'invalid mode' => [['q_value' => 0.2, 'is_exploration' => 'yes', 'feedback_count' => 0, 'ranked_actions' => [['id' => 'a', 'label' => 'A', 'q_value' => 0.1]]]],
    'negative count' => [['q_value' => 0.2, 'is_exploration' => false, 'feedback_count' => -1, 'ranked_actions' => [['id' => 'a', 'label' => 'A', 'q_value' => 0.1]]]],
    'empty ranking' => [['q_value' => 0.2, 'is_exploration' => false, 'feedback_count' => 0, 'ranked_actions' => []]],
])->throws(InvalidInferenceResponseException::class);

it('builds a feedback result from a valid payload', function () {
    $result = FeedbackResult::fromArray([
        'success' => true,
        'old_q' => 0.2,
        'new_q' => 0.36,
        'feedback_count' => 3,
    ]);

    expect($result->oldQ)->toBe(0.2)
        ->and($result->newQ)->toBe(0.36)
        ->and($result->feedbackCount)->toBe(3)
        ->and($result->toArray())->toBe([
            'old_q' => 0.2,
            'new_q' => 0.36,
            'feedback_count' => 3,
        ]);
});

it('rejects an invalid feedback payload', function (array $payload) {
    FeedbackResult::fromArray($payload);
})->with([
    'missing old q' => [['new_q' => 0.3, 'feedback_count' => 1]],
    'non numeric new q' => [['old_q' => 0.2, 'new_q' => 'high', 'feedback_count' => 1]],
    'negative count' => [['old_q' => 0.2, 'new_q' => 0.3, 'feedback_count' => -1]],
    'missing count' => [['old_q' => 0.2, 'new_q' => 0.3]],
])->throws(InvalidInferenceResponseException::class);
