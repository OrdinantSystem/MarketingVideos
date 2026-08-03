<?php

namespace App\Creative\Automation;

interface ArtifactEvaluator
{
    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @param  list<string>  $requiredSlots
     * @return array{
     *     passed: bool,
     *     threshold: int,
     *     rubric_name: string,
     *     rubric_version: string,
     *     scores: list<array{
     *         artifact_id: string,
     *         slot: string,
     *         candidate_order: int,
     *         score: int,
     *         passed: bool,
     *         reasons: list<string>,
     *         dimensions: array<string, array{met: bool, points: int, max_points: int}>
     *     }>,
     *     recommendations: array<string, string>,
     *     fatal_issues: list<string>,
     *     diagnosis: string
     * }
     */
    public function evaluate(string $phase, array $artifacts, array $requiredSlots, int $threshold): array;
}
