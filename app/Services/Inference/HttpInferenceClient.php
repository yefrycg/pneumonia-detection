<?php

namespace App\Services\Inference;

use App\Contracts\InferenceClient;
use App\Exceptions\InferenceException;
use App\Exceptions\InferenceServiceUnavailableException;
use App\Exceptions\InferenceTimeoutException;
use App\Exceptions\InvalidInferenceResponseException;
use App\Exceptions\UnreadableImageException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use JsonException;
use Throwable;

/**
 * Sends the radiograph to the standalone inference service over HTTP.
 *
 * Responsibilities are deliberately narrow: stream the upload, translate
 * transport failures into domain exceptions, and hand back the decoded
 * payload. Schema validation belongs to the caller.
 */
final class HttpInferenceClient implements InferenceClient
{
    private const IMAGE_REJECTED_STATUSES = [400, 415, 422];

    public function __construct(private readonly Factory $http) {}

    public function classify(UploadedFile $image): array
    {
        $path = $image->getRealPath();

        if ($path === false || ! is_readable($path) || ($stream = fopen($path, 'rb')) === false) {
            throw InferenceServiceUnavailableException::unreachable();
        }

        /*
         * The stream is intentionally left open: the HTTP client owns it for
         * the lifetime of the multipart request and releases it together with
         * the request body. Closing it here would pull the bytes out from under
         * a client that has not finished reading, which fake transports
         * surface immediately.
         */
        try {
            $response = $this->http
                ->connectTimeout((int) config('inference.connect_timeout'))
                ->timeout((int) config('inference.timeout'))
                ->acceptJson()
                ->attach(
                    'image',
                    $stream,
                    $this->transferFilename($image),
                    ['Content-Type' => $image->getMimeType() ?: 'application/octet-stream'],
                )
                ->post($this->endpoint('predict_path'), [
                    'model' => (string) config('inference.model.name'),
                ]);
        } catch (ConnectionException $exception) {
            throw $this->translateConnectionFailure($exception);
        }

        return $this->interpret($response);
    }

    private function endpoint(string $pathKey): string
    {
        $base = rtrim((string) config('inference.url'), '/');

        return $base.'/'.ltrim((string) config('inference.'.$pathKey), '/');
    }

    /**
     * The uploaded file never reaches disk under its original name, so the
     * service receives a neutral name with an extension derived from the
     * file contents rather than from anything the client sent.
     */
    private function transferFilename(UploadedFile $image): string
    {
        return 'radiograph.'.mb_strtolower($image->guessExtension() ?: 'jpg');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InferenceException
     */
    private function interpret(Response $response): array
    {
        $status = $response->status();

        if ($response->successful()) {
            return $this->decodePayload($response);
        }

        if ($response->serverError() || in_array($status, [404, 429], true)) {
            throw InferenceServiceUnavailableException::serverError($status);
        }

        if (in_array($status, self::IMAGE_REJECTED_STATUSES, true)) {
            throw UnreadableImageException::rejectedByService($status);
        }

        throw InvalidInferenceResponseException::malformed("unexpected status {$status}");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InferenceException
     */
    private function decodePayload(Response $response): array
    {
        try {
            $payload = $response->json();
        } catch (JsonException) {
            throw InvalidInferenceResponseException::malformed('response body was not valid JSON');
        }

        if (! is_array($payload)) {
            throw InvalidInferenceResponseException::malformed('response body was not a JSON object');
        }

        if (($payload['success'] ?? true) === false) {
            throw $this->translateServiceError($payload);
        }

        return $payload;
    }

    /**
     * Only the error code is trusted; the service message is discarded because
     * it can contain file system paths or model internals.
     *
     * @param  array<string, mixed>  $payload
     */
    private function translateServiceError(array $payload): InferenceException
    {
        $code = $payload['error']['code'] ?? null;

        return match (true) {
            $code === 'MODEL_UNAVAILABLE' => InferenceServiceUnavailableException::serverError(503),
            $code === 'MODEL_TIMEOUT' => InferenceTimeoutException::afterSeconds((int) config('inference.timeout')),
            $code === 'INVALID_IMAGE' => UnreadableImageException::rejectedByService(422),
            default => InvalidInferenceResponseException::malformed(
                is_string($code) ? "service reported {$code}" : 'service reported an unknown error'
            ),
        };
    }

    private function translateConnectionFailure(ConnectionException $exception): InferenceException
    {
        if ($this->looksLikeTimeout($exception)) {
            return InferenceTimeoutException::afterSeconds((int) config('inference.timeout'), $exception);
        }

        return InferenceServiceUnavailableException::unreachable();
    }

    /**
     * Guzzle wraps the underlying cURL failure in a ConnectException and does
     * not expose the cURL errno publicly, so the whole exception chain is
     * inspected for the textual markers cURL emits. A refused connection
     * reports "cURL error 7"; a timeout reports "cURL error 28 / timed out".
     */
    private function looksLikeTimeout(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $message = mb_strtolower($current->getMessage());

            if (str_contains($message, 'curl error 28') || str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                return true;
            }
        }

        return false;
    }
}
