<?php

namespace App\Creative\Generation;

final readonly class PhaseGenerationRequest
{
    /**
     * @param  list<array<string, mixed>>  $inputs
     * @param  list<string>  $requiredSlots
     */
    public function __construct(
        public string $phase,
        public array $inputs,
        public string $feedback,
        public array $requiredSlots,
    ) {}
}
