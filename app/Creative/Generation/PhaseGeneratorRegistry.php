<?php

namespace App\Creative\Generation;

use RuntimeException;

final class PhaseGeneratorRegistry
{
    public function __construct(
        private readonly SimulationPhaseGenerator $simulation,
        private readonly OpenRouterPhaseGenerator $openRouter,
    ) {}

    public function select(string $phase, bool $simulationOnly = false): PhaseGenerator
    {
        if ($simulationOnly) {
            return $this->simulation;
        }

        return match ((string) config('creative.generation.driver', 'simulation')) {
            'simulation' => $this->simulation,
            'openrouter' => $this->requiredOpenRouter($phase),
            'auto' => $this->openRouter->supports($phase) && $this->openRouter->configured()
                ? $this->openRouter
                : $this->simulation,
            default => throw new RuntimeException('CREATIVE_GENERATION_DRIVER must be auto, openrouter, or simulation.'),
        };
    }

    /** @return array{configured_driver: string, effective_driver: string, label: string, external: bool, cost: string, description: string} */
    public function state(string $phase): array
    {
        $configured = (string) config('creative.generation.driver', 'simulation');
        $effective = 'simulation';

        if ($configured === 'openrouter' && $this->openRouter->supports($phase)) {
            $effective = $this->openRouter->configured() ? 'openrouter' : 'unavailable';
        } elseif ($configured === 'auto'
            && $this->openRouter->supports($phase)
            && $this->openRouter->configured()) {
            $effective = 'openrouter';
        }

        return [
            'configured_driver' => $configured,
            'effective_driver' => $effective,
            'label' => match ($effective) {
                'openrouter' => 'OpenRouter',
                'unavailable' => 'Provider unavailable',
                default => 'Simulation',
            },
            'external' => $effective === 'openrouter',
            'cost' => $effective === 'openrouter' ? 'Provider cost not precomputed' : '$0.00',
            'description' => match ($effective) {
                'openrouter' => 'Selected inputs and pending feedback are sent to OpenRouter; provider charges may apply.',
                'unavailable' => 'OpenRouter is selected but not configured. Generation will fail without a configured key.',
                default => in_array($phase, ['images', 'scenes'], true)
                    ? 'Deterministic local media fixtures with job records, probes, and hashes; no provider is contacted.'
                    : 'Local deterministic fixtures; no external request is made.',
            },
        ];
    }

    private function requiredOpenRouter(string $phase): PhaseGenerator
    {
        if (! $this->openRouter->supports($phase)) {
            return $this->simulation;
        }

        if (! $this->openRouter->configured()) {
            throw new RuntimeException('The OpenRouter generation driver is enabled, but OPENROUTER_API_KEY is missing.');
        }

        return $this->openRouter;
    }
}
