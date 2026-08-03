<?php

namespace Tests\Feature;

use App\Creative\Automation\AutomationStateStore;
use App\Creative\Automation\CreativeRunOrchestrator;
use App\Creative\CreativeIntegrityAuditor;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\Rendering\RenderInputResolver;
use App\Creative\ShotPlanResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class CreativeDynamicShotLifecycleTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->creativeRoot = storage_path('framework/testing/creative-dynamic-shots-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
            'creative.access.mode' => 'local',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_a_preselected_four_shot_script_drives_the_complete_automatic_simulation_flow(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject(
            'Four shot automatic run',
            'Prove that automatic simulation follows the selected script instead of a fixed five-shot template.',
            'concepts',
        );
        $this->generateAndSelectFirst($repository, $target, 'concepts');
        $script = $this->generateAndSelectScriptWithShotCount($repository, $target, 4);

        $imageWorkspace = $repository->workspace($target['project'], $target['run'], 'images');
        $this->assertSame(['shot-01', 'shot-02', 'shot-03', 'shot-04'], $imageWorkspace['phase']['required_slots']);
        $this->assertSame(4, $imageWorkspace['phase']['required_count']);
        $this->assertSame(4, count($imageWorkspace['shots']));
        $this->assertSame(
            $script['metadata']['shot_plan']['shots'][3]['purpose'],
            $imageWorkspace['shots']['shot-04']['purpose'],
        );

        $state = app(CreativeRunOrchestrator::class)->start($target['project'], $target['run'], [
            'human_gates' => [],
        ]);

        $this->assertSame('complete', $state['status']);
        $this->assertSame(3, $state['total_attempts']);
        $this->assertSame(17, $state['simulation_units_spent']);

        $imageEvaluations = app(AutomationStateStore::class)->evaluations(
            $target['project'],
            $target['run'],
            'images',
        );
        $this->assertCount(1, $imageEvaluations);
        $this->assertSame(
            ['shot-01', 'shot-02', 'shot-03', 'shot-04'],
            $imageEvaluations[0]['required_slots'],
        );
        $this->assertSame(
            ['shot-01', 'shot-02', 'shot-03', 'shot-04'],
            array_keys($imageEvaluations[0]['recommendations']),
        );

        $images = $repository->workspace($target['project'], $target['run'], 'images');
        $scenes = $repository->workspace($target['project'], $target['run'], 'scenes');
        $sequence = $repository->workspace($target['project'], $target['run'], 'sequence');
        $selectedScenes = collect($scenes['artifacts'])
            ->where('selected', true)
            ->sortBy(fn (array $artifact): int => (int) $artifact['metadata']['shot']['order'])
            ->values();
        $selectedSequence = collect($sequence['artifacts'])->firstWhere('selected', true);

        $this->assertTrue($images['phase']['complete']);
        $this->assertSame(4, $images['phase']['selected_count']);
        $this->assertSame(8, count($images['artifacts']));
        $this->assertTrue($scenes['phase']['complete']);
        $this->assertSame(4, $scenes['phase']['selected_count']);
        $this->assertCount(4, $selectedScenes);
        $this->assertTrue($sequence['phase']['complete']);
        $this->assertIsArray($selectedSequence);
        $this->assertCount(4, $selectedSequence['metadata']['shot_plan']['shots']);
        $this->assertSame(
            $selectedScenes->pluck('id')->all(),
            $selectedSequence['metadata']['scene_artifact_ids'],
        );
        $this->assertSame(
            $selectedScenes->pluck('id')->all(),
            $selectedSequence['metadata']['source_artifact_ids'],
        );

        $renderInputs = app(RenderInputResolver::class)->resolve($target['project'], $target['run']);
        $this->assertCount(4, $renderInputs['shot_plan']['shots']);
        $this->assertCount(4, $renderInputs['scenes']);
        $this->assertSame(
            ['shot-01', 'shot-02', 'shot-03', 'shot-04'],
            collect($renderInputs['scenes'])->pluck('artifact.slot')->all(),
        );

        $base = "/projects/{$target['project']}/runs/{$target['run']}";
        $this->get("{$base}/images")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('creative/workspace')
                ->where('phase.required_count', 4)
                ->where('phase.required_slots', ['shot-01', 'shot-02', 'shot-03', 'shot-04'])
                ->has('shots', 4)
                ->where('shots.shot-04.id', 'shot-04')
                ->where('shots.shot-04.purpose', $script['metadata']['shot_plan']['shots'][3]['purpose'])
                ->where('shots.shot-04.visual', $script['metadata']['shot_plan']['shots'][3]['visual']));

        Http::assertNothingSent();
    }

    public function test_reselecting_from_five_shots_to_four_invalidates_descendants_then_recovers_with_append_only_evidence(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject(
            'Shrinking shot plan',
            'Prove that a script reselection updates dynamic requirements without erasing old creative history.',
            'concepts',
        );
        $this->generateAndSelectFirst($repository, $target, 'concepts');
        $scripts = $this->generateScriptsByShotCount($repository, $target);
        $repository->select($target['project'], $target['run'], 'brief-script', $scripts[5]['id']);

        $repository->simulate($target['project'], $target['run'], 'images', true);
        $oldImageIds = $this->selectFirstValidArtifactPerRequiredSlot($repository, $target, 'images');
        $repository->simulate($target['project'], $target['run'], 'scenes', true);
        $oldSceneIds = $this->selectFirstValidArtifactPerRequiredSlot($repository, $target, 'scenes');
        $repository->simulate($target['project'], $target['run'], 'sequence', true);
        $oldSequence = $repository->workspace($target['project'], $target['run'], 'sequence')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'sequence', $oldSequence['id']);

        $this->assertCount(5, $oldImageIds);
        $this->assertCount(5, $oldSceneIds);
        $this->assertTrue($repository->workspace($target['project'], $target['run'], 'sequence')['phase']['complete']);

        $repository->select($target['project'], $target['run'], 'brief-script', $scripts[4]['id']);

        $staleImages = $repository->workspace($target['project'], $target['run'], 'images');
        $staleScenes = $repository->workspace($target['project'], $target['run'], 'scenes');
        $staleSequence = $repository->workspace($target['project'], $target['run'], 'sequence');

        $this->assertSame(['shot-01', 'shot-02', 'shot-03', 'shot-04'], $staleImages['phase']['required_slots']);
        $this->assertSame(4, $staleImages['phase']['required_count']);
        $this->assertFalse($staleImages['phase']['complete']);
        $this->assertSame('stale', $staleImages['phase']['status']);
        $this->assertGreaterThanOrEqual(1, $staleImages['phase']['selection_issue_count']);
        $this->assertFalse($staleScenes['phase']['available']);
        $this->assertFalse($staleScenes['phase']['complete']);
        $this->assertSame('stale', $staleScenes['phase']['status']);
        $this->assertFalse($staleSequence['phase']['available']);
        $this->assertFalse($staleSequence['phase']['complete']);

        try {
            $repository->select(
                $target['project'],
                $target['run'],
                'images',
                $oldImageIds['shot-01'],
            );
            $this->fail('An image generated from the superseded five-shot script should not be selectable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Artifact cannot be selected', $exception->getMessage());
            $this->assertStringContainsString('sources do not match', $exception->getMessage());
        }

        $this->expectExceptionMessageForRenderInputs($target);

        $staleReport = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $this->assertTrue(collect($staleReport['issues'])->contains(
            fn (array $issue): bool => $issue['code'] === 'stale_selection'
                && $issue['phase'] === 'images',
        ));
        $this->assertTrue(collect($staleReport['issues'])->contains(
            fn (array $issue): bool => $issue['code'] === 'stale_artifact'
                && $issue['severity'] === 'warning'
                && in_array($issue['phase'], ['images', 'scenes', 'sequence'], true),
        ));
        $this->assertFalse(collect($staleReport['issues'])->contains(
            fn (array $issue): bool => $issue['code'] === 'artifact_attempt_manifest_invalid'
                && in_array($issue['phase'], ['images', 'scenes', 'sequence'], true),
        ));

        $repository->simulate($target['project'], $target['run'], 'images', true);
        $newImageIds = $this->selectFirstValidArtifactPerRequiredSlot($repository, $target, 'images');
        $repository->simulate($target['project'], $target['run'], 'scenes', true);
        $newSceneIds = $this->selectFirstValidArtifactPerRequiredSlot($repository, $target, 'scenes');
        $repository->simulate($target['project'], $target['run'], 'sequence', true);
        $sequenceWorkspace = $repository->workspace($target['project'], $target['run'], 'sequence');
        $newSequence = collect($sequenceWorkspace['artifacts'])
            ->first(fn (array $artifact): bool => $artifact['validation']['valid'] && ! $artifact['selected']);
        $this->assertIsArray($newSequence);
        $repository->select($target['project'], $target['run'], 'sequence', $newSequence['id']);

        $this->assertSame(['shot-01', 'shot-02', 'shot-03', 'shot-04'], array_keys($newImageIds));
        $this->assertSame(['shot-01', 'shot-02', 'shot-03', 'shot-04'], array_keys($newSceneIds));
        $this->assertTrue($repository->workspace($target['project'], $target['run'], 'images')['phase']['complete']);
        $this->assertTrue($repository->workspace($target['project'], $target['run'], 'scenes')['phase']['complete']);
        $this->assertTrue($repository->workspace($target['project'], $target['run'], 'sequence')['phase']['complete']);

        $renderInputs = app(RenderInputResolver::class)->resolve($target['project'], $target['run']);
        $this->assertCount(4, $renderInputs['scenes']);
        $this->assertSame(
            array_values($newSceneIds),
            collect($renderInputs['scenes'])->pluck('artifact.id')->all(),
        );
        $this->assertSame(array_values($newSceneIds), $renderInputs['sequence']['metadata']['scene_artifact_ids']);
        $this->assertNotContains($oldSceneIds['shot-05'], $renderInputs['sequence']['metadata']['scene_artifact_ids']);

        $recoveredReport = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $this->assertTrue(collect($recoveredReport['issues'])->contains(
            fn (array $issue): bool => $issue['code'] === 'stale_selection'
                && in_array($issue['entity'], [
                    $this->selectedRecordIdForArtifact($staleImages, $oldImageIds['shot-05']),
                    $this->selectedRecordIdForArtifact($staleScenes, $oldSceneIds['shot-05']),
                ], true),
        ));
        Http::assertNothingSent();
    }

    /** @param array{project: string, run: string, phase: string} $target */
    private function generateAndSelectFirst(
        CreativeWorkspaceRepository $repository,
        array $target,
        string $phase,
    ): array {
        $repository->simulate($target['project'], $target['run'], $phase, true);
        $artifact = $repository->workspace($target['project'], $target['run'], $phase)['artifacts'][0];
        $repository->select($target['project'], $target['run'], $phase, $artifact['id']);

        return $artifact;
    }

    /** @param array{project: string, run: string, phase: string} $target */
    private function generateAndSelectScriptWithShotCount(
        CreativeWorkspaceRepository $repository,
        array $target,
        int $shotCount,
    ): array {
        $scripts = $this->generateScriptsByShotCount($repository, $target);
        $this->assertArrayHasKey($shotCount, $scripts);
        $repository->select($target['project'], $target['run'], 'brief-script', $scripts[$shotCount]['id']);

        return $scripts[$shotCount];
    }

    /**
     * @param  array{project: string, run: string, phase: string}  $target
     * @return array<int, array<string, mixed>>
     */
    private function generateScriptsByShotCount(CreativeWorkspaceRepository $repository, array $target): array
    {
        $repository->simulate($target['project'], $target['run'], 'brief-script', true);
        $scripts = [];

        foreach ($repository->workspace($target['project'], $target['run'], 'brief-script')['artifacts'] as $artifact) {
            $resolution = app(ShotPlanResolver::class)->resolve($artifact);
            $this->assertTrue($resolution['valid']);
            $count = count($resolution['shot_plan']['shots']);
            $scripts[$count] ??= $artifact;
        }

        return $scripts;
    }

    /**
     * @param  array{project: string, run: string, phase: string}  $target
     * @return array<string, string>
     */
    private function selectFirstValidArtifactPerRequiredSlot(
        CreativeWorkspaceRepository $repository,
        array $target,
        string $phase,
    ): array {
        $workspace = $repository->workspace($target['project'], $target['run'], $phase);
        $selected = [];

        foreach ($workspace['phase']['required_slots'] as $slot) {
            $artifact = collect($workspace['artifacts'])->first(
                fn (array $candidate): bool => $candidate['slot'] === $slot
                    && $candidate['validation']['valid']
                    && ! $candidate['selected'],
            );
            $this->assertIsArray($artifact, "Expected a valid {$phase} artifact for {$slot}.");
            $repository->select($target['project'], $target['run'], $phase, $artifact['id']);
            $selected[$slot] = $artifact['id'];
        }

        return $selected;
    }

    /** @param array{project: string, run: string, phase: string} $target */
    private function expectExceptionMessageForRenderInputs(array $target): void
    {
        try {
            app(RenderInputResolver::class)->resolve($target['project'], $target['run']);
            $this->fail('Rendering should be blocked while the selected sequence is stale.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('valid selected sequence', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $workspace */
    private function selectedRecordIdForArtifact(array $workspace, string $artifactId): string
    {
        $artifact = collect($workspace['artifacts'])->firstWhere('id', $artifactId);
        $this->assertIsArray($artifact);
        $selection = $artifact['invalid_selection'] ?? $artifact['selection'] ?? null;
        $this->assertIsArray($selection);

        return (string) $selection['id'];
    }
}
