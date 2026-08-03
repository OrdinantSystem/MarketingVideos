<?php

namespace Tests\Unit;

use App\Creative\Generation\PhaseGenerationRequest;
use App\Creative\Generation\SimulationPhaseGenerator;
use App\Creative\ShotPlanResolver;
use Tests\TestCase;

class SimulationPhaseGeneratorTest extends TestCase
{
    public function test_brief_script_drafts_include_canonical_dynamic_shot_plans(): void
    {
        $generated = $this->generator()->generate(new PhaseGenerationRequest(
            phase: 'brief-script',
            inputs: [['id' => 'concept-1', 'body' => '# Concept']],
            feedback: '',
            requiredSlots: ['primary'],
        ));

        $this->assertSame('simulation', $generated['metadata']['generator']);
        $this->assertCount(3, $generated['drafts']);
        $this->assertSame([5, 4, 4], array_map(
            fn (array $draft): int => count($draft['metadata']['shot_plan']['shots']),
            $generated['drafts'],
        ));
        $this->assertTrue(collect($generated['drafts'])->every(
            fn (array $draft): bool => $draft['metadata']['shot_plan']['version'] === 1
                && $draft['metadata']['shot_plan']['total_duration_seconds'] === 15.0,
        ));
    }

    public function test_a_selected_four_shot_script_produces_eight_images_and_four_exact_scenes(): void
    {
        [$script, $slots] = $this->fourShotScript();
        $generator = $this->generator();

        $images = $generator->generate(new PhaseGenerationRequest('images', [$script], '', $slots));

        $this->assertCount(8, $images['drafts']);
        $this->assertEqualsCanonicalizing($slots, collect($images['drafts'])->pluck('slot')->unique()->values()->all());
        foreach ($slots as $slot) {
            $slotDrafts = collect($images['drafts'])->where('slot', $slot)->values();
            $this->assertCount(2, $slotDrafts);
            $this->assertSame(['A', 'B'], $slotDrafts->pluck('metadata.variant')->all());
            $this->assertTrue($slotDrafts->every(
                fn (array $draft): bool => $draft['metadata']['shot']['id'] === $slot,
            ));
        }

        $selectedImages = array_values(collect($images['drafts'])
            ->where('metadata.variant', 'A')
            ->values()
            ->map(fn (array $draft): array => $this->artifactFromDraft($draft, 'image-'.$draft['slot']))
            ->all());
        $scenes = $generator->generate(new PhaseGenerationRequest('scenes', $selectedImages, '', $slots));

        $this->assertCount(4, $scenes['drafts']);
        foreach ($scenes['drafts'] as $index => $scene) {
            $slot = $slots[$index];
            $this->assertSame($slot, $scene['slot']);
            $this->assertSame(['image-'.$slot], $scene['metadata']['source_artifact_ids']);
            $this->assertSame($selectedImages[$index]['metadata']['shot'], $scene['metadata']['shot']);
        }
    }

    public function test_sequence_order_and_timing_come_from_the_four_selected_scenes(): void
    {
        [$script, $slots] = $this->fourShotScript();
        $generator = $this->generator();
        $images = $generator->generate(new PhaseGenerationRequest('images', [$script], '', $slots));
        $selectedImages = array_values(collect($images['drafts'])
            ->where('metadata.variant', 'A')
            ->values()
            ->map(fn (array $draft): array => $this->artifactFromDraft($draft, 'image-'.$draft['slot']))
            ->all());
        $scenes = $generator->generate(new PhaseGenerationRequest('scenes', $selectedImages, '', $slots));
        $selectedScenes = array_values(collect($scenes['drafts'])
            ->map(fn (array $draft): array => $this->artifactFromDraft($draft, 'scene-'.$draft['slot']))
            ->reverse()
            ->values()
            ->all());

        $sequence = $generator->generate(new PhaseGenerationRequest('sequence', $selectedScenes, '', ['primary']));

        $this->assertCount(3, $sequence['drafts']);
        foreach ($sequence['drafts'] as $draft) {
            $this->assertSame(
                ['scene-shot-01', 'scene-shot-02', 'scene-shot-03', 'scene-shot-04'],
                $draft['metadata']['scene_artifact_ids'],
            );
            $this->assertCount(4, $draft['metadata']['shot_plan']['shots']);
            $this->assertStringContainsString('0.0–3.0s · `shot-01` · Hook', $draft['body']);
            $this->assertStringContainsString('3.0–7.0s · `shot-02` · Product', $draft['body']);
            $this->assertStringContainsString('12.0–15.0s · `shot-04` · CTA', $draft['body']);
            $this->assertStringContainsString('15.0 seconds.', $draft['body']);
            $this->assertStringNotContainsString('Five selected scenes', $draft['body']);
        }
    }

    public function test_retry_context_is_recorded_and_materially_changes_simulation_candidates(): void
    {
        $initial = $this->generator()->generate(new PhaseGenerationRequest(
            'concepts',
            [['id' => 'project-1', 'body' => '# Project']],
            '',
            ['primary'],
        ));
        $retried = $this->generator()->generate(new PhaseGenerationRequest(
            'concepts',
            [['id' => 'project-1', 'body' => '# Project']],
            "Make the opening demonstrate the product directly.\n\n[automatic-retry-context]\nPrior evaluation: evaluation-concepts--one\nDiagnosis: Make the product mechanism explicit.",
            ['primary'],
        ));

        $this->assertFalse($initial['drafts'][0]['metadata']['retry_context_applied']);
        $this->assertTrue($retried['drafts'][0]['metadata']['retry_context_applied']);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $retried['drafts'][0]['metadata']['retry_context_sha256'],
        );
        $this->assertSame(
            'Make the product mechanism explicit.',
            $retried['drafts'][0]['metadata']['retry_diagnosis'],
        );
        $this->assertSame(
            hash('sha256', 'Make the product mechanism explicit.'),
            $retried['drafts'][0]['metadata']['retry_diagnosis_sha256'],
        );
        $this->assertSame(
            hash('sha256', 'Make the opening demonstrate the product directly.'),
            $retried['drafts'][0]['metadata']['retry_feedback_sha256'],
        );
        $this->assertSame(
            'Make the product mechanism explicit in the concept beat.',
            $retried['drafts'][0]['metadata']['retry_refinement_action'],
        );
        $this->assertStringNotContainsString('Automatic refinement', $initial['drafts'][0]['body']);
        $this->assertStringContainsString('Automatic refinement', $retried['drafts'][0]['body']);
        $this->assertStringContainsString('Make the product mechanism explicit.', $retried['drafts'][0]['body']);
        $this->assertStringContainsString('Make the opening demonstrate the product directly.', $retried['drafts'][0]['body']);
        $this->assertStringContainsString('not a creative-quality judgment', $retried['drafts'][0]['body']);
    }

    public function test_diagnosis_and_feedback_produce_distinct_deterministic_refinement_evidence(): void
    {
        $request = fn (string $feedback): PhaseGenerationRequest => new PhaseGenerationRequest(
            'concepts',
            [['id' => 'project-1', 'body' => '# Project']],
            $feedback,
            ['primary'],
        );
        $firstFeedback = "Clarify the product demonstration.\n\n[automatic-retry-context]\nPrior evaluation: evaluation-one\nDiagnosis: The product mechanism is unclear.";
        $secondFeedback = "Strengthen the first visual beat.\n\n[automatic-retry-context]\nPrior evaluation: evaluation-two\nDiagnosis: The opening hook is unclear.";
        $first = $this->generator()->generate($request($firstFeedback));
        $firstAgain = $this->generator()->generate($request($firstFeedback));
        $second = $this->generator()->generate($request($secondFeedback));

        $this->assertSame(
            $first['drafts'][0]['metadata']['retry_refinement_sha256'],
            $firstAgain['drafts'][0]['metadata']['retry_refinement_sha256'],
        );
        $this->assertSame($first['drafts'][0]['body'], $firstAgain['drafts'][0]['body']);
        $this->assertNotSame(
            $first['drafts'][0]['metadata']['retry_refinement_sha256'],
            $second['drafts'][0]['metadata']['retry_refinement_sha256'],
        );
        $this->assertNotSame(
            $first['drafts'][0]['metadata']['retry_diagnosis_sha256'],
            $second['drafts'][0]['metadata']['retry_diagnosis_sha256'],
        );
        $this->assertNotSame($first['drafts'][0]['body'], $second['drafts'][0]['body']);
    }

    /** @return array{array<string, mixed>, list<string>} */
    private function fourShotScript(): array
    {
        $brief = $this->generator()->generate(new PhaseGenerationRequest(
            'brief-script',
            [['id' => 'concept-1', 'body' => '# Concept']],
            '',
            ['primary'],
        ));
        $draft = collect($brief['drafts'])->firstWhere('title', 'Delete the small talk');
        $this->assertIsArray($draft);
        $script = $this->artifactFromDraft($draft, 'script-four');
        $slots = array_column($draft['metadata']['shot_plan']['shots'], 'id');

        return [$script, $slots];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function artifactFromDraft(array $draft, string $id): array
    {
        return $draft + ['id' => $id];
    }

    private function generator(): SimulationPhaseGenerator
    {
        return new SimulationPhaseGenerator(new ShotPlanResolver);
    }
}
