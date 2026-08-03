<?php

namespace Tests\Feature;

use App\Creative\Automation\CreativeRunOrchestrator;
use App\Creative\CreativeTraceabilityReader;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreativeTraceabilityUiTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-traceability-'.Str::lower(Str::random(8)));
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

    public function test_ideas_skip_is_an_append_only_source_decision_that_can_be_superseded(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Explicit skip', 'Choose the Concepts source deliberately.', 'ideas');
        $repository->simulate($target['project'], $target['run'], 'ideas', true);
        $ideas = $repository->workspace($target['project'], $target['run'], 'ideas')['artifacts'];
        $repository->select($target['project'], $target['run'], 'ideas', $ideas[0]['id'], reason: 'This is the first direction.');

        $base = "/projects/{$target['project']}/runs/{$target['run']}";
        $this->post("{$base}/ideas/skip", [
            'reason' => 'Use the broad project idea for this run instead.',
        ])->assertRedirect("{$base}/concepts");

        $skipped = $repository->workspace($target['project'], $target['run'], 'ideas');
        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts');
        $this->assertSame('skipped', $skipped['phase']['status']);
        $this->assertTrue($skipped['phase']['complete']);
        $this->assertSame('project_idea', $concepts['concept_source']['mode']);
        $this->assertTrue($concepts['concept_source']['explicit']);
        $this->assertSame($target['project'], $concepts['inputs'][0]['id']);

        $decisionPath = $this->creativeRoot."/{$target['project']}/runs/{$target['run']}/decisions/ideas-source";
        $this->assertCount(2, File::files($decisionPath));
        $latestSkip = collect(File::files($decisionPath))
            ->map(fn (\SplFileInfo $file): array => MarkdownDocument::read($file->getPathname())['metadata'])
            ->sortByDesc('sequence')
            ->first();
        $this->assertSame('project_idea', $latestSkip['mode']);
        $this->assertSame('Use the broad project idea for this run instead.', $latestSkip['reason']);
        $this->assertCount(2, app(CreativeTraceabilityReader::class)->phase(
            $target['project'],
            $target['run'],
            'concepts',
        )['source_decisions']);

        $repository->select($target['project'], $target['run'], 'ideas', $ideas[1]['id'], reason: 'This newer choice supersedes the skip.');
        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts');
        $this->assertSame('selected_idea', $concepts['concept_source']['mode']);
        $this->assertSame($ideas[1]['id'], $concepts['inputs'][0]['id']);
        $this->assertCount(3, File::files($decisionPath));

        $this->get("{$base}/concepts")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('creative/workspace')
                ->where('concept_source.mode', 'selected_idea')
                ->where('concept_source.source_id', $ideas[1]['id'])
                ->where('concept_source.explicit', true)
                ->has('traceability.source_decisions', 3));
    }

    public function test_workspace_exposes_attempt_evaluation_event_selection_and_feedback_history_records(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Inspectable trace', 'Make every automatic decision inspectable.', 'concepts');
        app(CreativeRunOrchestrator::class)->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $repository->appendFeedback($target['project'], $target['run'], 'concepts', 'phase', 'Keep the hook legible.');
        $repository->archiveFeedback($target['project'], $target['run'], 'concepts', 'phase');

        $traceability = app(CreativeTraceabilityReader::class)->phase(
            $target['project'],
            $target['run'],
            'concepts',
        );
        $this->assertNotEmpty($traceability['attempts']);
        $this->assertNotEmpty($traceability['evaluations']);
        $this->assertNotEmpty($traceability['selections']);
        $this->assertNotEmpty($traceability['automation_events']);

        $base = "/projects/{$target['project']}/runs/{$target['run']}/concepts";
        $this->get($base)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('creative/workspace')
                ->has('traceability.attempts', 1)
                ->has('traceability.evaluations', 1)
                ->has('traceability.selections', 1)
                ->has('traceability.automation_events')
                ->has('feedback.history', 1)
                ->where('feedback.history.0.metadata.status', 'archived_unapplied')
                ->has('integrity.issues'));
    }

    public function test_feedback_application_requests_results_and_parse_errors_are_exposed(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject(
            'Feedback trace',
            'Prove which pending feedback changed an immutable generation.',
            'concepts',
        );
        $repository->appendFeedback(
            $target['project'],
            $target['run'],
            'concepts',
            'phase',
            'Make the opening readable without sound.',
        );
        $repository->simulate($target['project'], $target['run'], 'concepts', true);

        $malformedDirectory = $this->creativeRoot
            ."/{$target['project']}/runs/{$target['run']}/feedback/applications/zz-malformed--concepts";
        File::ensureDirectoryExists($malformedDirectory);
        File::put($malformedDirectory.'/request.md', "---\nsources: [\n---\n");

        $applications = app(CreativeTraceabilityReader::class)->phase(
            $target['project'],
            $target['run'],
            'concepts',
        )['feedback_applications'];
        $this->assertCount(2, $applications);

        $applied = collect($applications)->firstWhere('status', 'applied');
        $this->assertIsArray($applied);
        $this->assertSame('concepts', $applied['phase']);
        $this->assertSame(64, strlen($applied['source_snapshots'][0]['pending_sha256']));
        $this->assertSame(64, strlen($applied['generation_feedback_sha256']));
        $this->assertCount(4, $applied['implementation_references']);
        $this->assertCount(1, $applied['history_ids']);
        $this->assertStringNotContainsString('pending_body', json_encode($applied['request'], JSON_THROW_ON_ERROR));

        $malformed = collect($applications)->firstWhere('status', 'unreadable');
        $this->assertIsArray($malformed);
        $this->assertNotSame('', $malformed['parse_error']);
        $this->assertStringContainsString('feedback/applications/zz-malformed--concepts', $malformed['path']);

        $this->get("/projects/{$target['project']}/runs/{$target['run']}/concepts")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('creative/workspace')
                ->has('traceability.feedback_applications', 2)
                ->has('feedback.history', 1)
                ->where('feedback.history.0.metadata.status', 'applied')
                ->has('feedback.history.0.metadata.implementation_references', 4));
    }

    public function test_dynamic_shot_details_and_human_selection_reason_are_exposed(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Shot context', 'Show why every shot exists.', 'concepts');
        $concept = $this->generateFirst($repository, $target, 'concepts');
        $repository->select($target['project'], $target['run'], 'concepts', $concept['id'], reason: 'Clearest concept for the audience.');
        $script = $this->generateFirst($repository, $target, 'brief-script');
        $repository->select($target['project'], $target['run'], 'brief-script', $script['id'], reason: 'Best timed story.');

        $images = $repository->workspace($target['project'], $target['run'], 'images');
        $firstSlot = $images['phase']['required_slots'][0];
        $this->assertSame($firstSlot, $images['shots'][$firstSlot]['id']);
        $this->assertIsNumeric($images['shots'][$firstSlot]['start_seconds']);
        $this->assertIsNumeric($images['shots'][$firstSlot]['end_seconds']);
        $this->assertNotSame('', $images['shots'][$firstSlot]['purpose']);
        $this->assertNotSame('', $images['shots'][$firstSlot]['visual']);

        $this->get("/projects/{$target['project']}/runs/{$target['run']}/images")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('creative/workspace')
                ->where("shots.{$firstSlot}.id", $firstSlot)
                ->where("shots.{$firstSlot}.purpose", $images['shots'][$firstSlot]['purpose'])
                ->where("shots.{$firstSlot}.visual", $images['shots'][$firstSlot]['visual']));

        $repository->simulate($target['project'], $target['run'], 'images', true);
        $generatedImages = $repository->workspace($target['project'], $target['run'], 'images')['artifacts'];
        $mediaJobs = app(CreativeTraceabilityReader::class)->phase(
            $target['project'],
            $target['run'],
            'images',
        )['media_jobs'];
        $this->assertNotEmpty($mediaJobs);
        $this->assertTrue(collect($generatedImages)->every(
            fn (array $artifact): bool => is_string($artifact['metadata']['media_job_id'] ?? null),
        ));

        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts');
        $selected = collect($concepts['artifacts'])->firstWhere('selected', true);
        $this->assertSame('Clearest concept for the audience.', $selected['selection']['reason']);
        $this->assertSame('human', $selected['selection']['selected_by']);
    }

    /**
     * @param  array{project: string, run: string, phase: string}  $target
     * @return array<string, mixed>
     */
    private function generateFirst(CreativeWorkspaceRepository $repository, array $target, string $phase): array
    {
        $repository->simulate($target['project'], $target['run'], $phase, true);

        return $repository->workspace($target['project'], $target['run'], $phase)['artifacts'][0];
    }
}
