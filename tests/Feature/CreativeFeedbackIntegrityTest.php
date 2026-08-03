<?php

namespace Tests\Feature;

use App\Creative\CreativeIntegrityAuditor;
use App\Creative\CreativePathResolver;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\Feedback\FeedbackApplicationStore;
use App\Creative\Generation\CreativeGenerationService;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CreativeFeedbackIntegrityTest extends TestCase
{
    private string $creativeRoot;

    /** @var array{project: string, run: string} */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->creativeRoot = storage_path('framework/testing/feedback-integrity-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
        ]);
        $created = app(CreativeWorkspaceRepository::class)->createProject(
            'Feedback integrity',
            'Prove feedback application history locally.',
            'concepts',
        );
        $this->target = [
            'project' => $created['project'],
            'run' => $created['run'],
        ];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_valid_applied_lifecycle_is_clean_and_identity_or_phase_corruption_is_reported(): void
    {
        $application = $this->appliedApplication();
        $attemptId = $application['attempt_id'];

        $this->assertSame([], $this->applicationCodes($attemptId));

        $this->rewriteMetadata($application['path'].'/request.md', [
            'id' => 'wrong--feedback-application',
            'phase' => 'images',
        ]);
        $this->rewriteMetadata($application['path'].'/result.md', [
            'attempt_id' => 'wrong--attempt',
            'phase' => 'brief-script',
        ]);

        $codes = $this->applicationCodes($attemptId);
        $this->assertContains('invalid_feedback_application_request', $codes);
        $this->assertContains('invalid_feedback_application_result', $codes);
        $this->assertContains('feedback_application_phase_mismatch', $codes);
        Http::assertNothingSent();
    }

    public function test_reserved_application_without_terminal_result_and_malformed_result_are_reported(): void
    {
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->appendFeedback(
            $this->target['project'],
            $this->target['run'],
            'concepts',
            'phase',
            'Keep the headline legible.',
        );
        $context = $workspace->phaseContext($this->target['project'], $this->target['run'], 'concepts');
        $attemptId = '20260728-120000--generation--reserved--concepts';
        $this->assertTrue(app(FeedbackApplicationStore::class)->reserve(
            $this->target['project'],
            $this->target['run'],
            'concepts',
            $attemptId,
            $context['feedback'],
        ));

        $this->assertContains('incomplete_feedback_application', $this->applicationCodes($attemptId));

        $path = app(CreativePathResolver::class)
            ->feedbackApplicationsPath($this->target['project'], $this->target['run']).'/'.$attemptId.'/result.md';
        File::put($path, "---\nstatus: [broken\n---\n");

        $this->assertContains('malformed_feedback_application_result', $this->applicationCodes($attemptId));
        Http::assertNothingSent();
    }

    public function test_missing_and_malformed_application_requests_are_reported(): void
    {
        $application = $this->appliedApplication();
        $attemptId = $application['attempt_id'];
        $requestPath = $application['path'].'/request.md';

        File::delete($requestPath);
        $this->assertContains(
            'feedback_application_request_missing',
            $this->applicationCodes($attemptId),
        );

        File::put($requestPath, "---\nsources: [broken\n---\n");
        $this->assertContains(
            'malformed_feedback_application_request',
            $this->applicationCodes($attemptId),
        );
        Http::assertNothingSent();
    }

    public function test_invalid_hash_and_reference_shapes_and_changed_source_bytes_are_reported(): void
    {
        $application = $this->appliedApplication();
        $attemptId = $application['attempt_id'];
        $requestPath = $application['path'].'/request.md';
        $resultPath = $application['path'].'/result.md';
        $request = MarkdownDocument::read($requestPath);
        $request['metadata']['sources'][0]['pending_body'] .= '\nChanged after reservation.';
        MarkdownDocument::write($requestPath, $request['metadata'], $request['body']);

        $codes = $this->applicationCodes($attemptId);
        $this->assertContains('feedback_application_source_hash_mismatch', $codes);
        $this->assertContains('feedback_application_generation_hash_mismatch', $codes);

        $this->rewriteMetadata($requestPath, ['generation_feedback_sha256' => 'invalid']);
        $this->rewriteMetadata($resultPath, [
            'status' => 'mystery',
            'implementation_references' => [[
                'type' => 'artifact',
                'id' => 'unsafe',
                'path' => '../../outside.md',
            ]],
        ]);

        $codes = $this->applicationCodes($attemptId);
        $this->assertContains('invalid_feedback_application_request', $codes);
        $this->assertContains('invalid_feedback_application_result', $codes);
        Http::assertNothingSent();
    }

    public function test_valid_looking_but_wrong_result_hashes_references_and_artifact_manifest_hashes_are_reported(): void
    {
        $application = $this->appliedApplication();
        $attemptId = $application['attempt_id'];
        $resultPath = $application['path'].'/result.md';
        $result = MarkdownDocument::read($resultPath);
        $wrongHash = str_repeat('f', 64);
        $result['metadata']['generation_feedback_sha256'] = $wrongHash;
        $result['metadata']['implementation_references'][0]['path'] .= '--wrong';
        MarkdownDocument::write($resultPath, $result['metadata'], $result['body']);

        $attemptResultPath = $this->attemptPath($attemptId).'/result.md';
        $attemptResult = MarkdownDocument::read($attemptResultPath);
        $artifactId = $application['result']['artifact_ids'][0];
        $attemptResult['metadata']['artifact_sha256'][$artifactId] = 'not-a-hash';
        MarkdownDocument::write(
            $attemptResultPath,
            $attemptResult['metadata'],
            $attemptResult['body'],
        );

        $codes = $this->applicationCodes($attemptId);
        $this->assertContains('feedback_application_result_hash_mismatch', $codes);
        $this->assertContains('feedback_application_references_mismatch', $codes);
        $this->assertContains('feedback_application_artifact_hash_invalid', $codes);
        Http::assertNothingSent();
    }

    public function test_coherently_changed_source_body_and_source_hash_still_break_the_generation_hash(): void
    {
        $application = $this->appliedApplication();
        $requestPath = $application['path'].'/request.md';
        $request = MarkdownDocument::read($requestPath);
        $request['metadata']['sources'][0]['pending_body'] .= '\nCoherently forged addition.';
        $request['metadata']['sources'][0]['pending_sha256'] = hash(
            'sha256',
            $request['metadata']['sources'][0]['pending_body'],
        );
        MarkdownDocument::write($requestPath, $request['metadata'], $request['body']);

        $codes = $this->applicationCodes($application['attempt_id']);
        $this->assertNotContains('feedback_application_source_hash_mismatch', $codes);
        $this->assertContains('feedback_application_generation_hash_mismatch', $codes);
        Http::assertNothingSent();
    }

    public function test_applied_lifecycle_requires_attempt_artifact_history_targets_and_matching_artifact_hashes(): void
    {
        $application = $this->appliedApplication();
        $attemptId = $application['attempt_id'];
        $phaseRoot = dirname(app(CreativePathResolver::class)->outputsPath(
            $this->target['project'],
            $this->target['run'],
            'concepts',
        ));
        File::delete($phaseRoot.'/attempts/'.$attemptId.'/request.md');

        $artifactIds = $application['result']['artifact_ids'];
        $firstArtifact = app(CreativePathResolver::class)->outputsPath(
            $this->target['project'],
            $this->target['run'],
            'concepts',
        ).'/'.$artifactIds[0].'.md';
        File::append($firstArtifact, "\ncorrupted\n");
        File::delete(dirname($firstArtifact).'/'.$artifactIds[1].'.md');

        $historyId = $application['result']['history_ids'][0];
        File::delete(app(CreativePathResolver::class)
            ->feedbackHistoryPath($this->target['project'], $this->target['run']).'/'.$historyId.'.md');

        $codes = $this->applicationCodes($attemptId);
        $this->assertContains('feedback_application_attempt_missing', $codes);
        $this->assertContains('feedback_application_artifact_hash_mismatch', $codes);
        $this->assertContains('feedback_application_artifact_missing', $codes);
        $this->assertContains('feedback_application_history_missing', $codes);
        Http::assertNothingSent();
    }

    public function test_applied_lifecycle_requires_a_terminal_attempt_result_target(): void
    {
        $application = $this->appliedApplication();
        File::delete($this->attemptPath($application['attempt_id']).'/result.md');

        $this->assertContains(
            'feedback_application_attempt_missing',
            $this->applicationCodes($application['attempt_id']),
        );
        Http::assertNothingSent();
    }

    public function test_applied_history_requires_the_exact_implementation_references(): void
    {
        $application = $this->appliedApplication();
        $historyId = $application['result']['history_ids'][0];
        $historyPath = app(CreativePathResolver::class)
            ->feedbackHistoryPath($this->target['project'], $this->target['run']).'/'.$historyId.'.md';
        $this->rewriteMetadata($historyPath, ['implementation_references' => []]);

        $this->assertContains(
            'feedback_history_references_mismatch',
            $this->applicationCodes($application['attempt_id']),
        );
        Http::assertNothingSent();
    }

    public function test_applied_history_rejects_appended_content_and_requires_an_applied_terminal_listing(): void
    {
        $application = $this->appliedApplication();
        $historyId = $application['result']['history_ids'][0];
        $historyPath = app(CreativePathResolver::class)
            ->feedbackHistoryPath($this->target['project'], $this->target['run']).'/'.$historyId.'.md';
        File::append($historyPath, "\nUnrecorded appended claim.\n");

        $this->assertContains(
            'feedback_application_history_snapshot_mismatch',
            $this->applicationCodes($application['attempt_id']),
        );

        File::delete($application['path'].'/result.md');
        $codes = $this->applicationCodes($application['attempt_id']);
        $this->assertContains('incomplete_feedback_application', $codes);
        $this->assertContains('orphaned_applied_feedback_history', $codes);

        File::deleteDirectory(app(CreativePathResolver::class)
            ->feedbackApplicationsPath($this->target['project'], $this->target['run']));
        $this->assertContains(
            'orphaned_applied_feedback_history',
            $this->applicationCodes($application['attempt_id']),
        );
        Http::assertNothingSent();
    }

    public function test_failed_not_applied_lifecycle_is_valid_but_committed_attempt_is_a_contradiction(): void
    {
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->appendFeedback(
            $this->target['project'],
            $this->target['run'],
            'concepts',
            'phase',
            'Keep this feedback pending after failure.',
        );
        $context = $workspace->phaseContext($this->target['project'], $this->target['run'], 'concepts');
        $attemptId = '20260728-120000--generation--failed-feedback--concepts';

        try {
            app(CreativeGenerationService::class)->generate(
                $this->target['project'],
                $this->target['run'],
                'concepts',
                $context['inputs'],
                $context['feedback'],
                $context['required_slots'],
                ['primary' => ['missing--source']],
                true,
                $attemptId,
            );
            $this->fail('The deliberately incorrect source contract should fail generation.');
        } catch (RuntimeException) {
            // The truthful failed attempt and not_applied application are the fixture.
        }

        $this->assertSame([], $this->applicationCodes($attemptId));

        $attemptResult = dirname(app(CreativePathResolver::class)->outputsPath(
            $this->target['project'],
            $this->target['run'],
            'concepts',
        )).'/attempts/'.$attemptId.'/result.md';
        $this->rewriteMetadata($attemptResult, ['status' => 'committed']);

        $this->assertContains(
            'feedback_not_applied_committed_attempt',
            $this->applicationCodes($attemptId),
        );
        Http::assertNothingSent();
    }

    public function test_not_applied_requires_a_complete_terminal_attempt_target(): void
    {
        $attemptId = $this->failedApplication();
        File::delete($this->attemptPath($attemptId).'/request.md');

        $this->assertContains(
            'feedback_application_attempt_missing',
            $this->applicationCodes($attemptId),
        );
        Http::assertNothingSent();
    }

    public function test_not_applied_rejects_a_non_terminal_attempt_result(): void
    {
        $attemptId = $this->failedApplication();
        $resultPath = $this->attemptPath($attemptId).'/result.md';
        $this->rewriteMetadata($resultPath, ['status' => 'running']);

        $this->assertContains(
            'feedback_not_applied_non_terminal_attempt',
            $this->applicationCodes($attemptId),
        );
        Http::assertNothingSent();
    }

    /**
     * @return array{attempt_id: string, request: array<string, mixed>, result: array<string, mixed>, path: string}
     */
    private function appliedApplication(): array
    {
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->appendFeedback(
            $this->target['project'],
            $this->target['run'],
            'concepts',
            'phase',
            'Make the opening readable without sound.',
        );
        $workspace->appendFeedback(
            $this->target['project'],
            $this->target['run'],
            'concepts',
            'cycle',
            'Keep the product present throughout.',
        );
        $workspace->simulate($this->target['project'], $this->target['run'], 'concepts', true);

        $applications = app(FeedbackApplicationStore::class)
            ->applications($this->target['project'], $this->target['run']);
        $application = $applications[array_key_last($applications)];
        $this->assertIsArray($application['request']);
        $this->assertIsArray($application['result']);

        /** @var array{attempt_id: string, request: array<string, mixed>, result: array<string, mixed>, path: string} $application */
        return $application;
    }

    private function failedApplication(): string
    {
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->appendFeedback(
            $this->target['project'],
            $this->target['run'],
            'concepts',
            'phase',
            'Keep this pending when the generation attempt fails.',
        );
        $context = $workspace->phaseContext($this->target['project'], $this->target['run'], 'concepts');
        $attemptId = '20260728-120000--generation--missing-target--concepts';

        try {
            app(CreativeGenerationService::class)->generate(
                $this->target['project'],
                $this->target['run'],
                'concepts',
                $context['inputs'],
                $context['feedback'],
                $context['required_slots'],
                ['primary' => ['missing--source']],
                true,
                $attemptId,
            );
            $this->fail('The deliberately incorrect source contract should fail generation.');
        } catch (RuntimeException) {
            // The complete failed attempt is the baseline before a target is removed.
        }

        $this->assertSame([], $this->applicationCodes($attemptId));

        return $attemptId;
    }

    private function attemptPath(string $attemptId): string
    {
        return dirname(app(CreativePathResolver::class)->outputsPath(
            $this->target['project'],
            $this->target['run'],
            'concepts',
        )).'/attempts/'.$attemptId;
    }

    /** @return list<string> */
    private function applicationCodes(string $attemptId): array
    {
        $report = app(CreativeIntegrityAuditor::class)
            ->audit($this->target['project'], $this->target['run']);

        return array_values(collect($report['issues'])
            ->where('entity', $attemptId)
            ->filter(fn (array $issue): bool => str_contains((string) $issue['code'], 'feedback'))
            ->pluck('code')
            ->all());
    }

    /** @param array<string, mixed> $changes */
    private function rewriteMetadata(string $path, array $changes): void
    {
        $document = MarkdownDocument::read($path);
        MarkdownDocument::write($path, array_merge($document['metadata'], $changes), $document['body']);
    }
}
