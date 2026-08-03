<?php

namespace App\Creative\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FalClient
{
    public function configured(): bool
    {
        return trim((string) config('services.fal.key')) !== '';
    }

    /** @return array{url: string, response: array<string, mixed>} */
    public function generateVideo(string $prompt, ?string $imageUrl = null): array
    {
        $this->requireKey();
        $model = $imageUrl
            ? (string) config('services.fal.seedance_image_model')
            : (string) config('services.fal.seedance_model');
        $payload = [
            'prompt' => $prompt,
            'resolution' => config('creative.generation.lightweight', true)
                ? '480p'
                : (string) config('services.fal.resolution', '480p'),
            'duration' => config('creative.generation.lightweight', true)
                ? '4'
                : (string) config('services.fal.duration', 4),
            'aspect_ratio' => (string) config('services.fal.aspect_ratio', '9:16'),
            'generate_audio' => config('creative.generation.lightweight', true)
                ? false
                : (bool) config('services.fal.generate_audio', false),
        ];

        if ($imageUrl) {
            $payload['image_url'] = $imageUrl;
        }

        try {
            $response = $this->generationRequest()
                ->post('/'.ltrim($model, '/'), $payload)
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException($this->friendlyFailure('fal.ai Seedance generation', $exception), previous: $exception);
        }

        $url = data_get($response, 'video.url')
            ?? data_get($response, 'videos.0.url')
            ?? data_get($response, 'output.video.url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('fal.ai returned no video URL. The generation was not saved.');
        }

        return ['url' => $url, 'response' => is_array($response) ? $response : []];
    }

    public function check(): void
    {
        $this->requireKey();

        try {
            Http::baseUrl(rtrim((string) config('services.fal.platform_base_url'), '/'))
                ->withHeaders(['Authorization' => 'Key '.config('services.fal.key')])
                ->acceptJson()
                ->timeout(30)
                ->get('/models', ['limit' => 1])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException($this->friendlyFailure('fal.ai credential check', $exception), previous: $exception);
        }
    }

    private function generationRequest(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.fal.base_url'), '/'))
            ->withHeaders(['Authorization' => 'Key '.config('services.fal.key')])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('creative.generation.timeout', 180));
    }

    private function requireKey(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('FAL_KEY is missing. Add it to .env and run php artisan config:clear.');
        }
    }

    private function friendlyFailure(string $action, ConnectionException|RequestException $exception): string
    {
        $status = $exception instanceof RequestException ? ' (HTTP '.$exception->response->status().')' : '';

        return $action.$status.' failed. Check the API key, balance, and connection.';
    }
}
