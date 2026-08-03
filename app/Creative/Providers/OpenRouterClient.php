<?php

namespace App\Creative\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenRouterClient
{
    public function configured(): bool
    {
        return trim((string) config('services.openrouter.key')) !== '';
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $responseFormat
     * @return array{content: string, model: string, usage: array<string, mixed>, id: string|null}
     */
    public function chat(array $messages, string $model, ?array $responseFormat = null, bool $reasoning = true): array
    {
        $this->requireKey();

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens(),
        ];

        if ($responseFormat !== null) {
            $payload['response_format'] = $responseFormat;
            $payload['provider'] = ['require_parameters' => true];
        }

        if ($reasoning) {
            $payload['reasoning'] = [
                'effort' => config('creative.generation.lightweight', true)
                    ? 'low'
                    : (string) config('services.openrouter.reasoning_effort', 'low'),
            ];
        }

        try {
            $response = $this->request()->post('/chat/completions', $payload)->throw()->json();
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException($this->friendlyFailure('OpenRouter generation', $exception), previous: $exception);
        }

        $content = data_get($response, 'choices.0.message.content');

        if (is_array($content)) {
            $content = collect($content)->pluck('text')->filter()->implode('');
        }

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenRouter returned no usable text. The generation was not saved.');
        }

        return [
            'content' => trim($content),
            'model' => (string) data_get($response, 'model', $model),
            'usage' => is_array(data_get($response, 'usage')) ? data_get($response, 'usage') : [],
            'id' => is_string(data_get($response, 'id')) ? data_get($response, 'id') : null,
        ];
    }

    public function check(): void
    {
        $this->requireKey();

        try {
            $this->request()->get('/models')->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException($this->friendlyFailure('OpenRouter credential check', $exception), previous: $exception);
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.openrouter.base_url'), '/'))
            ->withToken((string) config('services.openrouter.key'))
            ->withHeaders([
                'HTTP-Referer' => (string) config('services.openrouter.http_referer'),
                'X-Title' => (string) config('services.openrouter.app_name'),
            ])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('creative.generation.timeout', 180));
    }

    private function requireKey(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('OPENROUTER_API_KEY is missing. Add it to .env and run php artisan config:clear.');
        }
    }

    private function maxTokens(): int
    {
        $configured = (int) config('services.openrouter.max_tokens', 2200);

        return config('creative.generation.lightweight', true) ? min($configured, 2200) : $configured;
    }

    private function friendlyFailure(string $action, ConnectionException|RequestException $exception): string
    {
        $status = $exception instanceof RequestException ? ' (HTTP '.$exception->response->status().')' : '';

        return $action.$status.' failed. Check the API key, model access, balance, and connection.';
    }
}
