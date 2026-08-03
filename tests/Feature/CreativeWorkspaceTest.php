<?php

namespace Tests\Feature;

use App\Creative\CreativeIntegrityAuditor;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\FilesystemCreativeStore;
use App\Creative\MarkdownDocument;
use App\Creative\ShotPlanResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreativeWorkspaceTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_project_is_created_as_a_filesystem_workspace(): void
    {
        $response = $this->post('/projects', [
            'name' => 'Quiet confidence',
            'idea' => 'A 15-second advertisement about a calmer way to begin a conversation.',
            'start_phase' => 'concepts',
        ]);

        $response->assertRedirect();

        $projects = app(CreativeWorkspaceRepository::class)->projects();
        $project = $projects[0];
        $projectPath = $this->creativeRoot.'/'.$project['id'];

        $this->assertFileExists($projectPath.'/project.md');
        $this->assertFileExists($projectPath.'/runs/'.$project['latest_run'].'/feedback/current/cycle.md');
        $this->assertDirectoryExists($projectPath.'/runs/'.$project['latest_run'].'/02-brief-script/outputs');

        $workspace = app(CreativeWorkspaceRepository::class)->workspace(
            $project['id'],
            $project['latest_run'],
            'concepts',
        );
        $this->assertTrue($workspace['concept_source']['explicit']);
        $this->assertTrue($workspace['concept_source']['skipped']);
        $this->assertSame($project['id'], $workspace['inputs'][0]['id']);
        $this->assertCount(1, app(FilesystemCreativeStore::class)->ideaSourceDecisions(
            $project['id'],
            $project['latest_run'],
        ));
    }

    public function test_options_can_be_generated_selected_and_handed_forward(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Human opening', 'Make dating conversations feel human.', 'concepts');
        $base = "/projects/{$target['project']}/runs/{$target['run']}";

        $this->post("{$base}/concepts/simulate")->assertRedirect();

        $workspace = $repository->workspace($target['project'], $target['run'], 'concepts');
        $this->assertCount(3, $workspace['artifacts']);

        $artifact = $workspace['artifacts'][0];
        $this->post("{$base}/concepts/artifacts/{$artifact['id']}/select")
            ->assertRedirect("{$base}/brief-script");

        $next = $repository->workspace($target['project'], $target['run'], 'brief-script');
        $this->assertSame($artifact['id'], $next['inputs'][0]['id']);
        $this->assertTrue($repository->workspace($target['project'], $target['run'], 'concepts')['phase']['complete']);
    }

    public function test_a_new_run_preserves_the_previous_iteration(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $first = $repository->createProject('Repeatable project', 'Test several creative iterations.', 'concepts');

        $this->post("/projects/{$first['project']}/runs")->assertRedirect();

        $workspace = $repository->workspace($first['project'], $first['run'], 'concepts');
        $this->assertCount(2, $workspace['runs']);
        $this->assertFileExists($this->creativeRoot."/{$first['project']}/runs/{$first['run']}/run.md");
    }

    public function test_feedback_moves_from_the_inbox_to_immutable_history(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Feedback loop', 'Test an advertisement idea.', 'concepts');
        $base = "/projects/{$target['project']}/runs/{$target['run']}/concepts/feedback";

        $this->post($base, [
            'scope' => 'phase',
            'feedback' => 'Make the hook understandable without sound.',
        ])->assertRedirect();

        $pending = $repository->workspace($target['project'], $target['run'], 'concepts')['feedback'];
        $this->assertCount(1, $pending['current']);

        $this->post($base.'/archive', ['scope' => 'phase'])->assertRedirect();

        $archived = $repository->workspace($target['project'], $target['run'], 'concepts')['feedback'];
        $this->assertCount(0, $archived['current']);
        $this->assertSame(1, $archived['history_count']);
    }

    public function test_workspace_page_exposes_six_simple_phases(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Six phases', 'A simple production chain.', 'concepts');

        $this->get("/projects/{$target['project']}/runs/{$target['run']}/concepts")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('creative/workspace')
                ->has('phases', 6)
                ->where('phase.key', 'concepts')
                ->where('phase.selection_mode', 'single')
                ->where('generation.effective_driver', 'simulation')
                ->where('generation.external', false)
                ->where('automation', null)
                ->where('integrity.errors', 0));
    }

    public function test_all_required_image_selections_are_needed_before_scenes_can_generate(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Exact phase gate', 'Require every selected shot.', 'concepts');
        $this->generateAndSelectPrimary($repository, $target, 'concepts');
        $this->generateAndSelectPrimary($repository, $target, 'brief-script');
        $repository->simulate($target['project'], $target['run'], 'images');
        $image = $repository->workspace($target['project'], $target['run'], 'images')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'images', $image['id']);

        $images = $repository->workspace($target['project'], $target['run'], 'images');
        $this->assertFalse($images['phase']['complete']);
        $this->assertSame(1, $images['phase']['selected_count']);

        $base = "/projects/{$target['project']}/runs/{$target['run']}";
        $this->post("{$base}/scenes/generate")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Complete every required selection'));

        $this->assertCount(0, $repository->workspace($target['project'], $target['run'], 'scenes')['artifacts']);
    }

    public function test_malformed_markdown_is_visible_but_cannot_be_selected(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Invalid artifact', 'Reject malformed output.', 'concepts');
        $output = $this->creativeRoot."/{$target['project']}/runs/{$target['run']}/01-concepts/outputs/malformed.md";
        File::put($output, "# Not a contract\n\nThis file has no front matter.");

        $workspace = $repository->workspace($target['project'], $target['run'], 'concepts');
        $artifact = $workspace['artifacts'][0];
        $this->assertFalse($artifact['validation']['valid']);

        $base = "/projects/{$target['project']}/runs/{$target['run']}/concepts";
        $this->post("{$base}/artifacts/{$artifact['id']}/select")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Artifact cannot be selected'));

        $this->assertFalse($repository->workspace($target['project'], $target['run'], 'concepts')['phase']['complete']);
    }

    public function test_corrupted_yaml_is_reported_without_crashing_the_workspace(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Corrupt artifact', 'Keep the workspace recoverable.', 'concepts');
        $output = $this->creativeRoot."/{$target['project']}/runs/{$target['run']}/01-concepts/outputs/corrupt.md";
        File::put($output, "---\nid: [unterminated\n---\n\n# Corrupt");

        $workspace = $repository->workspace($target['project'], $target['run'], 'concepts');

        $this->assertCount(1, $workspace['artifacts']);
        $this->assertFalse($workspace['artifacts'][0]['validation']['valid']);
        $this->assertContains(
            'The Markdown or YAML front matter cannot be parsed.',
            $workspace['artifacts'][0]['validation']['errors'],
        );
    }

    public function test_orphaned_selection_no_longer_counts_as_complete(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Orphan detection', 'Detect missing selected files.', 'concepts');
        $artifact = $this->generateAndSelectPrimary($repository, $target, 'concepts');
        File::delete($this->creativeRoot.'/'.$artifact['path']);

        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts');
        $brief = $repository->workspace($target['project'], $target['run'], 'brief-script');

        $this->assertFalse($concepts['phase']['complete']);
        $this->assertSame('stale', $concepts['phase']['status']);
        $this->assertSame(1, $concepts['phase']['selection_issue_count']);
        $this->assertFalse($brief['phase']['available']);
        $this->assertCount(0, $brief['inputs']);
    }

    public function test_optional_ideas_can_be_skipped_when_an_old_idea_selection_is_invalid(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Optional ideas', 'Concepts may still use the project idea.', 'ideas');
        $idea = $this->generateAndSelectPrimary($repository, $target, 'ideas');
        File::delete($this->creativeRoot.'/'.$idea['path']);

        $ideas = $repository->workspace($target['project'], $target['run'], 'ideas');
        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts');

        $this->assertSame('stale', $ideas['phase']['status']);
        $this->assertFalse($concepts['phase']['available']);
        $this->assertCount(0, $concepts['inputs']);
        $this->assertFalse($repository->phaseContext($target['project'], $target['run'], 'concepts')['ready']);

        $repository->skipIdeas(
            $target['project'],
            $target['run'],
            reason: 'The selected idea is unavailable; use project.md instead.',
        );
        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts');
        $this->assertTrue($concepts['phase']['available']);
        $this->assertTrue($concepts['concept_source']['explicit']);
        $this->assertTrue($concepts['concept_source']['skipped']);
        $this->assertSame($target['project'], $concepts['inputs'][0]['id']);
    }

    public function test_concepts_generation_requires_an_explicit_ideas_selection_or_skip(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject(
            'Ideas source gate',
            'Do not infer the Concepts source.',
            'ideas',
        );
        $base = "/projects/{$target['project']}/runs/{$target['run']}";
        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts');

        $this->assertFalse($concepts['phase']['available']);
        $this->assertFalse($concepts['concept_source']['explicit']);
        $this->assertCount(0, $concepts['inputs']);
        $this->post("{$base}/concepts/generate")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains(
                $message,
                'Complete every required selection',
            ));
        $this->assertCount(0, $repository->workspace(
            $target['project'],
            $target['run'],
            'concepts',
        )['artifacts']);

        $this->post("{$base}/ideas/skip", [
            'reason' => 'Use the broad idea deliberately.',
        ])->assertRedirect("{$base}/concepts");

        $decisions = app(FilesystemCreativeStore::class)->ideaSourceDecisions(
            $target['project'],
            $target['run'],
        );
        $this->assertCount(1, $decisions);
        $this->assertSame('project_idea', $decisions[0]['mode']);
        $this->assertSame($target['project'], $decisions[0]['source_id']);
        $this->assertSame('Use the broad idea deliberately.', $decisions[0]['reason']);

        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts');
        $this->assertTrue($concepts['phase']['available']);
        $this->assertTrue($concepts['concept_source']['explicit']);
        $this->assertTrue($concepts['concept_source']['skipped']);
        $this->assertSame($target['project'], $concepts['inputs'][0]['id']);

        $repository->simulate($target['project'], $target['run'], 'concepts', true);
        $this->assertTrue(collect($repository->workspace(
            $target['project'],
            $target['run'],
            'concepts',
        )['artifacts'])->every(
            fn (array $artifact): bool => $artifact['metadata']['source_artifact_ids'] === [$target['project']],
        ));
    }

    public function test_ideas_lineage_fails_closed_until_the_exact_selection_decision_is_recovered(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $store = app(FilesystemCreativeStore::class);
        $target = $repository->createProject(
            'Interrupted Ideas promotion',
            'Do not advance from an unproven Ideas selection.',
            'ideas',
        );
        $repository->simulate($target['project'], $target['run'], 'ideas');
        $artifact = $repository->workspace(
            $target['project'],
            $target['run'],
            'ideas',
        )['artifacts'][0];
        Carbon::setTestNow('2026-07-28T12:00:00+00:00');
        $repository->skipIdeas(
            $target['project'],
            $target['run'],
            reason: 'This skip is deliberately followed by a same-timestamp partial selection.',
        );
        $selectionId = 'interrupted-ideas-selection';
        $selectedAt = now('UTC')->format('Y-m-d\TH:i:s.uP');

        $store->appendSelection(
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
                'artifact_id' => $artifact['id'],
                'artifact_path' => $artifact['path'],
                'selected_by' => 'automation',
                'evaluation_id' => null,
                'reason' => 'The immutable selection survived an interrupted promotion.',
                'created_at' => $selectedAt,
            ],
            '# Interrupted Ideas selection',
        );

        $partialSource = $repository->effectiveIdeaSource($target['project'], $target['run']);
        $this->assertSame('selected_idea', $partialSource['mode']);
        $this->assertFalse($partialSource['explicit']);
        $this->assertFalse($partialSource['skipped']);
        $this->assertFalse($repository->phaseContext(
            $target['project'],
            $target['run'],
            'concepts',
        )['ready']);
        $partialIntegrity = app(CreativeIntegrityAuditor::class)->audit(
            $target['project'],
            $target['run'],
        );
        $this->assertContains(
            'ideas_source_decision_mismatch',
            array_column($partialIntegrity['issues'], 'code'),
        );

        $store->appendIdeaSourceDecision(
            $target['project'],
            $target['run'],
            'mismatched-ideas-source-decision',
            [
                'schema_version' => 1,
                'id' => 'mismatched-ideas-source-decision',
                'type' => 'ideas_source_decision',
                'phase' => 'ideas',
                'mode' => 'selected_idea',
                'source_id' => $artifact['id'],
                'artifact_id' => $artifact['id'],
                'selection_id' => 'a-different-selection',
                'selected_by' => 'automation',
                'reason' => 'This deliberately mismatched record must not prove promotion.',
                'created_at' => now('UTC')->format('Y-m-d\TH:i:s.uP'),
            ],
            '# Mismatched Ideas source decision',
        );

        $source = $repository->effectiveIdeaSource($target['project'], $target['run']);
        $ideas = $repository->workspace($target['project'], $target['run'], 'ideas');
        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts');
        $this->assertSame('selected_idea', $source['mode']);
        $this->assertFalse($source['explicit']);
        $this->assertNull($source['decision']);
        $this->assertFalse($ideas['phase']['complete']);
        $this->assertSame('stale', $ideas['phase']['status']);
        $this->assertFalse($repository->automationPhaseState(
            $target['project'],
            $target['run'],
            'ideas',
        )['complete']);
        $this->assertFalse($concepts['phase']['available']);
        $this->assertCount(0, $concepts['inputs']);
        $this->assertFalse($repository->phaseContext(
            $target['project'],
            $target['run'],
            'concepts',
        )['ready']);

        $recovered = $repository->ensureCurrentIdeaSourceDecision(
            $target['project'],
            $target['run'],
            reason: 'Resume the exact interrupted Ideas promotion.',
        );
        $this->assertTrue($recovered['explicit']);
        $this->assertSame($artifact['id'], $recovered['source_id']);
        $this->assertSame($selectionId, $recovered['decision']['selection_id']);
        $this->assertTrue($repository->automationPhaseState(
            $target['project'],
            $target['run'],
            'ideas',
        )['complete']);
        $this->assertTrue($repository->workspace(
            $target['project'],
            $target['run'],
            'concepts',
        )['phase']['available']);

        $decisionCount = count($store->ideaSourceDecisions($target['project'], $target['run']));
        $same = $repository->ensureCurrentIdeaSourceDecision($target['project'], $target['run']);
        $this->assertSame($recovered['decision']['id'], $same['decision']['id']);
        $this->assertCount(
            $decisionCount,
            $store->ideaSourceDecisions($target['project'], $target['run']),
        );

        $repository->skipIdeas(
            $target['project'],
            $target['run'],
            reason: 'A later explicit skip is now authoritative.',
        );

        try {
            $repository->ensureCurrentIdeaSourceDecision($target['project'], $target['run']);
            $this->fail('Recovery must not supersede the current explicit Ideas skip.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('explicitly skipped', $exception->getMessage());
        }
    }

    public function test_selecting_an_idea_records_exact_paired_lineage_evidence(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $store = app(FilesystemCreativeStore::class);
        $target = $repository->createProject(
            'Atomic Ideas promotion',
            'Record the selection and promotion as one lock-consistent action.',
            'ideas',
        );
        $repository->simulate($target['project'], $target['run'], 'ideas');
        $artifact = $repository->workspace(
            $target['project'],
            $target['run'],
            'ideas',
        )['artifacts'][0];

        $result = $repository->select(
            $target['project'],
            $target['run'],
            'ideas',
            $artifact['id'],
            reason: 'Promote this exact idea.',
        );
        $selection = $store->selectionRecords($target['project'], $target['run'], 'ideas')[0];
        $decision = $store->ideaSourceDecisions($target['project'], $target['run'])[0];

        $this->assertTrue($result['complete']);
        $this->assertSame('concepts', $result['next_phase']);
        $this->assertSame($artifact['id'], $decision['artifact_id']);
        $this->assertSame($selection['id'], $decision['selection_id']);
        $this->assertSame($selection['lineage_sequence'], $decision['lineage_sequence']);
        $this->assertTrue($repository->effectiveIdeaSource(
            $target['project'],
            $target['run'],
        )['explicit']);
        $this->assertTrue($repository->automationPhaseState(
            $target['project'],
            $target['run'],
            'ideas',
        )['complete']);
        $this->assertTrue($repository->phaseContext(
            $target['project'],
            $target['run'],
            'concepts',
        )['ready']);
    }

    public function test_last_same_second_selection_is_effective(): void
    {
        Carbon::setTestNow('2026-07-28T12:00:00+00:00');
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Selection order', 'Resolve same-second choices.', 'concepts');
        $repository->simulate($target['project'], $target['run'], 'concepts');
        $artifacts = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'];

        $repository->select($target['project'], $target['run'], 'concepts', $artifacts[0]['id']);
        $repository->select($target['project'], $target['run'], 'concepts', $artifacts[1]['id']);

        $workspace = $repository->workspace($target['project'], $target['run'], 'concepts');
        $selected = collect($workspace['artifacts'])->firstWhere('selected', true);
        $this->assertSame($artifacts[1]['id'], $selected['id']);
        $this->assertTrue($workspace['phase']['complete']);
    }

    public function test_regeneration_never_overwrites_previous_artifacts(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Immutable retries', 'Keep every generation attempt.', 'concepts');
        $repository->simulate($target['project'], $target['run'], 'concepts');
        $first = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'];

        $repository->simulate($target['project'], $target['run'], 'concepts');
        $all = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'];

        $this->assertCount(3, $first);
        $this->assertCount(6, $all);
        $this->assertCount(6, collect($all)->pluck('id')->unique());
        foreach ($first as $artifact) {
            $this->assertContains($artifact['id'], collect($all)->pluck('id')->all());
        }
    }

    public function test_artifact_from_an_uncommitted_attempt_cannot_be_selected(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Attempt gate', 'Reject partial generation batches.', 'concepts');
        $repository->simulate($target['project'], $target['run'], 'concepts');
        $artifact = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $attemptId = $artifact['metadata']['generation_attempt_id'];
        $result = $this->creativeRoot."/{$target['project']}/runs/{$target['run']}/01-concepts/attempts/{$attemptId}/result.md";
        File::delete($result);

        $base = "/projects/{$target['project']}/runs/{$target['run']}/concepts";
        $this->post("{$base}/artifacts/{$artifact['id']}/select")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'uncommitted'));

        $this->assertFalse($repository->workspace($target['project'], $target['run'], 'concepts')['phase']['complete']);
    }

    public function test_selected_artifact_becomes_incomplete_when_its_committed_bytes_change(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Committed bytes', 'Detect changes to promoted artifacts.', 'concepts');
        $artifact = $this->generateAndSelectPrimary($repository, $target, 'concepts');

        $this->assertTrue($repository->workspace($target['project'], $target['run'], 'concepts')['phase']['complete']);

        File::append($this->creativeRoot.'/'.$artifact['path'], "\nUnexpected mutation.\n");
        $workspace = $repository->workspace($target['project'], $target['run'], 'concepts');
        $mutated = collect($workspace['artifacts'])->firstWhere('id', $artifact['id']);

        $this->assertFalse($workspace['phase']['complete']);
        $this->assertSame('stale', $workspace['phase']['status']);
        $this->assertSame(1, $workspace['phase']['selection_issue_count']);
        $this->assertTrue($mutated['selection_invalid']);
        $this->assertFalse($mutated['validation']['valid']);
        $this->assertTrue(collect($mutated['validation']['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'SHA-256'),
        ));
    }

    public function test_selected_ready_media_becomes_incomplete_when_its_asset_disappears(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Ready media', 'Require selected media files to remain available.', 'concepts');
        $this->generateAndSelectPrimary($repository, $target, 'concepts');
        $this->generateAndSelectPrimary($repository, $target, 'brief-script');
        $repository->simulate($target['project'], $target['run'], 'images');

        $selectedIds = $this->selectFirstPerSlot($repository, $target, 'images');
        $this->assertTrue($repository->workspace($target['project'], $target['run'], 'images')['phase']['complete']);

        $artifact = collect($repository->workspace(
            $target['project'],
            $target['run'],
            'images',
        )['artifacts'])->firstWhere('id', $selectedIds[0]);
        $assetPath = dirname(dirname($this->creativeRoot.'/'.$artifact['path'])).'/'.$artifact['metadata']['asset_path'];
        $this->assertFileExists($assetPath);

        File::delete($assetPath);
        $workspace = $repository->workspace($target['project'], $target['run'], 'images');
        $missing = collect($workspace['artifacts'])->firstWhere('id', $artifact['id']);

        $this->assertFalse($workspace['phase']['complete']);
        $this->assertSame('stale', $workspace['phase']['status']);
        $this->assertTrue($missing['selection_invalid']);
        $this->assertTrue(collect($missing['validation']['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'binary file is missing'),
        ));
        $this->assertFalse($repository->workspace($target['project'], $target['run'], 'scenes')['phase']['available']);
    }

    public function test_media_artifact_with_a_parent_traversing_asset_path_cannot_be_selected(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Unsafe media', 'Reject paths outside the creative run.', 'concepts');
        $this->generateAndSelectPrimary($repository, $target, 'concepts');
        $this->generateAndSelectPrimary($repository, $target, 'brief-script');
        $repository->simulate($target['project'], $target['run'], 'images');

        $artifact = $repository->workspace($target['project'], $target['run'], 'images')['artifacts'][0];
        $this->rewriteArtifactMetadataAndRefreshAttemptHash($artifact, [
            'asset_path' => '../../outside.png',
        ]);

        $base = "/projects/{$target['project']}/runs/{$target['run']}/images";
        $this->post("{$base}/artifacts/{$artifact['id']}/select")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'unsafe'));

        $workspace = $repository->workspace($target['project'], $target['run'], 'images');
        $unsafe = collect($workspace['artifacts'])->firstWhere('id', $artifact['id']);
        $this->assertFalse($unsafe['validation']['valid']);
        $this->assertFalse($workspace['phase']['complete']);
    }

    public function test_legacy_simulate_route_never_uses_a_configured_provider(): void
    {
        config([
            'creative.generation.driver' => 'openrouter',
            'services.openrouter.key' => 'test-key-that-must-not-be-used',
        ]);
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Simulation boundary', 'Never contact a provider here.', 'concepts');
        $base = "/projects/{$target['project']}/runs/{$target['run']}/concepts";

        $this->post("{$base}/simulate")
            ->assertRedirect()
            ->assertSessionHas('success', 'Sample outputs created as new immutable files.');

        Http::assertNothingSent();
        $artifacts = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'];
        $this->assertCount(3, $artifacts);
        $this->assertTrue(collect($artifacts)->every(fn (array $artifact): bool => $artifact['metadata']['simulation'] === true));
    }

    public function test_upstream_reselection_marks_selected_descendants_stale(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Fresh lineage', 'Detect stale descendants.', 'concepts');
        $repository->simulate($target['project'], $target['run'], 'concepts');
        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'];
        $repository->select($target['project'], $target['run'], 'concepts', $concepts[0]['id']);
        $this->generateAndSelectPrimary($repository, $target, 'brief-script');

        $repository->select($target['project'], $target['run'], 'concepts', $concepts[1]['id']);
        $brief = $repository->workspace($target['project'], $target['run'], 'brief-script');

        $this->assertFalse($brief['phase']['complete']);
        $this->assertSame('stale', $brief['phase']['status']);
        $this->assertSame(1, $brief['phase']['selection_issue_count']);
        $this->assertTrue(collect($brief['artifacts'])->contains('selection_invalid', true));
    }

    public function test_complete_six_phase_simulation_records_valid_exact_lineage(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Dry pipeline', 'Prove all six phases without an API.', 'ideas');

        $this->generateAndSelectPrimary($repository, $target, 'ideas');
        $this->generateAndSelectPrimary($repository, $target, 'concepts');
        $script = $this->generateAndSelectPrimary($repository, $target, 'brief-script');
        $shotPlan = app(ShotPlanResolver::class)->resolve($script);
        $this->assertTrue($shotPlan['valid']);
        $expectedShotCount = count($shotPlan['shot_plan']['shots'] ?? []);

        $repository->simulate($target['project'], $target['run'], 'images');
        $imageIds = $this->selectFirstPerSlot($repository, $target, 'images');
        $repository->simulate($target['project'], $target['run'], 'scenes');
        $sceneIds = $this->selectFirstPerSlot($repository, $target, 'scenes');
        $this->generateAndSelectPrimary($repository, $target, 'sequence');

        $workspace = $repository->workspace($target['project'], $target['run'], 'sequence');
        $this->assertTrue(collect($workspace['phases'])->every('complete', true));
        $this->assertGreaterThan(0, $expectedShotCount);
        $this->assertCount($expectedShotCount, $imageIds);
        $this->assertCount($expectedShotCount, $sceneIds);
        $this->assertEqualsCanonicalizing($sceneIds, collect($workspace['inputs'])->pluck('id')->all());
        $this->assertSame('simulation', $workspace['generation']['effective_driver']);
        $this->assertFalse($workspace['generation']['external']);

        foreach (['ideas', 'concepts', 'brief-script', 'images', 'scenes', 'sequence'] as $phase) {
            $phaseWorkspace = $repository->workspace($target['project'], $target['run'], $phase);
            $this->assertTrue(collect($phaseWorkspace['artifacts'])->every(
                fn (array $artifact): bool => $artifact['validation']['valid']
                    && $artifact['metadata']['schema_version'] === 1
                    && $artifact['metadata']['source_artifact_ids'] !== [],
            ));
        }
    }

    /**
     * @param  array{project: string, run: string, phase: string}  $target
     * @return array<string, mixed>
     */
    private function generateAndSelectPrimary(CreativeWorkspaceRepository $repository, array $target, string $phase): array
    {
        $repository->simulate($target['project'], $target['run'], $phase);
        $artifact = $repository->workspace($target['project'], $target['run'], $phase)['artifacts'][0];
        $repository->select($target['project'], $target['run'], $phase, $artifact['id']);

        return $artifact;
    }

    /**
     * @param  array{project: string, run: string, phase: string}  $target
     * @return list<string>
     */
    private function selectFirstPerSlot(CreativeWorkspaceRepository $repository, array $target, string $phase): array
    {
        $artifacts = $repository->workspace($target['project'], $target['run'], $phase)['artifacts'];
        $selectedIds = [];

        foreach (collect($artifacts)->groupBy('slot') as $slotArtifacts) {
            $artifact = $slotArtifacts->first();
            $repository->select($target['project'], $target['run'], $phase, $artifact['id']);
            $selectedIds[] = $artifact['id'];
        }

        return $selectedIds;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $changes
     */
    private function rewriteArtifactMetadataAndRefreshAttemptHash(array $artifact, array $changes): void
    {
        $artifactPath = $this->creativeRoot.'/'.$artifact['path'];
        $document = MarkdownDocument::read($artifactPath);
        $metadata = array_merge($document['metadata'], $changes);
        MarkdownDocument::write($artifactPath, $metadata, $document['body']);

        $attemptId = $metadata['generation_attempt_id'] ?? null;
        $artifactId = $metadata['id'] ?? null;
        if (! is_string($attemptId) || ! is_string($artifactId)) {
            throw new \RuntimeException('Test artifact has no generation attempt identity.');
        }

        $resultPath = dirname(dirname($artifactPath))."/attempts/{$attemptId}/result.md";
        $result = MarkdownDocument::read($resultPath);
        $hashes = $result['metadata']['artifact_sha256'] ?? null;
        $hash = hash_file('sha256', $artifactPath);
        if (! is_array($hashes) || ! is_string($hash)) {
            throw new \RuntimeException('Test generation result has no artifact hashes.');
        }

        $hashes[$artifactId] = $hash;
        $result['metadata']['artifact_sha256'] = $hashes;
        MarkdownDocument::write($resultPath, $result['metadata'], $result['body']);
    }
}
