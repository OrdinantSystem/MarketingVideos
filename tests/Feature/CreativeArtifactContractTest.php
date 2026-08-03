<?php

namespace Tests\Feature;

use App\Creative\CreativeArtifactEligibilityVerifier;
use App\Creative\CreativeIntegrityAuditor;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreativeArtifactContractTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->creativeRoot = storage_path('framework/testing/artifact-contract-'.Str::lower(Str::random(8)));
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

    public function test_schema_one_artifact_without_its_attempt_manifests_is_unselectable_and_audited(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Manifest gate', 'Require reconstructible provenance.', 'concepts');
        $repository->simulate($target['project'], $target['run'], 'concepts', true);
        $artifact = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $attemptId = $artifact['metadata']['generation_attempt_id'];
        $attemptDirectory = dirname(dirname($this->creativeRoot.'/'.$artifact['path']))."/attempts/{$attemptId}";
        File::deleteDirectory($attemptDirectory);

        $base = "/projects/{$target['project']}/runs/{$target['run']}/concepts";
        $this->post("{$base}/artifacts/{$artifact['id']}/select")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains(
                $message,
                CreativeArtifactEligibilityVerifier::ERROR_SCHEMA_V1_ATTEMPT_REQUEST_MISSING,
            ));

        $workspace = $repository->workspace($target['project'], $target['run'], 'concepts');
        $invalid = collect($workspace['artifacts'])->firstWhere('id', $artifact['id']);
        $this->assertFalse($invalid['validation']['valid']);
        $this->assertFalse($workspace['phase']['complete']);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $issues = collect($report['issues'])->where('code', 'artifact_attempt_manifest_missing');
        $issue = $issues->first();
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertTrue($issues->contains('entity', $artifact['id']));
    }

    public function test_failed_media_state_cannot_be_selected(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $this->workspaceThroughScript($repository);
        $repository->simulate($target['project'], $target['run'], 'images', true);
        $artifact = $repository->workspace($target['project'], $target['run'], 'images')['artifacts'][0];
        $this->rewriteArtifactMetadataAndRefreshAttemptHash($artifact, ['asset_status' => 'failed']);

        $base = "/projects/{$target['project']}/runs/{$target['run']}/images";
        $this->post("{$base}/artifacts/{$artifact['id']}/select")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains(
                $message,
                'asset_status must be ready or absent_simulation',
            ));

        $workspace = $repository->workspace($target['project'], $target['run'], 'images');
        $failed = collect($workspace['artifacts'])->firstWhere('id', $artifact['id']);
        $this->assertFalse($failed['validation']['valid']);
        $this->assertFalse($workspace['phase']['complete']);
    }

    public function test_ready_binary_hash_is_rechecked_and_corruption_is_reported(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $this->workspaceThroughScript($repository);
        $repository->simulate($target['project'], $target['run'], 'images', true);
        $artifact = $repository->workspace($target['project'], $target['run'], 'images')['artifacts'][0];
        $assetPath = dirname(dirname($this->creativeRoot.'/'.$artifact['path'])).'/'.$artifact['metadata']['asset_path'];
        $this->assertFileExists($assetPath);

        $ready = collect($repository->workspace(
            $target['project'],
            $target['run'],
            'images',
        )['artifacts'])->firstWhere('id', $artifact['id']);
        $this->assertTrue($ready['validation']['valid']);

        $bytes = File::get($assetPath);
        $this->assertNotSame('', $bytes);
        File::put($assetPath, chr(ord($bytes[0]) ^ 0xFF).substr($bytes, 1));
        $changed = collect($repository->workspace(
            $target['project'],
            $target['run'],
            'images',
        )['artifacts'])->firstWhere('id', $artifact['id']);
        $this->assertFalse($changed['validation']['valid']);
        $this->assertTrue(collect($changed['validation']['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'asset_sha256'),
        ));

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $this->assertContains('asset_hash_mismatch', array_column($report['issues'], 'code'));
    }

    public function test_only_a_verified_ready_artifact_binary_is_streamed_inline(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $this->workspaceThroughScript($repository);
        $repository->simulate($target['project'], $target['run'], 'images', true);
        $artifact = $repository->workspace(
            $target['project'],
            $target['run'],
            'images',
        )['artifacts'][0];

        $this->assertSame('ready', $artifact['asset_status']);
        $this->assertTrue($artifact['validation']['valid']);
        $this->assertIsString($artifact['asset_url']);
        $response = $this->get($artifact['asset_url']);
        $response->assertOk()
            ->assertHeader('Content-Type', $artifact['metadata']['media_probe']['mime_type'])
            ->assertHeader('ETag', '"'.$artifact['metadata']['asset_sha256'].'"')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringStartsWith(
            'inline;',
            (string) $response->headers->get('Content-Disposition'),
        );

        $assetPath = dirname(dirname($this->creativeRoot.'/'.$artifact['path']))
            .'/'.$artifact['metadata']['asset_path'];
        File::append($assetPath, 'tamper');

        $this->get($artifact['asset_url'])->assertNotFound();
        $tampered = collect($repository->workspace(
            $target['project'],
            $target['run'],
            'images',
        )['artifacts'])->firstWhere('id', $artifact['id']);
        $this->assertFalse($tampered['validation']['valid']);
        $this->assertNull($tampered['asset_url']);
        Http::assertNothingSent();
    }

    public function test_later_ideas_skip_is_the_effective_concepts_source_for_integrity(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Explicit Ideas skip', 'Use the project idea after review.', 'ideas');
        $repository->simulate($target['project'], $target['run'], 'ideas', true);
        $idea = $repository->workspace($target['project'], $target['run'], 'ideas')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'ideas', $idea['id']);
        $repository->skipIdeas($target['project'], $target['run'], reason: 'Return to the broad project idea.');
        $repository->simulate($target['project'], $target['run'], 'concepts', true);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $conceptStaleness = collect($report['issues'])->filter(
            fn (array $issue): bool => $issue['code'] === 'stale_artifact' && $issue['phase'] === 'concepts',
        );

        $this->assertCount(0, $conceptStaleness);
        $this->assertNotContains('invalid_ideas_source_decision', array_column($report['issues'], 'code'));
    }

    public function test_malformed_and_invalid_ideas_source_decisions_are_reported_and_ignored_for_lineage(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Decision audit', 'Ignore invalid source records safely.', 'ideas');
        $repository->simulate($target['project'], $target['run'], 'ideas', true);
        $idea = $repository->workspace($target['project'], $target['run'], 'ideas')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'ideas', $idea['id']);

        $decisions = $this->runPath($target).'/decisions/ideas-source';
        File::put($decisions.'/malformed.md', "---\nmode: [unterminated\n---\n");
        MarkdownDocument::write($decisions.'/invalid.md', [
            'schema_version' => 1,
            'id' => 'invalid',
            'type' => 'ideas_source_decision',
            'phase' => 'ideas',
            'mode' => 'project_idea',
            'source_id' => 'wrong-project--abc123',
            'artifact_id' => null,
            'selection_id' => null,
            'selected_by' => 'human',
            'reason' => 'This invalid high-sequence record must be ignored.',
            'created_at' => now('UTC')->toIso8601String(),
            'sequence' => 999,
        ], '# Invalid Ideas source decision');
        $repository->simulate($target['project'], $target['run'], 'concepts', true);

        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $codes = array_column($report['issues'], 'code');
        $conceptStaleness = collect($report['issues'])->filter(
            fn (array $issue): bool => $issue['code'] === 'stale_artifact' && $issue['phase'] === 'concepts',
        );

        $this->assertContains('malformed_ideas_source_decision', $codes);
        $this->assertContains('invalid_ideas_source_decision', $codes);
        $this->assertCount(0, $conceptStaleness);
    }

    public function test_provider_sequence_candidates_receive_exact_selected_scene_contract_before_commit_and_selection(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $this->workspaceThroughScript($repository);
        $repository->simulate($target['project'], $target['run'], 'images', true);
        $this->selectOnePerRequiredSlot($repository, $target, 'images');
        $repository->simulate($target['project'], $target['run'], 'scenes', true);
        $this->selectOnePerRequiredSlot($repository, $target, 'scenes');

        $sceneWorkspace = $repository->workspace($target['project'], $target['run'], 'scenes');
        $selectedScenes = collect($sceneWorkspace['phase']['required_slots'])->map(
            fn (string $slot): array => collect($sceneWorkspace['artifacts'])
                ->first(fn (array $artifact): bool => $artifact['slot'] === $slot && $artifact['selected']),
        )->all();
        $expectedSceneIds = array_column($selectedScenes, 'id');
        $expectedShots = array_map(
            static fn (array $scene): array => $scene['metadata']['shot'],
            $selectedScenes,
        );

        config([
            'creative.generation.driver' => 'openrouter',
            'services.openrouter.key' => 'test-key',
        ]);
        Http::fake([
            'https://openrouter.ai/*' => Http::response($this->sequenceProviderResponse()),
        ]);

        $mode = $repository->simulate($target['project'], $target['run'], 'sequence');
        $sequences = $repository->workspace($target['project'], $target['run'], 'sequence')['artifacts'];

        $this->assertSame('provider', $mode);
        $this->assertCount(3, $sequences);
        foreach ($sequences as $sequence) {
            $this->assertSame($expectedSceneIds, $sequence['metadata']['source_artifact_ids']);
            $this->assertSame($expectedSceneIds, $sequence['metadata']['scene_artifact_ids']);
            $this->assertSame($expectedShots, $sequence['metadata']['shot_plan']['shots']);
            $this->assertTrue($sequence['validation']['valid']);
        }
        Http::assertSentCount(1);

        $tampered = $sequences[0];
        $plan = $tampered['metadata']['shot_plan'];
        $plan['shots'][0]['purpose'] = 'A different scene purpose';
        $this->rewriteArtifactMetadataAndRefreshAttemptHash($tampered, ['shot_plan' => $plan]);

        $base = "/projects/{$target['project']}/runs/{$target['run']}/sequence";
        $this->post("{$base}/artifacts/{$tampered['id']}/select")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains(
                $message,
                'does not match the current selected scene snapshots',
            ));
    }

    public function test_media_selection_rejects_an_attempt_reserved_for_only_part_of_the_current_shot_plan(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $this->workspaceThroughScript($repository);
        $repository->simulate($target['project'], $target['run'], 'images', true);
        $workspace = $repository->workspace($target['project'], $target['run'], 'images');
        $artifact = collect($workspace['artifacts'])->firstWhere('slot', 'shot-01');
        $this->assertIsArray($artifact);
        $this->assertGreaterThan(1, count($workspace['phase']['required_slots']));

        $attemptId = $artifact['metadata']['generation_attempt_id'];
        $attemptPath = $this->runPath($target).'/03-images/attempts/'.$attemptId.'/request.md';
        $request = MarkdownDocument::read($attemptPath);
        $request['metadata']['required_slots'] = ['shot-01'];
        MarkdownDocument::write($attemptPath, $request['metadata'], $request['body']);

        $base = "/projects/{$target['project']}/runs/{$target['run']}/images";
        $this->post("{$base}/artifacts/{$artifact['id']}/select")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains(
                $message,
                'required_slots do not match the current effective phase slots',
            ));
    }

    public function test_selecting_one_scene_revalidates_every_siblings_per_shot_lineage(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $this->workspaceThroughScript($repository);
        $repository->simulate($target['project'], $target['run'], 'images', true);
        $this->selectOnePerRequiredSlot($repository, $target, 'images');
        $repository->simulate($target['project'], $target['run'], 'scenes', true);

        $images = $repository->workspace($target['project'], $target['run'], 'images')['artifacts'];
        $selectedShotOneImage = collect($images)->first(
            fn (array $artifact): bool => $artifact['slot'] === 'shot-01' && $artifact['selected'],
        );
        $scenes = $repository->workspace($target['project'], $target['run'], 'scenes')['artifacts'];
        $candidate = collect($scenes)->firstWhere('slot', 'shot-01');
        $invalidSibling = collect($scenes)->firstWhere('slot', 'shot-02');
        $this->assertIsArray($selectedShotOneImage);
        $this->assertIsArray($candidate);
        $this->assertIsArray($invalidSibling);

        $this->rewriteArtifactMetadataAndRefreshAttemptHash($invalidSibling, [
            'source_artifact_ids' => [$selectedShotOneImage['id']],
        ]);

        $base = "/projects/{$target['project']}/runs/{$target['run']}/scenes";
        $this->post("{$base}/artifacts/{$candidate['id']}/select")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains(
                $message,
                "Committed artifact [{$invalidSibling['id']}] is invalid: Artifact sources do not match the current effective selection",
            ));
    }

    /** @return array{project: string, run: string, phase: string} */
    private function workspaceThroughScript(CreativeWorkspaceRepository $repository): array
    {
        $target = $repository->createProject('Media contract', 'Prove media eligibility locally.', 'concepts');
        foreach (['concepts', 'brief-script'] as $phase) {
            $repository->simulate($target['project'], $target['run'], $phase, true);
            $artifact = $repository->workspace($target['project'], $target['run'], $phase)['artifacts'][0];
            $repository->select($target['project'], $target['run'], $phase, $artifact['id']);
        }

        return $target;
    }

    /**
     * @param  array{project: string, run: string, phase: string}  $target
     */
    private function selectOnePerRequiredSlot(
        CreativeWorkspaceRepository $repository,
        array $target,
        string $phase,
    ): void {
        $workspace = $repository->workspace($target['project'], $target['run'], $phase);
        foreach ($workspace['phase']['required_slots'] as $slot) {
            $artifact = collect($workspace['artifacts'])->firstWhere('slot', $slot);
            $this->assertIsArray($artifact);
            $repository->select($target['project'], $target['run'], $phase, $artifact['id']);
        }
    }

    /** @return array<string, mixed> */
    private function sequenceProviderResponse(): array
    {
        $options = collect(range(1, 3))->map(fn (int $number): array => [
            'title' => "Provider sequence {$number}",
            'summary' => "Provider sequence summary {$number}",
            'body' => "# Provider sequence {$number}\n\n## Scene order\n\nUse every selected scene in order.\n\n## Exact timing\n\nPreserve selected shot timing.\n\n## Captions\n\nReadable captions.\n\n## Voice timing\n\nKeep voice inside each shot.\n\n## Sound\n\nUse one restrained cue.\n\n## Transitions\n\nUse direct cuts.\n\n## Aspect ratio\n\n9:16 portrait.\n\n## Total duration\n\n15.0 seconds.\n\n## Editor notes\n\nProvider alternative {$number}.",
        ])->all();

        return [
            'id' => 'sequence-structure-id',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => json_encode(['options' => $options])]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ];
    }

    /** @param array{project: string, run: string, phase: string} $target */
    private function runPath(array $target): string
    {
        return $this->creativeRoot."/{$target['project']}/runs/{$target['run']}";
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

        $attemptId = $metadata['generation_attempt_id'];
        $artifactId = $metadata['id'];
        $resultPath = dirname(dirname($artifactPath))."/attempts/{$attemptId}/result.md";
        $result = MarkdownDocument::read($resultPath);
        $hash = hash_file('sha256', $artifactPath);
        $this->assertIsString($hash);
        $result['metadata']['artifact_sha256'][$artifactId] = $hash;
        MarkdownDocument::write($resultPath, $result['metadata'], $result['body']);
    }
}
