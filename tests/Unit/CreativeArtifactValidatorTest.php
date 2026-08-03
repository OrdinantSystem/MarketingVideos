<?php

namespace Tests\Unit;

use App\Creative\CreativeArtifactEligibilityVerifier;
use App\Creative\CreativeArtifactValidator;
use App\Creative\EffectiveSelectionResolver;
use App\Creative\MarkdownDocument;
use App\Creative\MediaAssetContract;
use App\Creative\ShotPlanResolver;
use Tests\TestCase;

class CreativeArtifactValidatorTest extends TestCase
{
    public function test_a_structured_brief_script_shot_plan_is_valid(): void
    {
        $result = $this->validator()->validate(
            $this->briefScriptArtifact($this->shotPlan()),
            'brief-script',
            ['concept-1'],
            ['primary'],
        );

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_a_schema_one_script_without_structured_metadata_is_invalid(): void
    {
        $artifact = $this->briefScriptArtifact();

        $result = $this->validator()->validate($artifact, 'brief-script', ['concept-1'], ['primary']);

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'Schema-v1 brief/script artifact requires a structured shot_plan in front matter.',
            $result['errors'],
        );
    }

    public function test_an_unversioned_script_may_derive_legacy_timing_with_a_warning(): void
    {
        $artifact = $this->briefScriptArtifact();
        unset($artifact['metadata']['schema_version']);

        $result = $this->validator()->validate($artifact, 'brief-script', ['concept-1'], ['primary']);

        $this->assertTrue($result['valid']);
        $this->assertTrue(collect($result['warnings'])->contains(
            fn (string $warning): bool => str_contains($warning, 'legacy timed Markdown'),
        ));
    }

    public function test_a_string_schema_version_cannot_bypass_the_strict_script_contract(): void
    {
        $artifact = $this->briefScriptArtifact();
        $artifact['metadata']['schema_version'] = '1';

        $result = $this->validator()->validate($artifact, 'brief-script', ['concept-1'], ['primary']);

        $this->assertFalse($result['valid']);
        $this->assertContains('Artifact schema_version must be an integer.', $result['errors']);
        $this->assertFalse(collect($result['warnings'])->contains(
            fn (string $warning): bool => str_contains($warning, 'Legacy artifact'),
        ));
    }

    public function test_a_structured_script_plan_must_match_the_human_readable_timing(): void
    {
        $plan = $this->shotPlan();
        $plan['shots'][1]['purpose'] = 'Contradictory payoff';
        $artifact = $this->briefScriptArtifact($plan);

        $result = $this->validator()->validate($artifact, 'brief-script', ['concept-1'], ['primary']);

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'Structured shot_plan does not match the human-readable timed script.',
            $result['errors'],
        );
    }

    public function test_a_brief_script_without_a_valid_shot_plan_cannot_be_selected(): void
    {
        $artifact = $this->briefScriptArtifact();
        $artifact['body'] = str_replace(
            "**0.0–2.0s — Hook** A clear opening.\n\n**2.0–5.0s — Payoff** A human response.",
            'The timing was omitted.',
            $artifact['body'],
        );
        $artifact['sections'] = MarkdownDocument::sections($artifact['body']);

        $result = $this->validator()->validate($artifact, 'brief-script', ['concept-1'], ['primary']);

        $this->assertFalse($result['valid']);
        $this->assertTrue(collect($result['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'bold timed shot entry'),
        ));
    }

    public function test_dynamic_media_slots_are_checked_against_the_effective_plan(): void
    {
        $artifact = $this->imageArtifact('shot-01');

        $accepted = $this->validator()->validate($artifact, 'images', ['script-1'], ['shot-01', 'shot-02']);
        $rejected = $this->validator()->validate($artifact, 'images', ['script-1'], ['shot-02']);

        $this->assertTrue($accepted['valid']);
        $this->assertFalse($rejected['valid']);
        $this->assertContains('Artifact slot is not required by the effective phase plan.', $rejected['errors']);
    }

    public function test_image_shot_snapshot_must_match_the_selected_script_plan(): void
    {
        $expectedShot = $this->shotPlan()['shots'][0];
        $artifact = $this->imageArtifact('shot-01');

        $valid = $this->validator()->validate($artifact, 'images', ['script-1'], ['shot-01'], $expectedShot);
        $artifact['metadata']['shot']['end_seconds'] = 2.5;
        $altered = $this->validator()->validate($artifact, 'images', ['script-1'], ['shot-01'], $expectedShot);

        $this->assertTrue($valid['valid']);
        $this->assertFalse($altered['valid']);
        $this->assertContains(
            'Media shot snapshot does not match the selected script shot plan: end_seconds.',
            $altered['errors'],
        );
    }

    public function test_scene_shot_snapshot_must_match_the_selected_script_plan(): void
    {
        $expectedShot = $this->shotPlan()['shots'][1];
        $artifact = $this->sceneArtifact('shot-02');

        $valid = $this->validator()->validate($artifact, 'scenes', ['image-2'], ['shot-02'], $expectedShot);
        $artifact['metadata']['shot']['visual'] = 'A substituted visual.';
        $altered = $this->validator()->validate($artifact, 'scenes', ['image-2'], ['shot-02'], $expectedShot);

        $this->assertTrue($valid['valid']);
        $this->assertFalse($altered['valid']);
        $this->assertContains(
            'Media shot snapshot does not match the selected script shot plan: visual.',
            $altered['errors'],
        );
    }

    public function test_media_artifact_without_a_canonical_shot_snapshot_is_invalid(): void
    {
        $artifact = $this->imageArtifact('shot-01');
        unset($artifact['metadata']['shot']);

        $result = $this->validator()->validate($artifact, 'images', ['script-1'], ['shot-01']);

        $this->assertFalse($result['valid']);
        $this->assertContains('Media artifact requires a canonical shot metadata snapshot.', $result['errors']);
    }

    public function test_absent_simulation_media_requires_an_explicit_simulated_state_without_binary_metadata(): void
    {
        $artifact = $this->imageArtifact('shot-01');

        $this->assertTrue($this->validator()->validate(
            $artifact,
            'images',
            ['script-1'],
            ['shot-01'],
        )['valid']);

        $artifact['metadata']['status'] = 'ready';
        $artifact['metadata']['asset_sha256'] = str_repeat('a', 64);
        $invalid = $this->validator()->validate($artifact, 'images', ['script-1'], ['shot-01']);

        $this->assertFalse($invalid['valid']);
        $this->assertContains(
            'An absent_simulation asset is eligible only for a deliberate simulated artifact.',
            $invalid['errors'],
        );
        $this->assertContains(
            'An absent_simulation asset must not declare asset_sha256.',
            $invalid['errors'],
        );
    }

    public function test_unknown_or_failure_media_states_are_invalid(): void
    {
        foreach (['missing', 'pending', 'running', 'failed', 'cancelled', 'timed_out', 'unknown'] as $status) {
            $artifact = $this->imageArtifact('shot-01');
            $artifact['metadata']['asset_status'] = $status;

            $result = $this->validator()->validate($artifact, 'images', ['script-1'], ['shot-01']);

            $this->assertFalse($result['valid'], "Expected {$status} to be invalid.");
            $this->assertContains('Media asset_status must be ready or absent_simulation.', $result['errors']);
        }
    }

    public function test_ready_media_requires_hash_size_and_phase_specific_probe_metadata(): void
    {
        $image = $this->imageArtifact('shot-01');
        $image['metadata'] = array_merge($image['metadata'], [
            'status' => 'ready',
            'asset_status' => 'ready',
            'asset_sha256' => str_repeat('a', 64),
            'asset_bytes' => 128,
            'media_probe' => [
                'media_type' => 'image',
                'mime_type' => 'image/png',
                'codec' => 'png',
                'width' => 1080,
                'height' => 1920,
            ],
        ]);

        $this->assertTrue($this->validator()->validate(
            $image,
            'images',
            ['script-1'],
            ['shot-01'],
        )['valid']);

        $video = $this->sceneArtifact('shot-02');
        $video['metadata'] = array_merge($video['metadata'], [
            'status' => 'ready',
            'asset_status' => 'ready',
            'asset_sha256' => str_repeat('b', 64),
            'asset_bytes' => 256,
            'media_probe' => [
                'media_type' => 'video',
                'mime_type' => 'video/mp4',
                'codec' => 'h264',
                'width' => 1080,
                'height' => 1920,
            ],
        ]);
        $invalid = $this->validator()->validate($video, 'scenes', ['image-2'], ['shot-02']);

        $this->assertFalse($invalid['valid']);
        $this->assertContains('Video media probe requires a positive duration_seconds.', $invalid['errors']);

        $video['metadata']['media_probe']['duration_seconds'] = 3.0;
        $this->assertTrue($this->validator()->validate(
            $video,
            'scenes',
            ['image-2'],
            ['shot-02'],
        )['valid']);

        $video['metadata']['media_probe']['duration_seconds'] = 3.12;
        $this->assertTrue(
            $this->validator()->validate($video, 'scenes', ['image-2'], ['shot-02'])['valid'],
            'One-frame container duration rounding should remain eligible.',
        );

        $video['metadata']['media_probe']['duration_seconds'] = 3.13;
        $mismatched = $this->validator()->validate($video, 'scenes', ['image-2'], ['shot-02']);
        $this->assertFalse($mismatched['valid']);
        $this->assertContains(
            'Video media probe duration_seconds must match the shot duration within 0.125 seconds.',
            $mismatched['errors'],
        );
    }

    public function test_no_required_slots_can_never_form_a_complete_phase(): void
    {
        $resolver = new EffectiveSelectionResolver(
            $this->validator(),
            app(CreativeArtifactEligibilityVerifier::class),
        );

        $state = $resolver->resolve('images', [], [], [], [], true);

        $this->assertFalse($state['complete']);
    }

    public function test_an_altered_shot_snapshot_cannot_form_an_effective_selection(): void
    {
        $artifact = $this->imageArtifact('shot-01') + ['path' => 'project/run/03-images/outputs/image-1.md'];
        $artifact['metadata']['shot']['purpose'] = 'Altered purpose';
        $selection = [
            'id' => 'selection-1',
            'type' => 'selection',
            'phase' => 'images',
            'slot' => 'shot-01',
            'artifact_id' => 'image-1',
            'artifact_path' => $artifact['path'],
            'created_at' => '2026-07-28T12:01:00+00:00',
        ];
        $resolver = new EffectiveSelectionResolver(
            $this->validator(),
            app(CreativeArtifactEligibilityVerifier::class),
        );

        $state = $resolver->resolve(
            'images',
            [$selection],
            [$artifact],
            ['shot-01'],
            ['shot-01' => ['script-1']],
            true,
            ['shot-01' => $this->shotPlan()['shots'][0]],
        );

        $this->assertFalse($state['complete']);
        $this->assertSame([], $state['resolved']);
        $this->assertContains(
            'Media shot snapshot does not match the selected script shot plan: purpose.',
            $state['invalid'][0]['errors'],
        );
    }

    public function test_sequence_requires_exact_ordered_scene_lineage_and_current_shot_plan(): void
    {
        $artifact = $this->sequenceArtifact();

        $valid = $this->validator()->validate(
            $artifact,
            'sequence',
            ['scene-1', 'scene-2'],
            ['primary'],
            null,
            $this->shotPlan(),
        );
        $this->assertTrue($valid['valid']);

        $artifact['body'] = str_replace('`scene-2`', '`different-scene`', $artifact['body']);
        $artifact['sections'] = MarkdownDocument::sections($artifact['body']);
        $misleadingBody = $this->validator()->validate(
            $artifact,
            'sequence',
            ['scene-1', 'scene-2'],
            ['primary'],
            null,
            $this->shotPlan(),
        );
        $this->assertFalse($misleadingBody['valid']);
        $this->assertContains(
            'Sequence body Scene order does not exactly match the ordered scene manifest.',
            $misleadingBody['errors'],
        );

        $artifact = $this->sequenceArtifact();
        $artifact['metadata']['source_artifact_ids'] = ['scene-2', 'scene-1'];
        $wrongOrder = $this->validator()->validate(
            $artifact,
            'sequence',
            ['scene-1', 'scene-2'],
            ['primary'],
            null,
            $this->shotPlan(),
        );
        $this->assertFalse($wrongOrder['valid']);
        $this->assertContains(
            'Sequence source_artifact_ids must exactly equal ordered scene_artifact_ids.',
            $wrongOrder['errors'],
        );

        $artifact = $this->sequenceArtifact();
        $differentPlan = $this->shotPlan();
        $differentPlan['shots'][1]['visual'] = 'The current scene now proves a different visual.';
        $stale = $this->validator()->validate(
            $artifact,
            'sequence',
            ['scene-1', 'scene-2'],
            ['primary'],
            null,
            $differentPlan,
        );
        $this->assertFalse($stale['valid']);
        $this->assertContains(
            'Sequence shot_plan does not match the current selected scene snapshots.',
            $stale['errors'],
        );
    }

    public function test_batch_contract_requires_three_unique_primary_text_candidates(): void
    {
        $batch = [
            $this->batchArtifact('one', 'primary', 1, 'First'),
            $this->batchArtifact('two', 'primary', 2, 'Second'),
            $this->batchArtifact('three', 'primary', 3, 'Third'),
        ];

        $this->assertTrue($this->validator()->validateBatch($batch, 'sequence', ['primary'])['valid']);

        $batch[2]['metadata']['title'] = $batch[1]['metadata']['title'];
        $batch[2]['metadata']['summary'] = $batch[1]['metadata']['summary'];
        $batch[2]['body'] = $batch[1]['body'];
        $invalid = $this->validator()->validateBatch($batch, 'sequence', ['primary']);

        $this->assertFalse($invalid['valid']);
        $this->assertContains(
            'Generated sequence batch must contain three unique primary candidates.',
            $invalid['errors'],
        );
    }

    public function test_batch_contract_requires_images_a_and_b_and_one_scene_per_shot(): void
    {
        $images = [
            $this->batchArtifact('image-1-a', 'shot-01', 1, '1A', 'A'),
            $this->batchArtifact('image-1-b', 'shot-01', 2, '1B', 'B'),
            $this->batchArtifact('image-2-a', 'shot-02', 3, '2A', 'A'),
            $this->batchArtifact('image-2-b', 'shot-02', 4, '2B', 'B'),
        ];
        $this->assertTrue($this->validator()->validateBatch(
            $images,
            'images',
            ['shot-01', 'shot-02'],
        )['valid']);

        $images[3]['metadata']['variant'] = 'A';
        $invalidImages = $this->validator()->validateBatch($images, 'images', ['shot-01', 'shot-02']);
        $this->assertFalse($invalidImages['valid']);
        $this->assertContains(
            'Generated images batch must contain exactly variants A and B for shot-02.',
            $invalidImages['errors'],
        );

        $scenes = [
            $this->batchArtifact('scene-1', 'shot-01', 1, 'Scene 1'),
            $this->batchArtifact('scene-2', 'shot-02', 2, 'Scene 2'),
        ];
        $this->assertTrue($this->validator()->validateBatch(
            $scenes,
            'scenes',
            ['shot-01', 'shot-02'],
        )['valid']);

        $scenes[1]['metadata']['slot'] = 'shot-01';
        $invalidScenes = $this->validator()->validateBatch($scenes, 'scenes', ['shot-01', 'shot-02']);
        $this->assertFalse($invalidScenes['valid']);
        $this->assertContains(
            'Generated scenes batch must contain exactly one candidate for shot-02.',
            $invalidScenes['errors'],
        );
    }

    private function validator(): CreativeArtifactValidator
    {
        return new CreativeArtifactValidator(new ShotPlanResolver, new MediaAssetContract);
    }

    /**
     * @param  array<string, mixed>|null  $shotPlan
     * @return array<string, mixed>
     */
    private function briefScriptArtifact(?array $shotPlan = null): array
    {
        $metadata = $this->baseMetadata('script-1', 'brief-script', 'markdown', 'primary', ['concept-1']);
        if ($shotPlan !== null) {
            $metadata['shot_plan'] = $shotPlan;
        }

        $body = <<<'MARKDOWN'
# Test script

## Purpose

Prove a dynamic plan.

## Audience

Local test viewers.

## Story

A short deterministic story.

## Tone and constraints

Clear and under fifteen seconds.

## 15-second script

**0.0–2.0s — Hook** A clear opening.

**2.0–5.0s — Payoff** A human response.

## Shot intentions

Use two readable shots.

## Call to action

Continue.
MARKDOWN;

        return [
            'metadata' => $metadata,
            'body' => $body,
            'sections' => MarkdownDocument::sections($body),
            'id' => 'script-1',
            'slot' => 'primary',
            'filename' => 'script-1.md',
        ];
    }

    /** @return array<string, mixed> */
    private function imageArtifact(string $slot): array
    {
        $metadata = array_merge(
            $this->baseMetadata('image-1', 'images', 'image_sidecar', $slot, ['script-1']),
            [
                'variant' => 'A',
                'asset_path' => 'assets/image-1.png',
                'asset_status' => 'absent_simulation',
                'shot' => $this->shotForSlot($slot),
            ],
        );
        $body = "# Image\n\n## Visual\n\nA visual.\n\n## Direction\n\nPortrait.\n\n## Generation prompt\n\nCreate it.\n\n## Asset status\n\nSimulation only.";

        return [
            'metadata' => $metadata,
            'body' => $body,
            'sections' => MarkdownDocument::sections($body),
            'id' => 'image-1',
            'slot' => $slot,
            'filename' => 'image-1.md',
            'asset_status' => 'absent_simulation',
        ];
    }

    /** @return array<string, mixed> */
    private function sceneArtifact(string $slot): array
    {
        $metadata = array_merge(
            $this->baseMetadata('scene-2', 'scenes', 'video_sidecar', $slot, ['image-2']),
            [
                'asset_path' => 'assets/scene-2.mp4',
                'asset_status' => 'absent_simulation',
                'shot' => $this->shotForSlot($slot),
            ],
        );
        $body = "# Scene\n\n## Motion\n\nA motion study.\n\n## Duration\n\n2.0–5.0 seconds.\n\n## Asset status\n\nSimulation only.";

        return [
            'metadata' => $metadata,
            'body' => $body,
            'sections' => MarkdownDocument::sections($body),
            'id' => 'scene-2',
            'slot' => $slot,
            'filename' => 'scene-2.md',
            'asset_status' => 'absent_simulation',
        ];
    }

    /** @return array<string, mixed> */
    private function sequenceArtifact(): array
    {
        $metadata = array_merge(
            $this->baseMetadata(
                'sequence-1',
                'sequence',
                'markdown',
                'primary',
                ['scene-1', 'scene-2'],
            ),
            [
                'shot_plan' => $this->shotPlan(),
                'scene_artifact_ids' => ['scene-1', 'scene-2'],
            ],
        );
        $body = <<<'MARKDOWN'
# Sequence

## Scene order

1. `shot-01` — Hook — `scene-1`
2. `shot-02` — Payoff — `scene-2`

## Exact timing

- 0.0–2.0s · `shot-01` · Hook
- 2.0–5.0s · `shot-02` · Payoff

## Captions

Readable captions.

## Voice timing

Inside the approved shots.

## Sound

One restrained cue.

## Transitions

Direct cuts.

## Aspect ratio

9:16.

## Total duration

5.0 seconds.

## Editor notes

Preserve the selected order.
MARKDOWN;

        return [
            'metadata' => $metadata,
            'body' => $body,
            'sections' => MarkdownDocument::sections($body),
            'id' => 'sequence-1',
            'slot' => 'primary',
            'filename' => 'sequence-1.md',
        ];
    }

    /** @return array<string, mixed> */
    private function batchArtifact(
        string $id,
        string $slot,
        int $candidateOrder,
        string $label,
        ?string $variant = null,
    ): array {
        $metadata = [
            'id' => $id,
            'artifact_type' => 'markdown',
            'slot' => $slot,
            'candidate_order' => $candidateOrder,
            'title' => $label,
            'summary' => "{$label} summary",
        ];
        if ($variant !== null) {
            $metadata['variant'] = $variant;
        }

        return [
            'id' => $id,
            'metadata' => $metadata,
            'body' => "# {$label}",
        ];
    }

    /** @return array<string, mixed> */
    private function shotForSlot(string $slot): array
    {
        foreach ($this->shotPlan()['shots'] as $shot) {
            if ($shot['id'] === $slot) {
                return $shot;
            }
        }

        throw new \RuntimeException("Unknown test shot slot: {$slot}");
    }

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, mixed>
     */
    private function baseMetadata(
        string $id,
        string $phase,
        string $artifactType,
        string $slot,
        array $sourceIds,
    ): array {
        return [
            'schema_version' => 1,
            'generation_attempt_id' => 'attempt-1',
            'generator' => 'simulation',
            'prompt_version' => $phase.'-simulation-v1',
            'source_artifact_ids' => $sourceIds,
            'settings' => ['driver' => 'simulation'],
            'usage' => [],
            'cost_usd' => 0.0,
            'status' => in_array($phase, ['images', 'scenes'], true) ? 'simulated' : 'ready',
            'simulation' => true,
            'id' => $id,
            'artifact_type' => $artifactType,
            'phase' => $phase,
            'slot' => $slot,
            'title' => 'Test artifact',
            'summary' => 'A deterministic artifact.',
            'created_at' => '2026-07-28T12:00:00+00:00',
        ];
    }

    /** @return array{version: int, total_duration_seconds: float, shots: list<array{id: string, order: int, start_seconds: float, end_seconds: float, purpose: string, visual: string}>} */
    private function shotPlan(): array
    {
        return [
            'version' => 1,
            'total_duration_seconds' => 5.0,
            'shots' => [
                [
                    'id' => 'shot-01',
                    'order' => 1,
                    'start_seconds' => 0.0,
                    'end_seconds' => 2.0,
                    'purpose' => 'Hook',
                    'visual' => 'A clear opening.',
                ],
                [
                    'id' => 'shot-02',
                    'order' => 2,
                    'start_seconds' => 2.0,
                    'end_seconds' => 5.0,
                    'purpose' => 'Payoff',
                    'visual' => 'A human response.',
                ],
            ],
        ];
    }
}
