<?php

namespace App\Creative\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ElevenLabsClient
{
    public function configured(): bool
    {
        return trim((string) config('services.elevenlabs.key')) !== '';
    }

    public function synthesize(string $text): string
    {
        $this->requireKey();
        $voice = trim((string) config('services.elevenlabs.voice_id'));

        if ($voice === '') {
            throw new RuntimeException('ELEVENLABS_VOICE_ID is missing. Copy a Voice ID from your ElevenLabs voice library into .env.');
        }

        try {
            return $this->request()
                ->withQueryParameters(['output_format' => (string) config('services.elevenlabs.output_format')])
                ->post('/text-to-speech/'.rawurlencode($voice), [
                    'text' => $text,
                    'model_id' => config('creative.generation.lightweight', true)
                        ? 'eleven_flash_v2_5'
                        : (string) config('services.elevenlabs.model_id'),
                ])
                ->throw()
                ->body();
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException($this->friendlyFailure('ElevenLabs speech generation', $exception), previous: $exception);
        }
    }

    public function check(): void
    {
        $this->requireKey();

        try {
            $this->request()->get('/models')->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException($this->friendlyFailure('ElevenLabs credential check', $exception), previous: $exception);
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.elevenlabs.base_url'), '/'))
            ->withHeaders(['xi-api-key' => (string) config('services.elevenlabs.key')])
            ->accept('*/*')
            ->asJson()
            ->timeout((int) config('creative.generation.timeout', 180));
    }

    private function requireKey(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('ELEVENLABS_API_KEY is missing. Add it to .env and run php artisan config:clear.');
        }
    }

    private function friendlyFailure(string $action, ConnectionException|RequestException $exception): string
    {
        $status = $exception instanceof RequestException ? ' (HTTP '.$exception->response->status().')' : '';

        return $action.$status.' failed. Check the API key, voice access, balance, and connection.';
    }
}
