<?php

namespace Tests\Unit;

use App\Creative\Automation\SimulationArtifactEvaluator;
use PHPUnit\Framework\TestCase;

class SimulationArtifactEvaluatorTest extends TestCase
{
    public function test_it_recommends_one_valid_candidate_for_every_required_slot(): void
    {
        $artifacts = [
            $this->artifact('shot-01-a', 'shot-01', true, 1, 'images'),
            $this->artifact('shot-01-b', 'shot-01', true, 2, 'images'),
            $this->artifact('shot-02-a', 'shot-02', true, 3, 'images'),
        ];

        $evaluation = (new SimulationArtifactEvaluator)->evaluate(
            'images',
            $artifacts,
            ['shot-01', 'shot-02'],
            80,
        );

        $this->assertTrue($evaluation['passed']);
        $this->assertSame('shot-01-a', $evaluation['recommendations']['shot-01']);
        $this->assertSame('shot-02-a', $evaluation['recommendations']['shot-02']);
        $this->assertSame([], $evaluation['fatal_issues']);
        $this->assertSame('structural_eligibility', $evaluation['rubric_name']);
        $this->assertSame('images-structural-v4', $evaluation['rubric_version']);
        $this->assertArrayHasKey('shot_snapshot', $evaluation['scores'][0]['dimensions']);
        $this->assertArrayHasKey('variant_generation_direction', $evaluation['scores'][0]['dimensions']);
    }

    public function test_it_refuses_to_pass_when_one_slot_has_no_valid_candidate(): void
    {
        $evaluation = (new SimulationArtifactEvaluator)->evaluate(
            'scenes',
            [
                $this->artifact('shot-01-a', 'shot-01', true, 1, 'scenes'),
                $this->artifact('shot-02-a', 'shot-02', false, 2, 'scenes'),
            ],
            ['shot-01', 'shot-02'],
            80,
        );

        $this->assertFalse($evaluation['passed']);
        $this->assertArrayNotHasKey('shot-02', $evaluation['recommendations']);
        $this->assertStringContainsString('shot-02', $evaluation['diagnosis']);
    }

    public function test_equal_scores_use_stable_candidate_order_before_random_artifact_ids(): void
    {
        $evaluation = (new SimulationArtifactEvaluator)->evaluate(
            'images',
            [
                $this->artifact('aaa-random-second', 'shot-01', true, 2, 'images'),
                $this->artifact('zzz-random-first', 'shot-01', true, 1, 'images'),
            ],
            ['shot-01'],
            80,
        );

        $this->assertSame('zzz-random-first', $evaluation['recommendations']['shot-01']);
        $this->assertSame(
            [2, 1],
            array_column($evaluation['scores'], 'candidate_order'),
        );
    }

    public function test_each_phase_specific_rubric_is_required_even_above_the_score_threshold(): void
    {
        foreach (['ideas', 'concepts', 'brief-script', 'images', 'scenes', 'sequence'] as $phase) {
            $valid = $this->artifact("{$phase}-valid", 'primary', true, 1, $phase);
            $passed = (new SimulationArtifactEvaluator)->evaluate($phase, [$valid], ['primary'], 80);
            $this->assertTrue($passed['passed'], "{$phase} fixture should meet its structural rubric.");

            $invalid = $valid;
            $invalid['body'] = str_repeat('Substantive but phase-incomplete document. ', 4);
            foreach (['shot', 'variant', 'shot_plan', 'scene_artifact_ids'] as $field) {
                unset($invalid['metadata'][$field]);
            }
            $failed = (new SimulationArtifactEvaluator)->evaluate($phase, [$invalid], ['primary'], 80);

            $this->assertGreaterThanOrEqual(80, $failed['scores'][0]['score']);
            $this->assertFalse($failed['passed'], "{$phase} must not pass on generic points alone.");
            $this->assertStringContainsString('unmet dimensions', $failed['diagnosis']);
        }
    }

    public function test_retry_self_attestation_is_informational_and_cannot_raise_a_score(): void
    {
        $initial = $this->artifact('initial', 'shot-01', true, 1, 'images');
        $retry = $initial;
        $retry['id'] = 'retry';
        $retry['metadata'] += [
            'retry_context_applied' => true,
            'retry_context_sha256' => str_repeat('a', 64),
            'retry_diagnosis' => 'The image variant direction needs refinement.',
            'retry_diagnosis_sha256' => hash('sha256', 'The image variant direction needs refinement.'),
            'retry_refinement_action' => 'Differentiate the keyframe variant.',
            'retry_refinement_sha256' => str_repeat('b', 64),
        ];

        $evaluation = (new SimulationArtifactEvaluator)->evaluate(
            'images',
            [$initial, $retry],
            ['shot-01'],
            80,
        );

        $this->assertSame($evaluation['scores'][0]['score'], $evaluation['scores'][1]['score']);
        $this->assertSame(0, $evaluation['scores'][1]['dimensions']['retry_context_applied']['points']);
        $this->assertSame(0, $evaluation['scores'][1]['dimensions']['retry_context_applied']['max_points']);
        $this->assertLessThanOrEqual(100, $evaluation['scores'][1]['score']);
    }

    /** @return array<string, mixed> */
    private function artifact(string $id, string $slot, bool $valid, int $candidateOrder, string $phase): array
    {
        $shot = [
            'id' => $slot,
            'order' => 1,
            'start_seconds' => 0.0,
            'end_seconds' => 3.0,
            'purpose' => 'Hook',
            'visual' => 'A clear opening visual.',
        ];
        $body = match ($phase) {
            'ideas' => "## Emotional tension\n\nA familiar frustration becomes visible.\n\n## Product connection\n\nThe product resolves it.\n\n## What this tests\n\nA structural hypothesis.",
            'concepts' => "## Hook\n\nA legible opening moment.\n\n## Product mechanism\n\nThe mechanism is explicit.\n\n## Creative hypothesis\n\nA testable structure.\n\n## Risks or constraints\n\nKeep it readable.",
            'brief-script' => "## 15 second script\n\nA complete timed structural story that remains readable.\n\n## Shot intentions\n\nOne canonical shot.\n\n## Call to action\n\nAct now.",
            'images' => "## Visual\n\nA clear opening visual with enough substantive description for review.\n\n## Direction\n\nPortrait framing.\n\n## Generation prompt\n\nCreate the planned keyframe.",
            'scenes' => "## Motion\n\nUse one clear action with stable visual identity and restrained camera movement.\n\n## Duration\n\n0.0–3.0 seconds.",
            'sequence' => "## Exact timing\n\n0.0–3.0 seconds.\n\n## Captions\n\nKeep captions readable in the safe zone.\n\n## Editor notes\n\nUse a direct cut and preserve timing.",
            default => str_repeat('Readable simulation artifact. ', 5),
        };

        return [
            'id' => $id,
            'slot' => $slot,
            'body' => $body,
            'metadata' => [
                'schema_version' => 1,
                'simulation' => true,
                'source_artifact_ids' => ['source'],
                'candidate_order' => $candidateOrder,
                'shot' => $shot,
                'variant' => 'A',
                'shot_plan' => ['shots' => [$shot]],
                'scene_artifact_ids' => ['scene-1'],
            ],
            'validation' => [
                'valid' => $valid,
                'errors' => $valid ? [] : ['Invalid candidate.'],
            ],
        ];
    }
}
