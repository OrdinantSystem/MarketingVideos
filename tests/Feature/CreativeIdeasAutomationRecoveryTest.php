<?php

namespace Tests\Feature;

use App\Creative\Automation\AutomationPolicy;
use App\Creative\Automation\AutomationStateStore;
use App\Creative\Automation\CreativeRunOrchestrator;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\FilesystemCreativeStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreativeIdeasAutomationRecoveryTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-ideas-automation-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_resume_backfills_the_exact_source_decision_for_a_partial_automatic_ideas_selection(): void
    {
        $target = $this->project('Interrupted automatic Ideas promotion');
        $states = app(AutomationStateStore::class);
        $states->start(
            $target['project'],
            $target['run'],
            $states->newId('automation'),
            app(AutomationPolicy::class)->make('ideas', [
                'human_gates' => ['concepts'],
                'score_threshold' => 0,
            ]),
        );
        $orchestrator = app(CreativeRunOrchestrator::class);

        $generated = $orchestrator->step($target['project'], $target['run']);
        $this->assertSame('evaluating', $generated['step']);
        $evaluated = $orchestrator->step($target['project'], $target['run']);
        $this->assertSame('selecting', $evaluated['step']);

        $evaluationId = $evaluated['latest_evaluation_id'];
        $this->assertIsString($evaluationId);
        $evaluation = $states->evaluation(
            $target['project'],
            $target['run'],
            'ideas',
            $evaluationId,
        );
        $artifactId = $evaluation['recommendations']['primary'];
        $this->assertIsString($artifactId);
        $workspace = app(CreativeWorkspaceRepository::class);
        $artifact = collect($workspace->workspace(
            $target['project'],
            $target['run'],
            'ideas',
        )['artifacts'])->firstWhere('id', $artifactId);
        $this->assertIsArray($artifact);

        $selectionId = 'selection-interrupted-ideas-promotion';
        app(FilesystemCreativeStore::class)->appendSelection(
            $target['project'],
            $target['run'],
            'ideas',
            'primary',
            $selectionId,
            [
                'id' => $selectionId,
                'type' => 'selection',
                'phase' => 'ideas',
                'slot' => 'primary',
                'artifact_id' => $artifactId,
                'artifact_path' => $artifact['path'],
                'selected_by' => 'automation',
                'evaluation_id' => $evaluationId,
                'reason' => 'The process stopped after the selection write and before source promotion.',
                'created_at' => now('UTC')->format('Y-m-d\TH:i:s.uP'),
            ],
            '# Interrupted automatic Ideas selection',
        );

        $sourceBeforeRecovery = $workspace->effectiveIdeaSource($target['project'], $target['run']);
        $this->assertSame('selected_idea', $sourceBeforeRecovery['mode']);
        $this->assertSame($artifactId, $sourceBeforeRecovery['source_id']);
        $this->assertFalse($sourceBeforeRecovery['explicit']);
        $this->assertFalse($workspace->automationPhaseState(
            $target['project'],
            $target['run'],
            'ideas',
        )['complete']);
        $this->assertFalse($workspace->phaseContext(
            $target['project'],
            $target['run'],
            'concepts',
        )['ready']);

        $resumed = $orchestrator->resume($target['project'], $target['run']);

        $this->assertSame('awaiting_review', $resumed['status']);
        $this->assertSame('concepts', $resumed['gate_phase']);
        $source = $workspace->effectiveIdeaSource($target['project'], $target['run']);
        $this->assertTrue($source['explicit']);
        $this->assertSame($artifactId, $source['source_id']);
        $this->assertSame($artifactId, $source['decision']['artifact_id']);
        $this->assertSame($selectionId, $source['decision']['selection_id']);
        $this->assertSame('automation', $source['decision']['selected_by']);

        $matchingDecisions = collect(app(FilesystemCreativeStore::class)
            ->ideaSourceDecisions($target['project'], $target['run']))
            ->where('mode', 'selected_idea')
            ->where('artifact_id', $artifactId)
            ->where('selection_id', $selectionId)
            ->values();
        $this->assertCount(1, $matchingDecisions);
        $conceptArtifacts = $workspace->workspace(
            $target['project'],
            $target['run'],
            'concepts',
        )['artifacts'];
        $this->assertCount(3, $conceptArtifacts);
        $this->assertTrue(collect($conceptArtifacts)->every(
            fn (array $candidate): bool => ($candidate['metadata']['source_artifact_ids'] ?? null) === [$artifactId],
        ));
        $this->assertCount(1, collect($states->events($target['project'], $target['run']))
            ->where('event', 'selection_recorded')
            ->where('phase', 'ideas'));
        Http::assertNothingSent();
    }

    public function test_skip_after_the_automatic_ideas_review_rebinds_a_fresh_gate_to_the_project_source(): void
    {
        $target = $this->project('Skip after automatic Ideas review');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $workspace = app(CreativeWorkspaceRepository::class);
        $paused = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['ideas', 'concepts'],
            'score_threshold' => 0,
        ]);

        $this->assertSame('awaiting_review', $paused['status']);
        $this->assertSame('ideas', $paused['gate_phase']);
        $oldToken = $this->gateToken($orchestrator, $target);
        $oldSource = $workspace->effectiveIdeaSource($target['project'], $target['run']);
        $this->assertSame('selected_idea', $oldSource['mode']);
        $this->assertTrue($oldSource['explicit']);

        $workspace->skipIdeas(
            $target['project'],
            $target['run'],
            reason: 'Use the broad project idea after reviewing the automatic Ideas recommendation.',
        );

        $reopened = $orchestrator->status($target['project'], $target['run']);
        $this->assertNotNull($reopened);
        $this->assertSame('pending', $reopened['state']['status']);
        $this->assertSame('repair', $reopened['state']['step']);
        $this->assertSame('ideas', $reopened['state']['current_phase']);
        $this->assertNotEmpty($reopened['projection_issues']);

        $rebound = $orchestrator->resume($target['project'], $target['run']);
        $this->assertSame('awaiting_review', $rebound['status']);
        $this->assertSame('human_action', $rebound['step']);
        $this->assertSame('ideas', $rebound['gate_phase']);
        $newStatus = $orchestrator->status($target['project'], $target['run']);
        $this->assertNotNull($newStatus);
        $newToken = $newStatus['gate']['gate_token'];
        $this->assertIsString($newToken);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(['primary' => $target['project']], $newStatus['gate']['selection_ids']);

        $this->assertRuntimeExceptionContains(
            fn () => $orchestrator->decide(
                $target['project'],
                $target['run'],
                'approve',
                gateToken: $oldToken,
            ),
            'stale',
        );

        $advanced = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'manual_override',
            gateToken: $newToken,
        );

        $this->assertSame('awaiting_review', $advanced['status']);
        $this->assertSame('concepts', $advanced['gate_phase']);
        $source = $workspace->effectiveIdeaSource($target['project'], $target['run']);
        $this->assertSame('project_idea', $source['mode']);
        $this->assertTrue($source['explicit']);
        $this->assertTrue($source['skipped']);
        $decision = collect(app(AutomationStateStore::class)
            ->events($target['project'], $target['run']))
            ->where('event', 'gate_decided')
            ->where('phase', 'ideas')
            ->last();
        $this->assertIsArray($decision);
        $this->assertSame('manual_override', $decision['decision']);
        $this->assertSame(['primary' => $target['project']], $decision['selection_ids']);
        $this->assertSame($target['project'], $decision['ideas_source']['source_id']);
        $this->assertSame('project_idea', $decision['ideas_source']['mode']);
        $this->assertTrue(collect($workspace->workspace(
            $target['project'],
            $target['run'],
            'concepts',
        )['artifacts'])->every(
            fn (array $candidate): bool => ($candidate['metadata']['source_artifact_ids'] ?? null) === [$target['project']],
        ));
        Http::assertNothingSent();
    }

    public function test_an_ideas_source_change_during_brief_review_repairs_from_the_earliest_stale_phase(): void
    {
        $target = $this->project('Ideas change during Brief review');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $workspace = app(CreativeWorkspaceRepository::class);
        $paused = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['brief-script'],
            'score_threshold' => 0,
        ]);

        $this->assertSame('awaiting_review', $paused['status']);
        $this->assertSame('brief-script', $paused['gate_phase']);
        $oldToken = $this->gateToken($orchestrator, $target);
        $ideas = $workspace->workspace($target['project'], $target['run'], 'ideas')['artifacts'];
        $alternative = collect($ideas)->first(
            fn (array $artifact): bool => ($artifact['selected'] ?? false) !== true,
        );
        $this->assertIsArray($alternative);

        $workspace->select(
            $target['project'],
            $target['run'],
            'ideas',
            $alternative['id'],
            reason: 'Use a different reviewed Idea before approving the Brief.',
        );

        $reopened = $orchestrator->status($target['project'], $target['run']);
        $this->assertNotNull($reopened);
        $this->assertSame('pending', $reopened['state']['status']);
        $this->assertSame('repair', $reopened['state']['step']);
        $this->assertSame('ideas', $reopened['state']['current_phase']);
        $this->assertNotEmpty($reopened['projection_issues']);

        $repaired = $orchestrator->resume($target['project'], $target['run']);
        $this->assertSame('awaiting_review', $repaired['status']);
        $this->assertSame('brief-script', $repaired['gate_phase']);
        $this->assertSame(2, $repaired['attempts_by_phase']['concepts']);
        $this->assertSame(2, $repaired['attempts_by_phase']['brief-script']);
        $newToken = $this->gateToken($orchestrator, $target);
        $this->assertNotSame($oldToken, $newToken);

        $this->assertRuntimeExceptionContains(
            fn () => $orchestrator->decide(
                $target['project'],
                $target['run'],
                'approve',
                gateToken: $oldToken,
            ),
            'stale',
        );

        $selectedConcept = collect($workspace->workspace(
            $target['project'],
            $target['run'],
            'concepts',
        )['artifacts'])->firstWhere('selected', true);
        $this->assertIsArray($selectedConcept);
        $this->assertSame([$alternative['id']], $selectedConcept['metadata']['source_artifact_ids']);
        $selectedBrief = collect($workspace->workspace(
            $target['project'],
            $target['run'],
            'brief-script',
        )['artifacts'])->firstWhere('selected', true);
        $this->assertIsArray($selectedBrief);
        $this->assertSame([$selectedConcept['id']], $selectedBrief['metadata']['source_artifact_ids']);
        Http::assertNothingSent();
    }

    /** @return array{project: string, run: string, phase: string} */
    private function project(string $name): array
    {
        return app(CreativeWorkspaceRepository::class)->createProject(
            $name,
            'Prove Ideas lineage recovery without contacting a provider.',
            'ideas',
        );
    }

    /**
     * @param  array{project: string, run: string, phase: string}  $target
     */
    private function gateToken(CreativeRunOrchestrator $orchestrator, array $target): string
    {
        $status = $orchestrator->status($target['project'], $target['run']);
        $this->assertNotNull($status);
        $token = $status['gate']['gate_token'] ?? null;
        $this->assertIsString($token);

        return $token;
    }

    private function assertRuntimeExceptionContains(\Closure $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected a runtime exception containing: '.$message);
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
