<?php

namespace App\Creative\Generation;

interface PhaseGenerator
{
    public function driver(): string;

    public function configured(): bool;

    public function supports(string $phase): bool;

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
    public function generate(PhaseGenerationRequest $request): array;
}
