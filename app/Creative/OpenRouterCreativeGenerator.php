<?php

namespace App\Creative;

use App\Creative\Providers\OpenRouterClient;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class OpenRouterCreativeGenerator
{
    public function __construct(private readonly OpenRouterClient $client) {}

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return array{options: list<array{title: string, summary: string, body: string}>, metadata: array<string, mixed>}
     */
    public function generate(string $phase, array $inputs, string $feedback): array
    {
        $structureModel = (string) config('services.openrouter.structure_model');
        $structure = $this->client->chat([
            [
                'role' => 'system',
                'content' => $this->systemPrompt($phase),
            ],
            [
                'role' => 'user',
                'content' => $this->userPrompt($phase, $inputs, $feedback),
            ],
        ], $structureModel, $this->responseFormat());

        $options = $this->parseOptions($structure['content']);
        $metadata = [
            'generator' => 'openrouter',
            'model' => $structure['model'],
            'structure_model' => $structureModel,
            'prompt_version' => $phase.'-v1',
            'provider_response_ids' => $structure['id'] !== null ? [$structure['id']] : [],
            'usage' => ['structure' => $structure['usage']],
            'lightweight' => (bool) config('creative.generation.lightweight', true),
            'simulation' => false,
        ];

        if ($phase !== 'brief-script') {
            return compact('options', 'metadata');
        }

        $polishModel = (string) config('services.openrouter.polish_model');
        $polished = $this->client->chat([
            [
                'role' => 'system',
                'content' => 'You are the final copy editor for a 15-second advertisement. Polish the prose so it sounds natural, precise, and emotionally clear. Preserve all factual details, headings, timestamps, shot order, purpose, audience, and call to action. Return exactly the same three-option JSON structure and no commentary.',
            ],
            [
                'role' => 'user',
                'content' => json_encode(['options' => $options], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            ],
        ], $polishModel, $this->responseFormat(), false);

        $metadata['polish_model'] = $polished['model'];
        $metadata['configured_polish_model'] = $polishModel;
        if ($polished['id'] !== null) {
            $metadata['provider_response_ids'][] = $polished['id'];
        }
        $metadata['usage']['polish'] = $polished['usage'];

        return [
            'options' => $this->parseOptions($polished['content']),
            'metadata' => $metadata,
        ];
    }

    private function systemPrompt(string $phase): string
    {
        $phaseInstruction = match ($phase) {
            'ideas' => 'Create three meaningfully different advertising ideas. Each Markdown body must include: Idea, Recognizable moment, Emotional tension, Product connection, and What this tests.',
            'concepts' => 'Create three executable advertising concepts. Each Markdown body must include: Hook, Emotional turn, Product mechanism, Creative hypothesis, and Risks or constraints.',
            'brief-script' => 'Create three distinct but executable 15-second advertisements. Each Markdown body must clearly separate Purpose, Audience, Story, Tone and constraints, 15-second script with timestamps and shot intentions, and Call to action. Keep the total timing at or below 15 seconds and make it understandable without forcing the viewer to think.',
            'sequence' => 'Create three practical editor handoffs. Each Markdown body must include: Scene order, exact timing, captions, voice timing, sound, transitions, aspect ratio, total duration, and editor notes. Use only the supplied selected scenes.',
            default => throw new RuntimeException('OpenRouter does not generate this creative phase.'),
        };

        return "You are the creative director inside a traceable short-ad production system. {$phaseInstruction} Return exactly three options as strict JSON matching the supplied schema. The body field must be readable Markdown. Do not wrap the JSON in a code fence.";
    }

    /** @param list<array<string, mixed>> $inputs */
    private function userPrompt(string $phase, array $inputs, string $feedback): string
    {
        $documents = collect($inputs)->map(function (array $input, int $index): string {
            $title = (string) ($input['title'] ?? $input['metadata']['name'] ?? 'Input '.($index + 1));
            $body = (string) ($input['body'] ?? '');

            return "## {$title}\n\n".Str::limit($body, 12000, "\n[truncated]");
        })->implode("\n\n---\n\n");

        $feedbackText = trim($feedback) !== '' ? $feedback : 'No pending feedback.';

        return "Create the next {$phase} artifacts from these selected inputs. Do not invent product claims that are absent from the input.\n\n# Selected inputs\n\n{$documents}\n\n# Pending human feedback\n\n{$feedbackText}";
    }

    /** @return array<string, mixed> */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'creative_options',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['options'],
                    'properties' => [
                        'options' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'maxItems' => 3,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['title', 'summary', 'body'],
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'summary' => ['type' => 'string'],
                                    'body' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return list<array{title: string, summary: string, body: string}> */
    private function parseOptions(string $content): array
    {
        $content = preg_replace('/\A```(?:json)?\s*|\s*```\z/i', '', trim($content)) ?? $content;

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The AI response was not valid structured JSON. Nothing was saved; you can safely try again.', previous: $exception);
        }

        $options = is_array($decoded) ? ($decoded['options'] ?? null) : null;

        if (! is_array($options) || count($options) !== 3) {
            throw new RuntimeException('The AI must return exactly three options. Nothing was saved; you can safely try again.');
        }

        return array_values(collect($options)->map(function (mixed $option): array {
            if (! is_array($option)) {
                throw new RuntimeException('One AI option had an invalid structure. Nothing was saved.');
            }

            $title = trim((string) ($option['title'] ?? ''));
            $summary = trim((string) ($option['summary'] ?? ''));
            $body = trim((string) ($option['body'] ?? ''));

            if ($title === '' || $summary === '' || $body === '') {
                throw new RuntimeException('One AI option was incomplete. Nothing was saved.');
            }

            return compact('title', 'summary', 'body');
        })->values()->all());
    }
}
