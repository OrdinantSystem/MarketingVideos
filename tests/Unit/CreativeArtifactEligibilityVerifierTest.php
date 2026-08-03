<?php

namespace Tests\Unit;

use App\Creative\CreativeArtifactEligibilityVerifier;
use App\Creative\CreativePathResolver;
use App\Creative\Generation\GenerationAttemptStore;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreativeArtifactEligibilityVerifierTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/eligibility-'.Str::lower(Str::random(8)));
        config(['creative.root' => $this->creativeRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_legacy_artifact_without_an_attempt_manifest_remains_eligible(): void
    {
        $result = $this->verifier()->verify(
            'project--abc123',
            '20260728-120000--abc123',
            'concepts',
            $this->artifact('artifact-1', 'markdown', 'attempt-1'),
        );

        $this->assertTrue($result['eligible']);
        $this->assertSame([], $result['errors']);
        $this->assertTrue(collect($result['warnings'])->contains(
            fn (string $warning): bool => str_contains($warning, 'no generation attempt manifest'),
        ));
    }

    public function test_schema_one_artifact_without_an_attempt_request_is_ineligible(): void
    {
        $artifact = $this->artifact('artifact-1', 'markdown', 'attempt-1');
        $artifact['metadata']['schema_version'] = 1;

        $result = $this->verifier()->verify(
            'project--abc123',
            '20260728-120000--abc123',
            'concepts',
            $artifact,
        );

        $this->assertFalse($result['eligible']);
        $this->assertContains(
            CreativeArtifactEligibilityVerifier::ERROR_SCHEMA_V1_ATTEMPT_REQUEST_MISSING,
            $result['errors'],
        );
        $this->assertSame([], $result['warnings']);
    }

    public function test_schema_one_artifact_without_an_attempt_result_is_ineligible(): void
    {
        $project = 'project--abc123';
        $run = '20260728-120000--abc123';
        $phase = 'concepts';
        $attemptId = 'attempt-1';
        app(GenerationAttemptStore::class)->begin($project, $run, $phase, $attemptId, [
            'driver' => 'simulation',
        ]);
        $artifact = $this->artifact('artifact-1', 'markdown', $attemptId);
        $artifact['metadata']['schema_version'] = 1;

        $result = $this->verifier()->verify($project, $run, $phase, $artifact);

        $this->assertFalse($result['eligible']);
        $this->assertContains(
            CreativeArtifactEligibilityVerifier::ERROR_SCHEMA_V1_ATTEMPT_RESULT_MISSING,
            $result['errors'],
        );
    }

    public function test_committed_artifact_must_still_match_its_recorded_sha256(): void
    {
        $project = 'project--abc123';
        $run = '20260728-120000--abc123';
        $phase = 'concepts';
        $artifactId = 'artifact-1';
        $attemptId = 'attempt-1';
        $paths = app(CreativePathResolver::class);
        $attempts = app(GenerationAttemptStore::class);
        $artifactPath = $paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md';

        MarkdownDocument::write($artifactPath, ['id' => $artifactId], '# Immutable artifact', false);
        $hash = hash_file('sha256', $artifactPath);
        $this->assertIsString($hash);
        $attempts->begin($project, $run, $phase, $attemptId, ['driver' => 'simulation']);
        $attempts->finish($project, $run, $phase, $attemptId, 'committed', [
            'artifact_ids' => [$artifactId],
            'artifact_sha256' => [$artifactId => $hash],
            'output_count' => 1,
            'expected_output_count' => 1,
        ], 'Committed for an eligibility test.');

        $artifact = $this->artifact($artifactId, 'markdown', $attemptId);
        $this->assertTrue($this->verifier()->verify($project, $run, $phase, $artifact)['eligible']);

        File::append($artifactPath, "\nmutated");
        $mutated = $this->verifier()->verify($project, $run, $phase, $artifact);

        $this->assertFalse($mutated['eligible']);
        $this->assertTrue(collect($mutated['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'SHA-256'),
        ));
    }

    public function test_schema_one_artifact_with_a_consistent_committed_attempt_is_eligible(): void
    {
        $fixture = $this->strictCommittedConceptAttempt();

        $result = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
        );

        $this->assertTrue($result['eligible'], implode(' ', $result['errors']));
        $this->assertSame([], $result['errors']);
    }

    public function test_schema_one_artifact_requires_the_attempt_to_reserve_the_current_effective_slots(): void
    {
        $fixture = $this->strictCommittedConceptAttempt();

        $result = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
            ['primary', 'another-current-slot'],
        );

        $this->assertFalse($result['eligible']);
        $this->assertContains(
            'Artifact generation attempt required_slots do not match the current effective phase slots.',
            $result['errors'],
        );
    }

    public function test_schema_one_selection_revalidates_every_stored_batch_member(): void
    {
        $fixture = $this->strictCommittedConceptAttempt();
        $siblingPath = $fixture['artifact_paths']['artifact-2'];
        $this->rewriteMetadata($siblingPath, ['generation_attempt_id' => 'another-attempt']);
        $result = MarkdownDocument::read($fixture['result_path']);
        $hash = hash_file('sha256', $siblingPath);
        $this->assertIsString($hash);
        $result['metadata']['artifact_sha256']['artifact-2'] = $hash;
        MarkdownDocument::write($fixture['result_path'], $result['metadata'], $result['body']);

        $verification = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
            ['primary'],
        );

        $this->assertFalse($verification['eligible']);
        $this->assertTrue(collect($verification['errors'])->contains(
            fn (string $error): bool => str_contains($error, '[artifact-2] identity')
                || str_contains($error, '[artifact-2] is invalid'),
        ));
    }

    public function test_schema_one_attempt_cannot_exceed_its_reserved_units_or_cost(): void
    {
        $fixture = $this->strictCommittedConceptAttempt();
        $this->rewriteMetadata($fixture['result_path'], [
            'simulation_units' => 2,
            'cost_usd' => 1.0,
        ]);

        $verification = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
            ['primary'],
        );

        $this->assertFalse($verification['eligible']);
        $this->assertTrue(collect($verification['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'more simulation_units than it reserved'),
        ));
        $this->assertTrue(collect($verification['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'cost_usd exceeds'),
        ));
    }

    public function test_schema_one_artifact_rejects_a_malformed_or_invalid_request_record(): void
    {
        $fixture = $this->strictCommittedConceptAttempt();
        File::put($fixture['request_path'], "---\nsource_artifact_ids: [unterminated\n---\n");

        $malformed = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
        );

        $this->assertFalse($malformed['eligible']);
        $this->assertTrue(collect($malformed['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'manifest cannot be read'),
        ));

        $fixture = $this->strictCommittedConceptAttempt();
        $this->rewriteMetadata($fixture['request_path'], [
            'type' => 'untrusted_request',
            'source_artifact_ids' => 'source-1',
        ]);
        $invalid = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
        );

        $this->assertFalse($invalid['eligible']);
        $this->assertTrue(collect($invalid['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'request is invalid')
                && str_contains($error, 'generation_attempt_request'),
        ));
        $this->assertTrue(collect($invalid['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'source_artifact_ids'),
        ));
    }

    public function test_schema_one_artifact_rejects_an_invalid_or_non_committed_result_record(): void
    {
        $fixture = $this->strictCommittedConceptAttempt();
        $this->rewriteMetadata($fixture['result_path'], ['finished_at' => 'tomorrow']);

        $invalid = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
        );
        $this->assertFalse($invalid['eligible']);
        $this->assertTrue(collect($invalid['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'result is invalid')
                && str_contains($error, 'finished_at'),
        ));

        $fixture = $this->strictCommittedConceptAttempt();
        $this->rewriteMetadata($fixture['result_path'], [
            'status' => 'interrupted',
            'artifact_ids' => [],
            'output_count' => 0,
        ]);
        $interrupted = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
        );

        $this->assertFalse($interrupted['eligible']);
        $this->assertContains('Artifact generation attempt is not committed.', $interrupted['errors']);
    }

    public function test_schema_one_artifact_rejects_inconsistent_driver_source_and_count_lineage(): void
    {
        $fixture = $this->strictCommittedConceptAttempt();
        $this->rewriteMetadata($fixture['request_path'], [
            'driver' => 'other-driver',
            'source_artifact_ids' => ['other-source'],
            'expected_output_count' => 2,
        ]);

        $result = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
        );

        $this->assertFalse($result['eligible']);
        $this->assertTrue(collect($result['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'drivers do not match'),
        ));
        $this->assertTrue(collect($result['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'source lineage'),
        ));
        $this->assertTrue(collect($result['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'phase contract'),
        ));
    }

    public function test_schema_one_artifact_rejects_an_incomplete_hash_manifest(): void
    {
        $fixture = $this->strictCommittedConceptAttempt();
        $result = MarkdownDocument::read($fixture['result_path']);
        unset($result['metadata']['artifact_sha256']['artifact-3']);
        MarkdownDocument::write($fixture['result_path'], $result['metadata'], $result['body']);

        $verification = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
        );

        $this->assertFalse($verification['eligible']);
        $this->assertTrue(collect($verification['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'exact artifact batch'),
        ));
    }

    public function test_schema_one_selection_requires_every_committed_batch_member_to_exist_and_match(): void
    {
        $fixture = $this->strictCommittedConceptAttempt();
        File::delete($fixture['artifact_paths']['artifact-3']);

        $missing = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
        );

        $this->assertFalse($missing['eligible']);
        $this->assertTrue(collect($missing['errors'])->contains(
            fn (string $error): bool => str_contains($error, '[artifact-3] is missing'),
        ));

        $fixture = $this->strictCommittedConceptAttempt();
        File::append($fixture['artifact_paths']['artifact-2'], "\nmutated");
        $changed = $this->verifier()->verify(
            $fixture['project'],
            $fixture['run'],
            $fixture['phase'],
            $fixture['artifact'],
        );

        $this->assertFalse($changed['eligible']);
        $this->assertTrue(collect($changed['errors'])->contains(
            fn (string $error): bool => str_contains($error, '[artifact-2] bytes')
                && str_contains($error, 'SHA-256'),
        ));
    }

    public function test_ready_media_requires_a_safe_existing_asset(): void
    {
        $project = 'project--abc123';
        $run = '20260728-120000--abc123';
        $phase = 'images';
        $paths = app(CreativePathResolver::class);
        $asset = $paths->outputsPath($project, $run, $phase).'/assets/image-1.png';
        File::ensureDirectoryExists(dirname($asset));
        $bytes = 'fake-png';
        File::put($asset, $bytes);
        $artifact = $this->artifact('image-1', 'image_sidecar');
        $artifact['metadata'] = array_merge($artifact['metadata'], [
            'status' => 'ready',
            'simulation' => true,
            'asset_path' => 'assets/image-1.png',
            'asset_status' => 'ready',
            'asset_sha256' => hash('sha256', $bytes),
            'asset_bytes' => strlen($bytes),
            'media_probe' => [
                'media_type' => 'image',
                'mime_type' => 'image/png',
                'codec' => 'png',
                'width' => 1080,
                'height' => 1920,
            ],
        ]);

        $this->assertTrue($this->verifier()->verify($project, $run, $phase, $artifact)['eligible']);

        File::put($asset, 'changed-image-bytes');
        $changed = $this->verifier()->verify($project, $run, $phase, $artifact);
        $this->assertFalse($changed['eligible']);
        $this->assertTrue(collect($changed['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'asset_sha256'),
        ));
        $this->assertTrue(collect($changed['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'asset_bytes'),
        ));

        File::delete($asset);
        $missing = $this->verifier()->verify($project, $run, $phase, $artifact);

        $this->assertFalse($missing['eligible']);
        $this->assertTrue(collect($missing['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'binary file is missing'),
        ));
    }

    public function test_unknown_and_failure_media_states_are_ineligible(): void
    {
        foreach (['missing', 'pending', 'running', 'failed', 'cancelled', 'timed_out', 'unknown'] as $status) {
            $artifact = $this->artifact('image-1', 'image_sidecar');
            $artifact['metadata'] = array_merge($artifact['metadata'], [
                'status' => 'simulated',
                'simulation' => true,
                'asset_path' => 'assets/image-1.png',
                'asset_status' => $status,
            ]);

            $result = $this->verifier()->verify(
                'project--abc123',
                '20260728-120000--abc123',
                'images',
                $artifact,
            );

            $this->assertFalse($result['eligible'], "Expected {$status} to be ineligible.");
            $this->assertContains('Media asset_status must be ready or absent_simulation.', $result['errors']);
        }
    }

    public function test_absent_simulation_requires_deliberate_simulation_and_no_binary(): void
    {
        $project = 'project--abc123';
        $run = '20260728-120000--abc123';
        $phase = 'images';
        $artifact = $this->artifact('image-1', 'image_sidecar');
        $artifact['metadata'] = array_merge($artifact['metadata'], [
            'status' => 'simulated',
            'simulation' => true,
            'asset_path' => 'assets/image-1.png',
            'asset_status' => 'absent_simulation',
        ]);

        $this->assertTrue($this->verifier()->verify($project, $run, $phase, $artifact)['eligible']);

        $asset = app(CreativePathResolver::class)->outputsPath($project, $run, $phase).'/assets/image-1.png';
        File::ensureDirectoryExists(dirname($asset));
        File::put($asset, 'unexpected bytes');
        $unexpected = $this->verifier()->verify($project, $run, $phase, $artifact);

        $this->assertFalse($unexpected['eligible']);
        $this->assertTrue(collect($unexpected['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'unexpectedly resolves'),
        ));

        File::delete($asset);
        $artifact['metadata']['simulation'] = false;
        $notDeliberate = $this->verifier()->verify($project, $run, $phase, $artifact);
        $this->assertFalse($notDeliberate['eligible']);
        $this->assertTrue(collect($notDeliberate['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'deliberate simulated artifact'),
        ));
    }

    public function test_parent_traversing_media_path_is_never_eligible(): void
    {
        $artifact = $this->artifact('image-1', 'image_sidecar');
        $artifact['metadata']['asset_path'] = '../../outside.png';
        $artifact['metadata']['asset_status'] = 'absent_simulation';
        $artifact['metadata']['status'] = 'simulated';
        $artifact['metadata']['simulation'] = true;

        $result = $this->verifier()->verify(
            'project--abc123',
            '20260728-120000--abc123',
            'images',
            $artifact,
        );

        $this->assertFalse($result['eligible']);
        $this->assertTrue(collect($result['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'unsafe'),
        ));
    }

    /** @return array<string, mixed> */
    private function artifact(string $id, string $type, ?string $attemptId = null): array
    {
        return [
            'id' => $id,
            'metadata' => array_filter([
                'id' => $id,
                'artifact_type' => $type,
                'generation_attempt_id' => $attemptId,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    private function verifier(): CreativeArtifactEligibilityVerifier
    {
        return app(CreativeArtifactEligibilityVerifier::class);
    }

    /**
     * @return array{
     *     project: string,
     *     run: string,
     *     phase: string,
     *     artifact: array<string, mixed>,
     *     request_path: string,
     *     result_path: string,
     *     artifact_paths: array<string, string>
     * }
     */
    private function strictCommittedConceptAttempt(): array
    {
        $project = 'project--abc123';
        $run = '20260728-120000--abc123';
        $phase = 'concepts';
        $attemptId = 'attempt-strict-1';
        $sourceIds = ['source-1'];
        $artifactIds = ['artifact-1', 'artifact-2', 'artifact-3'];
        $paths = app(CreativePathResolver::class);
        File::deleteDirectory($paths->runPath($project, $run));
        $artifactPaths = [];
        $hashes = [];
        $selectedArtifact = [];

        foreach ($artifactIds as $index => $artifactId) {
            $number = $index + 1;
            $metadata = [
                'schema_version' => 1,
                'id' => $artifactId,
                'artifact_type' => 'markdown',
                'phase' => $phase,
                'slot' => 'primary',
                'title' => "Concept {$number}",
                'summary' => "Distinct concept {$number}.",
                'created_at' => "2026-07-28T12:00:0{$number}+00:00",
                'candidate_order' => $number,
                'generation_attempt_id' => $attemptId,
                'generator' => 'simulation',
                'prompt_version' => 'concepts-simulation-v1',
                'settings' => ['driver' => 'simulation'],
                'usage' => [],
                'cost_usd' => 0.0,
                'source_artifact_ids' => $sourceIds,
                'status' => 'ready',
                'simulation' => true,
            ];
            $artifactPath = $paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md';
            $body = "# Concept {$number}\n\n## Hook\n\nHook {$number}.\n\n## Emotional turn\n\nTurn {$number}.\n\n## Product mechanism\n\nMechanism {$number}.\n\n## Creative hypothesis\n\nHypothesis {$number}.\n\n## Risks or constraints\n\nRisk {$number}.";
            MarkdownDocument::write($artifactPath, $metadata, $body, false);
            $hash = hash_file('sha256', $artifactPath);
            $this->assertIsString($hash);
            $artifactPaths[$artifactId] = $artifactPath;
            $hashes[$artifactId] = $hash;
            if ($artifactId === 'artifact-1') {
                $selectedArtifact = [
                    'id' => $artifactId,
                    'metadata' => $metadata,
                ];
            }
        }

        $attempts = app(GenerationAttemptStore::class);
        $attempts->begin($project, $run, $phase, $attemptId, [
            'driver' => 'simulation',
            'source_artifact_ids' => $sourceIds,
            'required_slots' => ['primary'],
            'expected_output_count' => 3,
            'reserved_simulation_units' => 1,
            'cost_limit_usd' => 0.0,
        ]);
        $attempts->finish($project, $run, $phase, $attemptId, 'committed', [
            'driver' => 'simulation',
            'artifact_ids' => $artifactIds,
            'artifact_sha256' => $hashes,
            'output_count' => 3,
            'expected_output_count' => 3,
            'simulation_units' => 1,
            'cost_usd' => 0.0,
        ], 'Committed strict eligibility fixture.');

        $attemptRoot = dirname($paths->outputsPath($project, $run, $phase)).'/attempts/'.$attemptId;

        return [
            'project' => $project,
            'run' => $run,
            'phase' => $phase,
            'artifact' => $selectedArtifact,
            'request_path' => $attemptRoot.'/request.md',
            'result_path' => $attemptRoot.'/result.md',
            'artifact_paths' => $artifactPaths,
        ];
    }

    /** @param array<string, mixed> $changes */
    private function rewriteMetadata(string $path, array $changes): void
    {
        $document = MarkdownDocument::read($path);
        MarkdownDocument::write($path, array_merge($document['metadata'], $changes), $document['body']);
    }
}
