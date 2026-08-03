<?php

namespace App\Creative\Automation;

use RuntimeException;

final class AutomationPolicy
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array{
     *     schema_version: int,
     *     mode: string,
     *     driver: string,
     *     start_phase: string,
     *     max_attempts_per_phase: int,
     *     max_total_attempts: int,
     *     max_simulation_units: int,
     *     max_cost_usd: float,
     *     score_threshold: int,
     *     human_gates: list<string>
     * }
     */
    public function make(string $startPhase, array $overrides = []): array
    {
        $policy = array_merge([
            'schema_version' => 1,
            'mode' => 'automatic',
            'driver' => 'simulation',
            'start_phase' => $startPhase,
            'max_attempts_per_phase' => 2,
            'max_total_attempts' => 12,
            'max_simulation_units' => 60,
            'max_cost_usd' => 0.0,
            'score_threshold' => 80,
            'human_gates' => ['brief-script', 'sequence'],
        ], $overrides);

        if (($policy['mode'] ?? null) !== 'automatic' || ($policy['driver'] ?? null) !== 'simulation') {
            throw new RuntimeException('Automatic creative runs are simulation-only in this release.');
        }

        foreach (['max_attempts_per_phase', 'max_total_attempts', 'max_simulation_units'] as $field) {
            if (! is_int($policy[$field] ?? null) || $policy[$field] < 1) {
                throw new RuntimeException("Automation policy {$field} must be a positive integer.");
            }
        }

        if (! is_int($policy['score_threshold'] ?? null)
            || $policy['score_threshold'] < 0
            || $policy['score_threshold'] > 100) {
            throw new RuntimeException('Automation policy score_threshold must be between 0 and 100.');
        }

        if (! is_numeric($policy['max_cost_usd'] ?? null) || (float) $policy['max_cost_usd'] !== 0.0) {
            throw new RuntimeException('Simulation automation must keep max_cost_usd at 0.');
        }

        if (! is_array($policy['human_gates'] ?? null) || ! array_is_list($policy['human_gates'])) {
            throw new RuntimeException('Automation policy human_gates must be a list.');
        }

        $gates = [];
        foreach ($policy['human_gates'] as $gate) {
            if (! is_string($gate) || trim($gate) === '') {
                throw new RuntimeException('Automation policy human_gates contains an invalid phase.');
            }

            $gates[] = $gate;
        }

        /** @var array{
         *     schema_version: int,
         *     mode: string,
         *     driver: string,
         *     start_phase: string,
         *     max_attempts_per_phase: int,
         *     max_total_attempts: int,
         *     max_simulation_units: int,
         *     max_cost_usd: float,
         *     score_threshold: int,
         *     human_gates: list<string>
         * } $normalized
         */
        $normalized = [
            'schema_version' => 1,
            'mode' => 'automatic',
            'driver' => 'simulation',
            'start_phase' => (string) $policy['start_phase'],
            'max_attempts_per_phase' => $policy['max_attempts_per_phase'],
            'max_total_attempts' => $policy['max_total_attempts'],
            'max_simulation_units' => $policy['max_simulation_units'],
            'max_cost_usd' => 0.0,
            'score_threshold' => $policy['score_threshold'],
            'human_gates' => array_values(array_unique($gates)),
        ];

        return $normalized;
    }
}
