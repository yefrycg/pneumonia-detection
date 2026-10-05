<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * Canned answer from the inference service, shaped like the real contract.
 */
function inferenceResponse(array $probabilities = ['NORMAL' => 0.18, 'PNEUMONIA' => 0.82]): array
{
    return [
        'success' => true,
        'model' => ['name' => 'modelo_neumonia_cnn'],
        'prediction' => [
            'class' => array_key_first(array_filter($probabilities, fn (float $p): bool => $p >= 0.5)),
            'probabilities' => $probabilities,
        ],
    ];
}

function radiograph(string $name = 'scan.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 150, 150);
}

it('returns the prediction for a valid radiograph', function () {
    Http::fake(['*/predict' => Http::response(inferenceResponse())]);

    $this->post(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('prediction.class', 'PNEUMONIA')
        ->assertJsonPath('prediction.confidence', 0.82)
        ->assertJsonPath('prediction.model', 'modelo_neumonia_cnn')
        ->assertJsonPath('prediction.probabilities.0.class', 'NORMAL')
        ->assertJsonPath('prediction.probabilities.0.percentage', 18)
        ->assertJsonPath('prediction.probabilities.1.class', 'PNEUMONIA');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/predict')
        && $request->hasFile('image'));
});

it('forwards the file under a neutral name instead of the uploaded one', function () {
    Http::fake(['*/predict' => Http::response(inferenceResponse())]);

    $this->post(route('analyzer.analyze'), ['image' => radiograph('paciente-juan-perez.jpg')])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->body(), 'filename="radiograph.jpg"')
        && ! str_contains($request->body(), 'paciente-juan-perez'));
});

it('rejects a request without an image', function () {
    $this->postJson(route('analyzer.analyze'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.message', __('errors.inference.VALIDATION_ERROR'))
        ->assertJsonStructure(['error' => ['fields' => ['image']]]);
});

it('rejects a file that is not an image at all', function () {
    Http::fake(['*' => Http::response(inferenceResponse())]);

    $this->post(route('analyzer.analyze'), [
        'image' => UploadedFile::fake()->create('report.pdf', 64, 'application/pdf'),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    Http::assertNothingSent();
});

it('rejects an image extension whose contents are not an image', function () {
    Http::fake(['*' => Http::response(inferenceResponse())]);

    $this->post(route('analyzer.analyze'), [
        'image' => UploadedFile::fake()->createWithContent('scan.jpg', 'not really a jpeg'),
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('rejects an image larger than the configured limit', function () {
    config()->set('inference.upload.max_kb', 10);

    $this->post(route('analyzer.analyze'), [
        'image' => UploadedFile::fake()->image('scan.jpg')->size(500),
    ])->assertStatus(422);
});

it('reports an unreachable inference service as a friendly 503', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'MODEL_UNAVAILABLE')
        ->assertJsonPath('error.message', __('errors.inference.MODEL_UNAVAILABLE'));
});

it('reports a slow inference service as a friendly 504', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertStatus(504)
        ->assertJsonPath('error.code', 'MODEL_TIMEOUT')
        ->assertJsonPath('error.message', __('errors.inference.MODEL_TIMEOUT'));
});

it('recognises a guzzle timeout hidden behind the client connection exception', function () {
    $connectException = new ConnectException(
        'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
        new Request('POST', 'http://127.0.0.1:8100/predict'),
    );

    Http::fake(fn () => throw new ConnectionException($connectException->getMessage(), 0, $connectException));

    $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertStatus(504)
        ->assertJsonPath('error.code', 'MODEL_TIMEOUT');
});

it('recognises a refused guzzle connection hidden behind the client connection exception', function () {
    $connectException = new ConnectException(
        'cURL error 7: Failed to connect to 127.0.0.1 port 8100 after 0 ms: Could not connect to server',
        new Request('POST', 'http://127.0.0.1:8100/predict'),
    );

    Http::fake(fn () => throw new ConnectionException($connectException->getMessage(), 0, $connectException));

    $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'MODEL_UNAVAILABLE');
});

it('reports a failing inference service as unavailable rather than a crash', function () {
    Http::fake(['*/predict' => Http::response(['detail' => 'model exploded'], 500)]);

    $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'MODEL_UNAVAILABLE');
});

it('rejects a payload whose probabilities do not form a distribution', function () {
    Http::fake(['*/predict' => Http::response(inferenceResponse(['NORMAL' => 0.4, 'PNEUMONIA' => 0.9]))]);

    $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'INVALID_MODEL_RESPONSE')
        ->assertJsonPath('error.message', __('errors.inference.INVALID_MODEL_RESPONSE'));
});

it('rejects a payload missing a configured class', function () {
    Http::fake(['*/predict' => Http::response(inferenceResponse(['NORMAL' => 1.0]))]);

    $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'INVALID_MODEL_RESPONSE');
});

it('never leaks internal details of the service in the error message', function () {
    Http::fake(['*/predict' => Http::response([
        'success' => false,
        'error' => ['code' => 'SOMETHING_ODD', 'message' => '/srv/app/main.py failed on line 42'],
    ])]);

    $response = $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'INVALID_MODEL_RESPONSE')
        ->assertJsonPath('error.message', __('errors.inference.INVALID_MODEL_RESPONSE'));

    expect($response->getContent())->not->toContain('/srv/app/main.py');
});

it('rate limits repeated analyses', function () {
    Http::fake(['*/predict' => Http::response(inferenceResponse())]);

    $limit = (int) config('inference.rate_limit');

    foreach (range(1, $limit) as $attempt) {
        $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])->assertOk();
    }

    $this->postJson(route('analyzer.analyze'), ['image' => radiograph()])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('never writes the uploaded radiograph to disk', function () {
    Storage::fake('local');

    Http::fake(['*/predict' => Http::response(inferenceResponse())]);

    $this->post(route('analyzer.analyze'), ['image' => radiograph('private.jpg')])->assertOk();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('keeps the analyze endpoint inside the web middleware group', function () {
    $middleware = Route::getRoutes()->getByName('analyzer.analyze')->gatherMiddleware();

    expect($middleware)->toContain('web');
});
