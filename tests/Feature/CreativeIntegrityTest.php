<?php

namespace Tests\Feature;

use App\Creative\Automation\AutomationPolicy;
use App\Creative\Automation\AutomationStateStore;
use App\Creative\Automation\CreativeRunOrchestrator;
use App\Creative\CreativeIntegrityAuditor;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreativeIntegrityTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->creativeRoot = storage_path('framework/testing/creative-integrity-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->creativeRoot);
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

    public function test_clean_simulated_workspace_has_no_integrity_errors(): void
    {
        $target = $this->createWorkspace();
        app(CreativeWorkspaceRepository::class)->simulate($target['project'], $target['run'], 'concepts', true);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);

        $this->assertSame(0, $report['summary']['errors']);
        $this->assertGreaterThan(0, $report['summary']['info']);
        $this->assertContains('phase_incomplete', array_column($report['issues'], 'code'));
    }

    public function test_effective_ideas_selection_requires_a_source_decision_for_its_exact_selection_record(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject(
            'Ideas decision evidence',
            'Require an explicit immutable promotion before Concepts can trust an Idea.',
            'ideas',
        );
        $repository->simulate($target['project'], $target['run'], 'ideas', true);
        $idea = $repository->workspace($target['project'], $target['run'], 'ideas')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'ideas', $idea['id']);
        $repository->simulate($target['project'], $target['run'], 'concepts', true);

        $decision = File::files($this->runPath($target).'/decisions/ideas-source')[0];
        File::delete($decision->getPathname());

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $codes = array_column($report['issues'], 'code');

        $this->assertContains('ideas_source_decision_missing', $codes);
        $this->assertContains('stale_artifact', $codes);
        $this->assertGreaterThan(0, $report['summary']['errors']);
    }

    public function test_automatic_ideas_selection_rejects_a_source_decision_for_another_selection_id(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject(
            'Automatic Ideas evidence',
            'Automatic source promotion must identify its exact immutable selection.',
            'ideas',
        );
        $state = app(CreativeRunOrchestrator::class)->start($target['project'], $target['run'], [
            'human_gates' => ['ideas'],
        ]);
        $this->assertSame('awaiting_review', $state['status']);

        $decisionPath = File::files($this->runPath($target).'/decisions/ideas-source')[0]->getPathname();
        $decision = MarkdownDocument::read($decisionPath);
        $decision['metadata']['selection_id'] = 'selection-forged--abc123';
        MarkdownDocument::write($decisionPath, $decision['metadata'], $decision['body']);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $issue = collect($report['issues'])->firstWhere('code', 'ideas_source_decision_mismatch');

        $this->assertIsArray($issue);
        $this->assertStringContainsString('automatic source promotion', $issue['message']);
        $this->assertGreaterThan(0, $report['summary']['errors']);
    }

    public function test_malformed_artifact_and_orphan_selection_are_reported(): void
    {
        $target = $this->createWorkspace();
        $repository = app(CreativeWorkspaceRepository::class);
        $repository->simulate($target['project'], $target['run'], 'concepts', true);
        $artifact = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'concepts', $artifact['id']);
        File::delete($this->creativeRoot.'/'.$artifact['path']);

        File::put(
            $this->runPath($target).'/01-concepts/outputs/corrupt.md',
            "---\nid: [unterminated\n---\n\n# Corrupt",
        );

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $codes = array_column($report['issues'], 'code');

        $this->assertContains('malformed_artifact', $codes);
        $this->assertContains('orphan_selection', $codes);
        $this->assertGreaterThanOrEqual(2, $report['summary']['errors']);
    }

    public function test_upstream_reselection_reports_the_effective_descendant_as_stale(): void
    {
        $target = $this->createWorkspace();
        $repository = app(CreativeWorkspaceRepository::class);
        $repository->simulate($target['project'], $target['run'], 'concepts', true);
        $concepts = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'];
        $repository->select($target['project'], $target['run'], 'concepts', $concepts[0]['id']);
        $repository->simulate($target['project'], $target['run'], 'brief-script', true);
        $script = $repository->workspace($target['project'], $target['run'], 'brief-script')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'brief-script', $script['id']);

        $repository->select($target['project'], $target['run'], 'concepts', $concepts[1]['id']);
        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);

        $this->assertContains('stale_selection', array_column($report['issues'], 'code'));
        $this->assertTrue(collect($report['issues'])->contains(
            fn (array $issue): bool => $issue['code'] === 'stale_artifact'
                && $issue['severity'] === 'warning'
                && $issue['phase'] === 'brief-script',
        ));
        $this->assertFalse(collect($report['issues'])->contains(
            fn (array $issue): bool => $issue['code'] === 'artifact_attempt_manifest_invalid'
                && $issue['phase'] === 'brief-script',
        ));
    }

    public function test_missing_ready_asset_and_path_traversal_are_reported(): void
    {
        $target = $this->createWorkspace();
        $output = $this->runPath($target).'/03-images/outputs';
        $this->writeImageSidecar($output.'/ready-image.md', 'ready-image', 'assets/missing.png', 'ready', $target['project']);
        $this->writeImageSidecar($output.'/unsafe-image.md', 'unsafe-image', '../outside.png', 'absent_simulation', $target['project']);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $codes = array_column($report['issues'], 'code');

        $this->assertContains('missing_ready_asset', $codes);
        $this->assertContains('asset_path_traversal', $codes);
    }

    public function test_incomplete_attempt_and_malformed_automation_files_are_reported(): void
    {
        $target = $this->createWorkspace();
        $runPath = $this->runPath($target);
        $attemptPath = $runPath.'/01-concepts/attempts/attempt-one';
        MarkdownDocument::write($attemptPath.'/request.md', [
            'id' => 'attempt-one',
            'type' => 'generation_attempt_request',
            'phase' => 'concepts',
            'status' => 'started',
        ], '# Attempt request');

        File::ensureDirectoryExists($runPath.'/automation');
        File::put($runPath.'/automation/state.md', "---\nstatus: [broken\n---\n");
        File::ensureDirectoryExists($runPath.'/01-concepts/evaluations');
        File::put($runPath.'/01-concepts/evaluations/broken.md', "---\nid: [broken\n---\n");

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $codes = array_column($report['issues'], 'code');

        $this->assertContains('incomplete_attempt', $codes);
        $this->assertContains('malformed_automation_state', $codes);
        $this->assertContains('malformed_evaluation', $codes);
    }

    public function test_policy_only_and_state_only_automation_bootstraps_are_reported(): void
    {
        $policyOnly = $this->createWorkspace();
        $states = app(AutomationStateStore::class);
        $policy = app(AutomationPolicy::class)->make('concepts', ['human_gates' => []]);
        $states->start(
            $policyOnly['project'],
            $policyOnly['run'],
            $states->newId('automation'),
            $policy,
        );
        File::delete($this->runPath($policyOnly).'/automation/state.md');

        $stateOnly = $this->createWorkspace();
        $states->start(
            $stateOnly['project'],
            $stateOnly['run'],
            $states->newId('automation'),
            $policy,
        );
        File::delete($this->runPath($stateOnly).'/automation/policy.md');

        foreach ([$policyOnly, $stateOnly] as $target) {
            $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);

            $this->assertContains('automation_bootstrap_incomplete', array_column($report['issues'], 'code'));
        }
    }

    public function test_canonical_automation_replay_reports_malformed_and_contradictory_gate_records(): void
    {
        $target = $this->createWorkspace();
        app(CreativeRunOrchestrator::class)->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $gate = collect(app(AutomationStateStore::class)->events($target['project'], $target['run']))
            ->firstWhere('event', 'gate_requested');
        $this->assertIsArray($gate);
        $this->assertIsString($gate['_path'] ?? null);
        $document = MarkdownDocument::read($gate['_path']);

        $malformed = $document['metadata'];
        unset($malformed['gate_kind']);
        MarkdownDocument::write($gate['_path'], $malformed, $document['body']);

        $malformedReport = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $malformedIssue = collect($malformedReport['issues'])->firstWhere('code', 'automation_replay_invalid');
        $this->assertIsArray($malformedIssue);
        $this->assertStringContainsString('invalid gate kind', $malformedIssue['message']);

        $contradictory = $document['metadata'];
        $contradictory['evaluation_id'] = 'evaluation-forged--abc123';
        MarkdownDocument::write($gate['_path'], $contradictory, $document['body']);

        $contradictoryReport = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $contradictoryIssue = collect($contradictoryReport['issues'])->firstWhere('code', 'automation_replay_invalid');
        $this->assertIsArray($contradictoryIssue);
        $this->assertStringContainsString('structural recommendations', $contradictoryIssue['message']);
    }

    public function test_canonical_automation_replay_reports_a_valid_but_diverged_checkpoint(): void
    {
        $target = $this->createWorkspace();
        app(CreativeRunOrchestrator::class)->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $statePath = $this->runPath($target).'/automation/state.md';
        $document = MarkdownDocument::read($statePath);
        $metadata = $document['metadata'];
        $metadata['last_error'] = 'A valid but non-canonical checkpoint mutation.';
        MarkdownDocument::write($statePath, $metadata, $document['body']);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);

        $this->assertContains('automation_checkpoint_diverged', array_column($report['issues'], 'code'));
    }

    public function test_attempt_request_result_metadata_and_terminal_status_are_validated(): void
    {
        $target = $this->createWorkspace();
        $attemptPath = $this->runPath($target).'/01-concepts/attempts/attempt-one';
        MarkdownDocument::write($attemptPath.'/request.md', [
            'schema_version' => 2,
            'id' => 'wrong-attempt',
            'type' => 'generation_attempt_request',
            'attempt_id' => 'wrong-attempt',
            'phase' => 'ideas',
            'started_at' => 'not-a-timestamp',
            'driver' => '',
            'source_artifact_ids' => 'not-a-list',
            'required_slots' => [],
            'expected_output_count' => 0,
            'reserved_simulation_units' => -1,
        ], '# Invalid request');
        MarkdownDocument::write($attemptPath.'/result.md', [
            'schema_version' => 2,
            'id' => 'wrong-result',
            'type' => 'generation_attempt_result',
            'attempt_id' => 'wrong-attempt',
            'phase' => 'ideas',
            'status' => 'mystery',
            'finished_at' => 'not-a-timestamp',
            'driver' => '',
            'artifact_ids' => ['one'],
            'output_count' => 0,
            'expected_output_count' => 0,
            'simulation_units' => -1,
        ], '# Invalid result');

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $codes = array_column($report['issues'], 'code');

        $this->assertContains('attempt_request_invalid', $codes);
        $this->assertContains('attempt_result_invalid', $codes);
        $this->assertContains('attempt_status_invalid', $codes);
    }

    public function test_cross_manifest_lineage_and_budget_failures_are_reported(): void
    {
        $target = $this->createWorkspace();
        $repository = app(CreativeWorkspaceRepository::class);
        $repository->simulate($target['project'], $target['run'], 'concepts', true);
        $artifact = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $attemptId = $artifact['metadata']['generation_attempt_id'];
        $attemptPath = $this->runPath($target).'/01-concepts/attempts/'.$attemptId;

        $request = MarkdownDocument::read($attemptPath.'/request.md');
        $request['metadata']['driver'] = 'different-driver';
        MarkdownDocument::write($attemptPath.'/request.md', $request['metadata'], $request['body']);

        $result = MarkdownDocument::read($attemptPath.'/result.md');
        $result['metadata']['simulation_units'] = $request['metadata']['reserved_simulation_units'] + 1;
        $result['metadata']['cost_usd'] = 1.0;
        MarkdownDocument::write($attemptPath.'/result.md', $result['metadata'], $result['body']);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $codes = array_column($report['issues'], 'code');

        $this->assertContains('attempt_manifest_mismatch', $codes);
        $this->assertContains('attempt_budget_exceeded', $codes);
        $this->assertContains('artifact_attempt_manifest_invalid', $codes);
    }

    public function test_automatic_selection_must_reference_its_exact_passed_recommendation(): void
    {
        $target = $this->createWorkspace();
        $repository = app(CreativeWorkspaceRepository::class);
        $repository->simulate($target['project'], $target['run'], 'concepts', true);
        $artifacts = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'];
        $evaluationId = 'evaluation-concepts--abc123';
        MarkdownDocument::write(
            $this->runPath($target).'/01-concepts/evaluations/'.$evaluationId.'.md',
            [
                'schema_version' => 1,
                'id' => $evaluationId,
                'type' => 'artifact_evaluation',
                'phase' => 'concepts',
                'passed' => true,
                'candidate_ids' => array_column($artifacts, 'id'),
                'recommendations' => ['primary' => $artifacts[1]['id']],
                'created_at' => now('UTC')->toIso8601String(),
            ],
            '# Evaluation',
            false,
        );
        $repository->select(
            $target['project'],
            $target['run'],
            'concepts',
            $artifacts[0]['id'],
            'automation',
            $evaluationId,
            'Deliberately does not match the recommendation.',
        );

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);

        $this->assertContains('automatic_selection_not_recommended', array_column($report['issues'], 'code'));
    }

    public function test_automatic_selection_with_a_missing_evaluation_is_reported(): void
    {
        $target = $this->createWorkspace();
        $repository = app(CreativeWorkspaceRepository::class);
        $repository->simulate($target['project'], $target['run'], 'concepts', true);
        $artifact = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $repository->select(
            $target['project'],
            $target['run'],
            'concepts',
            $artifact['id'],
            'automation',
            'missing-evaluation--abc123',
        );

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);

        $this->assertContains('automatic_selection_evaluation_missing', array_column($report['issues'], 'code'));
    }

    public function test_media_snapshot_changed_from_selected_script_is_stale_and_unselectable(): void
    {
        $target = $this->createWorkspace();
        $repository = app(CreativeWorkspaceRepository::class);
        $repository->simulate($target['project'], $target['run'], 'concepts', true);
        $concept = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'concepts', $concept['id']);
        $repository->simulate($target['project'], $target['run'], 'brief-script', true);
        $script = $repository->workspace($target['project'], $target['run'], 'brief-script')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'brief-script', $script['id']);
        $repository->simulate($target['project'], $target['run'], 'images', true);
        $image = $repository->workspace($target['project'], $target['run'], 'images')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'images', $image['id']);

        $imagePath = $this->creativeRoot.'/'.$image['path'];
        $document = MarkdownDocument::read($imagePath);
        $document['metadata']['shot']['visual'] = 'A changed visual that no longer matches the selected script.';
        MarkdownDocument::write($imagePath, $document['metadata'], $document['body']);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $codes = array_column($report['issues'], 'code');

        $this->assertContains('stale_artifact', $codes);
        $this->assertContains('stale_selection', $codes);
    }

    public function test_duplicate_artifact_ids_are_reported(): void
    {
        $target = $this->createWorkspace();
        $output = $this->runPath($target).'/01-concepts/outputs';
        $metadata = [
            'id' => 'duplicated-id',
            'artifact_type' => 'markdown',
            'phase' => 'concepts',
            'slot' => 'primary',
            'title' => 'Duplicate',
            'summary' => 'Duplicate identity fixture.',
            'created_at' => now('UTC')->toIso8601String(),
        ];
        $body = "# Duplicate\n\n## Hook\n\nHook.\n\n## Emotional turn\n\nTurn.\n\n## Product mechanism\n\nProduct.\n\n## Creative hypothesis\n\nHypothesis.\n\n## Risks or constraints\n\nRisk.";
        MarkdownDocument::write($output.'/first.md', $metadata, $body);
        MarkdownDocument::write($output.'/second.md', $metadata, $body);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);

        $this->assertContains('duplicate_artifact_id', array_column($report['issues'], 'code'));
    }

    public function test_committed_attempt_hash_mismatch_is_reported(): void
    {
        $target = $this->createWorkspace();
        $repository = app(CreativeWorkspaceRepository::class);
        $repository->simulate($target['project'], $target['run'], 'concepts', true);
        $artifact = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        File::append($this->creativeRoot.'/'.$artifact['path'], "\nUnexpected mutation.\n");

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);

        $this->assertContains('attempt_artifact_hash_mismatch', array_column($report['issues'], 'code'));
    }

    public function test_json_output_and_command_exit_codes_are_stable(): void
    {
        $target = $this->createWorkspace();

        $exit = Artisan::call('creative:integrity', [
            'project' => $target['project'],
            'run' => $target['run'],
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['project' => $target['project'], 'run' => $target['run']], $report['scope']);
        $this->assertSame(0, $report['summary']['errors']);
        foreach ($report['issues'] as $issue) {
            $this->assertSame(
                ['severity', 'code', 'project', 'run', 'phase', 'path', 'entity', 'message', 'remediation'],
                array_keys($issue),
            );
        }

        File::put(
            $this->runPath($target).'/01-concepts/outputs/corrupt.md',
            "---\nid: [unterminated\n---\n",
        );
        $this->assertSame(1, Artisan::call('creative:integrity', [
            'project' => $target['project'],
            'run' => $target['run'],
            '--json' => true,
        ]));
        $this->assertSame(2, Artisan::call('creative:integrity', [
            'project' => 'missing-project--abc123',
            '--json' => true,
        ]));
    }

    public function test_strict_mode_fails_on_warnings_but_not_incomplete_phase_info(): void
    {
        $target = $this->createWorkspace();
        $output = $this->runPath($target).'/01-concepts/outputs/legacy-concept.md';
        MarkdownDocument::write($output, [
            'id' => 'legacy-concept',
            'artifact_type' => 'markdown',
            'phase' => 'concepts',
            'slot' => 'primary',
            'title' => 'Legacy concept',
            'summary' => 'A valid legacy artifact with a provenance warning.',
            'created_at' => now('UTC')->toIso8601String(),
        ], "# Legacy concept\n\n## Hook\n\nHook.\n\n## Emotional turn\n\nTurn.\n\n## Product mechanism\n\nProduct.\n\n## Creative hypothesis\n\nHypothesis.\n\n## Risks or constraints\n\nRisk.");

        $this->assertSame(0, Artisan::call('creative:integrity', [
            'project' => $target['project'],
            'run' => $target['run'],
        ]));
        $this->assertSame(1, Artisan::call('creative:integrity', [
            'project' => $target['project'],
            'run' => $target['run'],
            '--strict' => true,
        ]));
    }

    public function test_audit_does_not_change_any_creative_file_or_directory(): void
    {
        $target = $this->createWorkspace();
        app(CreativeWorkspaceRepository::class)->simulate($target['project'], $target['run'], 'concepts', true);
        $before = $this->filesystemSnapshot();

        app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);

        $this->assertSame($before, $this->filesystemSnapshot());
    }

    /** @return array{project: string, run: string, phase: string} */
    private function createWorkspace(): array
    {
        return app(CreativeWorkspaceRepository::class)->createProject(
            'Integrity fixture',
            'Prove that the filesystem can diagnose itself without an API.',
            'concepts',
        );
    }

    /** @param array{project: string, run: string, phase: string} $target */
    private function runPath(array $target): string
    {
        return $this->creativeRoot."/{$target['project']}/runs/{$target['run']}";
    }

    private function writeImageSidecar(
        string $path,
        string $id,
        string $assetPath,
        string $assetStatus,
        string $sourceId,
    ): void {
        MarkdownDocument::write($path, [
            'schema_version' => 1,
            'generation_attempt_id' => 'integrity-attempt',
            'generator' => 'simulation',
            'prompt_version' => 'images-simulation-v1',
            'source_artifact_ids' => [$sourceId],
            'settings' => ['driver' => 'simulation'],
            'usage' => [],
            'cost_usd' => 0.0,
            'status' => 'simulated',
            'simulation' => true,
            'id' => $id,
            'artifact_type' => 'image_sidecar',
            'phase' => 'images',
            'slot' => 'shot-01',
            'variant' => 'A',
            'title' => Str::headline($id),
            'summary' => 'Integrity media fixture.',
            'asset_path' => $assetPath,
            'asset_status' => $assetStatus,
            'created_at' => now('UTC')->toIso8601String(),
        ], "# Image\n\n## Visual\n\nVisual.\n\n## Direction\n\nDirection.\n\n## Generation prompt\n\nPrompt.\n\n## Asset status\n\n{$assetStatus}.");
    }

    /** @return array<string, string> */
    private function filesystemSnapshot(): array
    {
        $snapshot = [];
        foreach (File::allFiles($this->creativeRoot) as $file) {
            $snapshot[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname()) ?: '';
        }
        foreach (File::directories($this->creativeRoot) as $directory) {
            $snapshot['directory:'.Str::after($directory, $this->creativeRoot.'/')] = 'directory';
        }
        ksort($snapshot);

        return $snapshot;
    }
}
