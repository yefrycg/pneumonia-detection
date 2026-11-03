<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function recommendationResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'success' => true,
        'recommendation_id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f',
        'text' => str_repeat('Esta recomendación es un apoyo informativo y no sustituye una evaluación clínica. ', 4),
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

function feedbackResponse(array $overrides = []): array
{
    return array_replace([
        'success' => true,
        'old_q' => 0.31,
        'new_q' => 0.448,
        'feedback_count' => 5,
    ], $overrides);
}

function recommendationRequestPayload(array $overrides = []): array
{
    return array_replace([
        'class' => 'PNEUMONIA',
        'confidence' => 0.93,
        'locale' => 'es',
    ], $overrides);
}

it('returns a validated recommendation for a prediction', function () {
    Http::fake(['*/recommend' => Http::response(recommendationResponse())]);

    $this->postJson(route('analyzer.recommend'), recommendationRequestPayload())
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('recommendation.id', '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f')
        ->assertJsonPath('recommendation.source', 'llm')
        ->assertJsonPath('recommendation.state', 'PNEUMONIA:high')
        ->assertJsonPath('recommendation.action.id', 'seek_medical_evaluation')
        ->assertJsonPath('recommendation.rl.q_value', 0.31)
        ->assertJsonPath('recommendation.rl.feedback_count', 4)
        ->assertJsonPath('recommendation.rl.ranked_actions.1.id', 'warning_signs');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/recommend')
        && $request->data()['class'] === 'PNEUMONIA'
        && $request->data()['confidence'] === 0.93
        && $request->data()['locale'] === 'es');
});

it('returns the deterministic fallback as a valid recommendation', function () {
    Http::fake(['*/recommend' => Http::response(recommendationResponse(['source' => 'fallback']))]);

    $this->postJson(route('analyzer.recommend'), recommendationRequestPayload())
        ->assertOk()
        ->assertJsonPath('recommendation.source', 'fallback');
});

it('validates the recommendation request before calling the service', function (array $payload) {
    Http::fake(['*' => Http::response(recommendationResponse())]);

    $this->postJson(route('analyzer.recommend'), $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields']]);

    Http::assertNothingSent();
})->with([
    'missing class' => [['confidence' => 0.9, 'locale' => 'es']],
    'unknown class' => [['class' => 'COVID', 'confidence' => 0.9, 'locale' => 'es']],
    'confidence above one' => [['class' => 'NORMAL', 'confidence' => 1.2, 'locale' => 'es']],
    'unsupported locale' => [['class' => 'NORMAL', 'confidence' => 0.8, 'locale' => 'de']],
]);

it('reports a slow recommendation as a friendly 504', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $this->postJson(route('analyzer.recommend'), recommendationRequestPayload())
        ->assertStatus(504)
        ->assertJsonPath('error.code', 'MODEL_TIMEOUT')
        ->assertJsonPath('error.message', __('errors.inference.MODEL_TIMEOUT'));
});

it('reports an unreachable recommendation service as a friendly 503', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    $this->postJson(route('analyzer.recommend'), recommendationRequestPayload())
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'MODEL_UNAVAILABLE');
});

it('maps a recommendation service error to an unavailable service', function () {
    Http::fake(['*/recommend' => Http::response(['detail' => 'llm exploded'], 500)]);

    $this->postJson(route('analyzer.recommend'), recommendationRequestPayload())
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'MODEL_UNAVAILABLE');
});

it('rejects a recommendation that violates the text contract', function (array $overrides) {
    Http::fake(['*/recommend' => Http::response(recommendationResponse($overrides))]);

    $this->postJson(route('analyzer.recommend'), recommendationRequestPayload())
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'INVALID_MODEL_RESPONSE')
        ->assertJsonPath('error.message', __('errors.inference.INVALID_MODEL_RESPONSE'));
})->with([
    'too short' => [['text' => 'Demasiado corto.']],
    'unknown source' => [['source' => 'human']],
    'missing rl block' => [['rl' => null]],
]);

it('never leaks service internals from a recommendation error', function () {
    Http::fake(['*/recommend' => Http::response([
        'success' => false,
        'error' => ['code' => 'SOMETHING_ODD', 'message' => '/srv/app/llm.py failed on line 42'],
    ])]);

    $response = $this->postJson(route('analyzer.recommend'), recommendationRequestPayload())
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'INVALID_MODEL_RESPONSE');

    expect($response->getContent())->not->toContain('/srv/app/llm.py');
});

it('rate limits repeated recommendation requests', function () {
    config()->set('inference.recommendation_rate_limit', 1);

    Http::fake(['*/recommend' => Http::response(recommendationResponse())]);

    $this->postJson(route('analyzer.recommend'), recommendationRequestPayload())->assertOk();

    $this->postJson(route('analyzer.recommend'), recommendationRequestPayload())
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('records feedback for a recommendation', function () {
    Http::fake(['*/rl/feedback' => Http::response(feedbackResponse())]);

    $this->postJson(route('analyzer.feedback'), [
        'recommendation_id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f',
        'reward' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('feedback.old_q', 0.31)
        ->assertJsonPath('feedback.new_q', 0.448)
        ->assertJsonPath('feedback.feedback_count', 5);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/rl/feedback')
        && $request->data()['recommendation_id'] === '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f'
        && $request->data()['reward'] === 1);
});

it('reports an unknown recommendation id as gone', function () {
    Http::fake(['*/rl/feedback' => Http::response([
        'success' => false,
        'error' => ['code' => 'RECOMMENDATION_NOT_FOUND', 'message' => 'unknown'],
    ], 404)]);

    $this->postJson(route('analyzer.feedback'), [
        'recommendation_id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f',
        'reward' => 1,
    ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'RECOMMENDATION_NOT_FOUND')
        ->assertJsonPath('error.message', __('errors.inference.RECOMMENDATION_NOT_FOUND'));
});

it('reports reuse of feedback as a conflict', function () {
    Http::fake(['*/rl/feedback' => Http::response([
        'success' => false,
        'error' => ['code' => 'FEEDBACK_ALREADY_RECORDED', 'message' => 'already'],
    ], 409)]);

    $this->postJson(route('analyzer.feedback'), [
        'recommendation_id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f',
        'reward' => -1,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'FEEDBACK_ALREADY_RECORDED');
});

it('reports rejected feedback as an unprocessable request', function () {
    Http::fake(['*/rl/feedback' => Http::response([
        'success' => false,
        'error' => ['code' => 'INVALID_FEEDBACK', 'message' => 'invalid'],
    ], 422)]);

    $this->postJson(route('analyzer.feedback'), [
        'recommendation_id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f',
        'reward' => 1,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'FEEDBACK_REJECTED');
});

it('validates feedback before calling the service', function (array $payload) {
    Http::fake(['*' => Http::response(feedbackResponse())]);

    $this->postJson(route('analyzer.feedback'), $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    Http::assertNothingSent();
})->with([
    'invalid id' => [['recommendation_id' => 'not-a-uuid', 'reward' => 1]],
    'zero reward' => [['recommendation_id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f', 'reward' => 0]],
    'reward above one' => [['recommendation_id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f', 'reward' => 2]],
]);

it('rate limits repeated feedback requests', function () {
    config()->set('inference.feedback_rate_limit', 1);

    Http::fake(['*/rl/feedback' => Http::response(feedbackResponse())]);

    $payload = [
        'recommendation_id' => '6f1a8f4e-6e6b-4d2e-9d2c-4a7b8c9d0e1f',
        'reward' => 1,
    ];

    $this->postJson(route('analyzer.feedback'), $payload)->assertOk();

    $this->postJson(route('analyzer.feedback'), $payload)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED');
});
