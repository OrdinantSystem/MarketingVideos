<?php

namespace Tests\Feature;

use App\Creative\CreativePathResolver;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\Feedback\FeedbackApplicationStore;
use App\Creative\Generation\CreativeGenerationService;
use App\Creative\Generation\GenerationAttemptStore;
use App\Creative\MarkdownDocument;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CreativeFeedbackApplicationTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-feedback-'.Str::lower(Str::random(8)));
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

    public function test_committed_generation_archives_exact_feedback_with_implementation_references(): void
    {
        $workspace = app(CreativeWorkspaceRepository::class);
        $target = $workspace->createProject('Applied feedback', 'Trace feedback into the committed batch.', 'concepts');
        $workspace->appendFeedback($target['project'], $target['run'], 'concepts', 'phase', 'Make the opening readable without sound.');
        $workspace->appendFeedback($target['project'], $target['run'], 'concepts', 'cycle', 'Keep the product present throughout.');

        $workspace->simulate($target['project'], $target['run'], 'concepts', true);

        $phase = $workspace->workspace($target['project'], $target['run'], 'concepts');
        $attemptId = $phase['artifacts'][0]['metadata']['generation_attempt_id'];
        $applications = app(FeedbackApplicationStore::class)->applications($target['project'], $target['run']);
        $this->assertCount(1, $applications);
        $this->assertSame($attemptId, $applications[0]['attempt_id']);
        $this->assertSame('applied', $applications[0]['result']['status']);
        $this->assertEqualsCanonicalizing(
            collect($phase['artifacts'])->pluck('id')->all(),
            $applications[0]['result']['artifact_ids'],
        );
        $this->assertSame([], $phase['feedback']['current']);
        $this->assertSame([], $phase['feedback']['cycle']);

        $history = File::files(app(CreativePathResolver::class)
            ->feedbackHistoryPath($target['project'], $target['run']));
        $this->assertCount(2, $history);
        foreach ($history as $file) {
            $metadata = MarkdownDocument::read($file->getPathname())['metadata'];
            $this->assertSame('applied', $metadata['status']);
            $this->assertSame($attemptId, $metadata['generation_attempt_id']);
            $this->assertCount(4, $metadata['implementation_references']);
            $this->assertStringContainsString(
                '01-concepts/attempts/'.$attemptId,
                $metadata['implementation_references'][0]['path'],
            );
        }
        Http::assertNothingSent();
    }

    public function test_failed_generation_preserves_feedback_and_records_not_applied(): void
    {
        $workspace = app(CreativeWorkspaceRepository::class);
        $target = $workspace->createProject('Failed feedback', 'Do not lose feedback on failure.', 'concepts');
        $workspace->appendFeedback($target['project'], $target['run'], 'concepts', 'phase', 'Preserve this exact note.');
        $context = $workspace->phaseContext($target['project'], $target['run'], 'concepts');
        $attemptId = now('UTC')->format('Ymd-His').'--generation--feedback-failure--concepts';

        try {
            app(CreativeGenerationService::class)->generate(
                $target['project'],
                $target['run'],
                'concepts',
                $context['inputs'],
                $context['feedback'],
                $context['required_slots'],
                ['primary' => ['missing--source']],
                true,
                $attemptId,
            );
            $this->fail('Generation should reject the deliberately incorrect source contract.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed validation', $exception->getMessage());
        }

        $application = app(FeedbackApplicationStore::class)
            ->applications($target['project'], $target['run'])[0];
        $this->assertSame('not_applied', $application['result']['status']);
        $this->assertSame([], $application['result']['implementation_references']);
        $this->assertSame(
            'failed',
            app(GenerationAttemptStore::class)
                ->attempt($target['project'], $target['run'], 'concepts', $attemptId)['result']['status'],
        );
        $feedback = $workspace->workspace($target['project'], $target['run'], 'concepts')['feedback'];
        $this->assertStringContainsString('Preserve this exact note.', $feedback['current'][0]['content']);
        $this->assertSame(0, $feedback['history_count']);
        Http::assertNothingSent();
    }

    public function test_feedback_added_after_reservation_remains_pending(): void
    {
        $workspace = app(CreativeWorkspaceRepository::class);
        $target = $workspace->createProject('Concurrent feedback', 'Preserve newly appended feedback.', 'concepts');
        $workspace->appendFeedback($target['project'], $target['run'], 'concepts', 'phase', 'Apply the first note.');
        $attemptId = now('UTC')->format('Ymd-His').'--generation--feedback-prefix--concepts';
        $applications = app(FeedbackApplicationStore::class);

        $this->assertTrue($applications->reserve(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
            'Apply the first note.',
        ));
        $workspace->appendFeedback($target['project'], $target['run'], 'concepts', 'phase', 'Keep this later note pending.');
        $applications->markApplied(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
            ['artifact--feedback-proof'],
        );

        $feedback = $workspace->workspace($target['project'], $target['run'], 'concepts')['feedback'];
        $this->assertCount(1, $feedback['current']);
        $this->assertStringNotContainsString('Apply the first note.', $feedback['current'][0]['content']);
        $this->assertStringContainsString('Keep this later note pending.', $feedback['current'][0]['content']);
        $this->assertSame('applied', $applications
            ->applications($target['project'], $target['run'])[0]['result']['status']);
    }

    public function test_feedback_appended_after_context_read_is_included_in_the_reserved_generation_payload(): void
    {
        $workspace = app(CreativeWorkspaceRepository::class);
        $target = $workspace->createProject('Atomic feedback append', 'Generate from the locked inbox snapshot.', 'concepts');
        $workspace->appendFeedback($target['project'], $target['run'], 'concepts', 'phase', 'Apply the first note.');
        $staleContext = $workspace->phaseContext($target['project'], $target['run'], 'concepts');
        $workspace->appendFeedback($target['project'], $target['run'], 'concepts', 'phase', 'Include the note appended before reservation.');
        $this->fakeConceptProvider();
        $attemptId = '20260728-120000--generation--atomic-append--concepts';

        app(CreativeGenerationService::class)->generate(
            $target['project'],
            $target['run'],
            'concepts',
            $staleContext['inputs'],
            $staleContext['feedback'],
            $staleContext['required_slots'],
            $staleContext['expected_sources_by_slot'],
            false,
            $attemptId,
        );

        Http::assertSent(function (Request $request): bool {
            $prompt = $request['messages'][1]['content'] ?? null;

            return is_string($prompt)
                && str_contains($prompt, 'Apply the first note.')
                && str_contains($prompt, 'Include the note appended before reservation.');
        });
        $application = app(FeedbackApplicationStore::class)
            ->applications($target['project'], $target['run'])[0];
        $attempt = app(GenerationAttemptStore::class)
            ->attempt($target['project'], $target['run'], 'concepts', $attemptId);
        $this->assertSame(
            $application['request']['generation_feedback_sha256'],
            $attempt['request']['generation_feedback_sha256'],
        );
        $this->assertStringContainsString(
            'Include the note appended before reservation.',
            $application['request']['sources'][0]['pending_body'],
        );
        $this->assertSame([], $workspace->workspace(
            $target['project'],
            $target['run'],
            'concepts',
        )['feedback']['current']);
        Http::assertSentCount(1);
    }

    public function test_feedback_archived_after_context_read_is_not_sent_or_claimed_as_applied(): void
    {
        $workspace = app(CreativeWorkspaceRepository::class);
        $target = $workspace->createProject('Atomic feedback archive', 'Do not generate from a stale inbox read.', 'concepts');
        $workspace->appendFeedback($target['project'], $target['run'], 'concepts', 'phase', 'Archive this before reservation.');
        $staleContext = $workspace->phaseContext($target['project'], $target['run'], 'concepts');
        $workspace->archiveFeedback($target['project'], $target['run'], 'concepts', 'phase');
        $this->fakeConceptProvider();

        app(CreativeGenerationService::class)->generate(
            $target['project'],
            $target['run'],
            'concepts',
            $staleContext['inputs'],
            $staleContext['feedback'],
            $staleContext['required_slots'],
            $staleContext['expected_sources_by_slot'],
            false,
            '20260728-120000--generation--atomic-archive--concepts',
        );

        Http::assertSent(function (Request $request): bool {
            $prompt = $request['messages'][1]['content'] ?? null;

            return is_string($prompt)
                && str_contains($prompt, '# Pending human feedback'."\n\n".'No pending feedback.')
                && ! str_contains($prompt, 'Archive this before reservation.');
        });
        $this->assertSame([], app(FeedbackApplicationStore::class)
            ->applications($target['project'], $target['run']));
        $feedback = $workspace->workspace($target['project'], $target['run'], 'concepts')['feedback'];
        $this->assertSame([], $feedback['current']);
        $this->assertCount(1, $feedback['history']);
        $this->assertSame('archived_unapplied', $feedback['history'][0]['metadata']['status']);
        Http::assertSentCount(1);
    }

    private function fakeConceptProvider(): void
    {
        config([
            'creative.generation.driver' => 'openrouter',
            'services.openrouter.key' => 'feedback-snapshot-test-key',
        ]);
        $options = collect(range(1, 3))->map(fn (int $number): array => [
            'title' => "Atomic concept {$number}",
            'summary' => "Atomic feedback concept {$number}",
            'body' => "# Atomic concept {$number}\n\n## Hook\n\nA clear opening.\n\n## Emotional turn\n\nA human change.\n\n## Product mechanism\n\nThe product enables it.\n\n## Creative hypothesis\n\nClarity improves response.\n\n## Risks or constraints\n\nKeep every claim grounded.",
        ])->all();
        Http::fakeSequence()->push([
            'id' => 'atomic-feedback-response',
            'model' => 'test-model',
            'choices' => [[
                'message' => [
                    'content' => json_encode(['options' => $options], JSON_THROW_ON_ERROR),
                ],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ]);
    }
}
