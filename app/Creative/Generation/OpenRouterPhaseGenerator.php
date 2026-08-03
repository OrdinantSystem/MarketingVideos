<?php

namespace App\Creative\Generation;

use App\Creative\OpenRouterCreativeGenerator;

final class OpenRouterPhaseGenerator implements PhaseGenerator
{
    public function __construct(private readonly OpenRouterCreativeGenerator $generator) {}

    public function driver(): string
    {
        return 'openrouter';
    }

    public function configured(): bool
    {
        return $this->generator->configured();
    }

    public function supports(string $phase): bool
    {
        return in_array($phase, ['ideas', 'concepts', 'brief-script', 'sequence'], true);
    }

    /**
     * @return array{
     *     metadata: array<string, mixed>,
     *     drafts: list<array{
     *         artifact_type: string,
     *         slot: string,
     *         title: string,
     *         summary: string,
     *         body: string,
     *         metadata: array<string, mixed>
     *     }>
     * }
     */
    public function generate(PhaseGenerationRequest $request): array
    {
        $generated = $this->generator->generate($request->phase, $request->inputs, $request->feedback);

        return [
            'metadata' => $generated['metadata'],
            'drafts' => array_map(fn (array $option): array => [
                'artifact_type' => 'markdown',
                'slot' => 'primary',
                'title' => $option['title'],
                'summary' => $option['summary'],
                'body' => $option['body'],
                'metadata' => [],
            ], $generated['options']),
        ];
    }
}
