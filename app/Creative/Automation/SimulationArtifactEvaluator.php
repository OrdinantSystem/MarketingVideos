<?php

namespace App\Creative\Automation;

final class SimulationArtifactEvaluator implements ArtifactEvaluator
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
    public function evaluate(string $phase, array $artifacts, array $requiredSlots, int $threshold): array
    {
        $scores = [];
        $fatalIssues = [];

        foreach ($artifacts as $artifact) {
            $artifactId = is_string($artifact['id'] ?? null) ? $artifact['id'] : '';
            $slot = is_string($artifact['slot'] ?? null) ? $artifact['slot'] : '';
            $validation = is_array($artifact['validation'] ?? null) ? $artifact['validation'] : [];
            $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
            $candidateOrder = is_int($metadata['candidate_order'] ?? null)
                && $metadata['candidate_order'] > 0
                ? $metadata['candidate_order']
                : PHP_INT_MAX;
            $body = is_string($artifact['body'] ?? null) ? $artifact['body'] : '';
            $sourceIds = $metadata['source_artifact_ids'] ?? null;
            $phaseDimensions = $this->phaseDimensions($phase, $artifact, $metadata, $body);
            $dimensions = [
                'contract_valid' => $this->dimension(($validation['valid'] ?? false) === true, 60),
                'versioned_provenance' => $this->dimension(($metadata['schema_version'] ?? null) === 1, 10),
                'simulation_boundary' => $this->dimension(($metadata['simulation'] ?? null) === true, 5),
                'source_lineage' => $this->dimension(is_array($sourceIds) && $sourceIds !== [], 5),
                'human_document' => $this->dimension(mb_strlen(trim($body)) >= 80, 5),
            ] + $phaseDimensions;
            $dimensions['retry_context_applied'] = $this->dimension(
                $this->hasValidRetryEvidence($metadata),
                0,
            );
            $score = min(100, array_sum(array_column($dimensions, 'points')));
            $requiredDimensionsMet = collect($phaseDimensions)->every(
                fn (array $dimension): bool => $dimension['met'],
            );
            $reasons = collect($dimensions)
                ->filter(fn (array $dimension): bool => $dimension['met'])
                ->keys()
                ->map(fn (string $name): string => match ($name) {
                    'contract_valid' => 'The artifact passes its phase and lineage contract.',
                    'versioned_provenance' => 'Versioned provenance is present.',
                    'simulation_boundary' => 'The candidate was produced by the local simulator.',
                    'source_lineage' => 'Exact source artifact IDs are recorded.',
                    'human_document' => 'The human-readable document is substantive.',
                    'retry_context_applied' => 'The simulator recorded inspectable retry provenance; this informational dimension adds no score.',
                    default => 'Phase-specific structural check passed: '.str_replace('_', ' ', $name).'.',
                })
                ->values()
                ->all();
            if (($validation['valid'] ?? false) !== true) {
                $errors = is_array($validation['errors'] ?? null) ? $validation['errors'] : [];
                $reasons[] = $errors === []
                    ? 'The artifact does not pass validation.'
                    : 'Validation failed: '.implode(' ', array_map('strval', $errors));
            }
            $scores[] = [
                'artifact_id' => $artifactId,
                'slot' => $slot,
                'candidate_order' => $candidateOrder,
                'score' => $score,
                'passed' => $artifactId !== ''
                    && $slot !== ''
                    && ($validation['valid'] ?? false) === true
                    && $requiredDimensionsMet
                    && $score >= $threshold,
                'reasons' => array_values($reasons),
                'dimensions' => $dimensions,
            ];
        }

        $recommendations = [];
        foreach ($requiredSlots as $slot) {
            $candidates = array_values(array_filter(
                $scores,
                fn (array $score): bool => $score['slot'] === $slot && $score['passed'],
            ));
            usort($candidates, static function (array $left, array $right): int {
                $scoreOrder = $right['score'] <=> $left['score'];
                if ($scoreOrder !== 0) {
                    return $scoreOrder;
                }
                $candidateOrder = $left['candidate_order'] <=> $right['candidate_order'];

                return $candidateOrder !== 0
                    ? $candidateOrder
                    : strcmp($left['artifact_id'], $right['artifact_id']);
            });

            if ($candidates === []) {
                $slotScores = array_values(array_filter(
                    $scores,
                    fn (array $score): bool => $score['slot'] === $slot,
                ));
                usort($slotScores, $this->scoreOrder(...));
                $strongest = $slotScores[0] ?? null;
                $details = '';
                if (is_array($strongest)) {
                    $unmet = collect($strongest['dimensions'])
                        ->filter(fn (array $dimension, string $name): bool => $name !== 'retry_context_applied'
                            && ! $dimension['met'])
                        ->keys()
                        ->values()
                        ->all();
                    $parts = [];
                    if ($unmet !== []) {
                        $parts[] = 'unmet dimensions: '.implode(', ', $unmet);
                    }
                    if ($strongest['score'] < $threshold) {
                        $parts[] = "score {$strongest['score']} is below threshold {$threshold}";
                    }
                    if ($parts !== []) {
                        $details = ' Strongest candidate diagnosis: '.implode('; ', $parts).'.';
                    }
                }
                $fatalIssues[] = "No eligible {$slot} candidate reached the structural requirements.{$details}";

                continue;
            }

            $recommendations[$slot] = $candidates[0]['artifact_id'];
        }

        $passed = $requiredSlots !== []
            && $fatalIssues === []
            && count($recommendations) === count($requiredSlots);

        return [
            'passed' => $passed,
            'threshold' => $threshold,
            'rubric_name' => 'structural_eligibility',
            'rubric_version' => $phase.'-structural-v4',
            'scores' => $scores,
            'recommendations' => $recommendations,
            'fatal_issues' => $fatalIssues,
            'diagnosis' => $passed
                ? 'Every required slot has a valid, traceable simulation candidate above the configured threshold.'
                : implode(' ', $fatalIssues),
        ];
    }

    /** @return array{met: bool, points: int, max_points: int} */
    private function dimension(bool $met, int $points): array
    {
        return [
            'met' => $met,
            'points' => $met ? $points : 0,
            'max_points' => $points,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function hasValidRetryEvidence(array $metadata): bool
    {
        if (($metadata['retry_context_applied'] ?? false) !== true
            || ! is_string($metadata['retry_diagnosis'] ?? null)
            || trim($metadata['retry_diagnosis']) === ''
            || ! is_string($metadata['retry_refinement_action'] ?? null)
            || trim($metadata['retry_refinement_action']) === '') {
            return false;
        }

        foreach (['retry_context_sha256', 'retry_diagnosis_sha256', 'retry_refinement_sha256'] as $field) {
            if (! is_string($metadata[$field] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $metadata[$field]) !== 1) {
                return false;
            }
        }

        return hash_equals(
            (string) $metadata['retry_diagnosis_sha256'],
            hash('sha256', (string) $metadata['retry_diagnosis']),
        );
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function scoreOrder(array $left, array $right): int
    {
        $scoreOrder = $right['score'] <=> $left['score'];
        if ($scoreOrder !== 0) {
            return $scoreOrder;
        }
        $candidateOrder = $left['candidate_order'] <=> $right['candidate_order'];

        return $candidateOrder !== 0
            ? $candidateOrder
            : strcmp($left['artifact_id'], $right['artifact_id']);
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $metadata
     * @return array<string, array{met: bool, points: int, max_points: int}>
     */
    private function phaseDimensions(string $phase, array $artifact, array $metadata, string $body): array
    {
        $slot = is_string($artifact['slot'] ?? null) ? $artifact['slot'] : '';
        $shot = $metadata['shot'] ?? null;
        $shotPlan = $metadata['shot_plan'] ?? null;
        $sceneIds = $metadata['scene_artifact_ids'] ?? null;

        return match ($phase) {
            'ideas' => [
                'idea_tension_and_mechanism' => $this->dimension(
                    $this->containsAll($body, ['## Emotional tension', '## Product connection']),
                    5,
                ),
                'testable_hypothesis' => $this->dimension(str_contains($body, '## What this tests'), 5),
            ],
            'concepts' => [
                'hook_and_product_mechanism' => $this->dimension(
                    $this->containsAll($body, ['## Hook', '## Product mechanism']),
                    5,
                ),
                'hypothesis_and_constraints' => $this->dimension(
                    $this->containsAll($body, ['## Creative hypothesis', '## Risks or constraints']),
                    5,
                ),
            ],
            'brief-script' => [
                'canonical_shot_plan' => $this->dimension(
                    is_array($shotPlan) && is_array($shotPlan['shots'] ?? null) && $shotPlan['shots'] !== [],
                    5,
                ),
                'timed_story_and_cta' => $this->dimension(
                    $this->containsAll($body, ['## 15 second script', '## Shot intentions', '## Call to action']),
                    5,
                ),
            ],
            'images' => [
                'shot_snapshot' => $this->dimension(is_array($shot) && ($shot['id'] ?? null) === $slot, 5),
                'variant_generation_direction' => $this->dimension(
                    is_string($metadata['variant'] ?? null) && str_contains($body, '## Generation prompt'),
                    5,
                ),
            ],
            'scenes' => [
                'shot_and_source_snapshot' => $this->dimension(
                    is_array($shot)
                        && ($shot['id'] ?? null) === $slot
                        && is_array($sourceIds = $metadata['source_artifact_ids'] ?? null)
                        && $sourceIds !== [],
                    5,
                ),
                'motion_and_timing' => $this->dimension(
                    $this->containsAll($body, ['## Motion', '## Duration']),
                    5,
                ),
            ],
            'sequence' => [
                'scene_manifest' => $this->dimension(
                    is_array($shotPlan)
                        && is_array($shotPlan['shots'] ?? null)
                        && $shotPlan['shots'] !== []
                        && is_array($sceneIds)
                        && count($sceneIds) === count($shotPlan['shots']),
                    5,
                ),
                'editor_handoff' => $this->dimension(
                    $this->containsAll($body, ['## Exact timing', '## Captions', '## Editor notes']),
                    5,
                ),
            ],
            default => [
                'known_phase' => $this->dimension(false, 5),
                'phase_contract' => $this->dimension(false, 5),
            ],
        };
    }

    /** @param list<string> $needles */
    private function containsAll(string $haystack, array $needles): bool
    {
        $normalizedHaystack = preg_replace('/[^a-z0-9]+/i', ' ', $haystack);
        $normalizedHaystack = is_string($normalizedHaystack)
            ? trim(preg_replace('/\s+/', ' ', $normalizedHaystack) ?? '')
            : '';
        foreach ($needles as $needle) {
            $normalizedNeedle = preg_replace('/[^a-z0-9]+/i', ' ', $needle);
            $normalizedNeedle = is_string($normalizedNeedle)
                ? trim(preg_replace('/\s+/', ' ', $normalizedNeedle) ?? '')
                : '';
            if ($normalizedNeedle === '' || ! str_contains($normalizedHaystack, $normalizedNeedle)) {
                return false;
            }
        }

        return true;
    }
}
