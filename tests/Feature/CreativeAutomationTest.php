<?php

namespace Tests\Feature;

use App\Creative\Automation\ArtifactEvaluator;
use App\Creative\Automation\AutomationPolicy;
use App\Creative\Automation\AutomationStateStore;
use App\Creative\Automation\CreativeRunOrchestrator;
use App\Creative\Automation\SimulationArtifactEvaluator;
use App\Creative\CreativeIntegrityAuditor;
use App\Creative\CreativePathResolver;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\Feedback\FeedbackApplicationStore;
use App\Creative\Generation\CreativeGenerationService;
use App\Creative\Generation\GenerationAttemptStore;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreativeAutomationTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-automation-'.Str::lower(Str::random(8)));
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

    public function test_a_complete_six_phase_automatic_run_uses_only_simulation_files(): void
    {
        config([
            'creative.generation.driver' => 'openrouter',
            'services.openrouter.key' => 'configured-but-forbidden-in-automatic-mode',
        ]);
        $target = $this->project('Complete automatic run', 'ideas');

        $state = app(CreativeRunOrchestrator::class)->start($target['project'], $target['run'], [
            'human_gates' => [],
        ]);

        $this->assertSame('complete', $state['status']);
        $workspace = app(CreativeWorkspaceRepository::class)->workspace($target['project'], $target['run'], 'sequence');
        $this->assertTrue(collect($workspace['phases'])->every('complete', true));
        foreach ($workspace['phases'] as $phase) {
            $phaseWorkspace = app(CreativeWorkspaceRepository::class)->workspace(
                $target['project'],
                $target['run'],
                $phase['key'],
            );
            $this->assertTrue(collect($phaseWorkspace['artifacts'])->every(
                fn (array $artifact): bool => ($artifact['metadata']['simulation'] ?? null) === true,
            ));
        }

        $events = app(AutomationStateStore::class)->events($target['project'], $target['run']);
        $this->assertContains('evaluation_recorded', array_column($events, 'event'));
        $this->assertContains('automation_completed', array_column($events, 'event'));
        $this->assertSame(0, app(CreativeIntegrityAuditor::class)
            ->audit($target['project'], $target['run'])['summary']['errors']);
        Http::assertNothingSent();
    }

    public function test_local_http_routes_require_an_explicit_gate_decision(): void
    {
        $target = $this->project('Automatic routes', 'concepts');
        $base = "/projects/{$target['project']}/runs/{$target['run']}/automation";

        $this->post($base.'/start', [
            'human_gates' => ['brief-script'],
            'max_attempts_per_phase' => 2,
            'max_total_attempts' => 12,
            'max_simulation_units' => 60,
            'score_threshold' => 80,
        ])->assertRedirect()->assertSessionHas('success');

        $paused = app(AutomationStateStore::class)->state($target['project'], $target['run']);
        $this->assertSame('awaiting_review', $paused['status']);
        $this->assertSame('brief-script', $paused['gate_phase']);

        $this->post($base.'/resume')->assertRedirect()->assertSessionHas('error');
        $this->assertSame(
            'awaiting_review',
            app(AutomationStateStore::class)->state($target['project'], $target['run'])['status'],
        );
        $this->assertCount(0, collect(app(AutomationStateStore::class)
            ->events($target['project'], $target['run']))->where('event', 'gate_decided'));
        $gateToken = $this->gateToken(app(CreativeRunOrchestrator::class), $target);
        $this->post($base.'/decision', [
            'decision' => 'approve',
            'gate_token' => $gateToken,
        ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(
            'complete',
            app(AutomationStateStore::class)->state($target['project'], $target['run'])['status'],
        );
        Http::assertNothingSent();
    }

    public function test_human_gates_pause_until_each_explicit_approval(): void
    {
        $target = $this->project('Human gates', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);

        $firstGate = $orchestrator->start($target['project'], $target['run']);
        $this->assertSame('awaiting_review', $firstGate['status']);
        $this->assertSame('brief-script', $firstGate['gate_phase']);

        $secondGate = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'approve',
            gateToken: $this->gateToken($orchestrator, $target),
        );
        $this->assertSame('awaiting_review', $secondGate['status']);
        $this->assertSame('sequence', $secondGate['gate_phase']);

        $complete = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'approve',
            gateToken: $this->gateToken($orchestrator, $target),
        );
        $this->assertSame('complete', $complete['status']);
    }

    public function test_decision_route_requires_the_current_durable_gate_token_and_rejects_a_stale_replay(): void
    {
        $target = $this->project('Durable gate token', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $orchestrator->start($target['project'], $target['run']);
        $url = "/projects/{$target['project']}/runs/{$target['run']}/automation/decision";
        $firstStatus = $orchestrator->status($target['project'], $target['run']);
        $firstToken = $firstStatus['gate']['gate_token'];

        $this->post($url, ['decision' => 'approve'])
            ->assertSessionHasErrors('gate_token');
        $this->post($url, [
            'decision' => 'approve',
            'gate_token' => $firstToken,
        ])->assertRedirect()->assertSessionHas('success');

        $secondStatus = $orchestrator->status($target['project'], $target['run']);
        $this->assertSame('awaiting_review', $secondStatus['state']['status']);
        $this->assertSame('sequence', $secondStatus['state']['gate_phase']);
        $this->assertNotSame($firstToken, $secondStatus['gate']['gate_token']);

        $this->post($url, [
            'decision' => 'approve',
            'gate_token' => $firstToken,
        ])->assertRedirect()->assertSessionHas('error');

        $afterReplay = $orchestrator->status($target['project'], $target['run']);
        $this->assertSame('awaiting_review', $afterReplay['state']['status']);
        $this->assertSame('sequence', $afterReplay['state']['gate_phase']);
        $this->assertCount(1, collect(app(AutomationStateStore::class)
            ->events($target['project'], $target['run']))->where('event', 'gate_decided'));

        $this->post($url, [
            'decision' => 'approve',
            'gate_token' => $secondStatus['gate']['gate_token'],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(
            'complete',
            $orchestrator->status($target['project'], $target['run'])['state']['status'],
        );
        Http::assertNothingSent();
    }

    public function test_projection_rejects_a_gate_decision_replayed_against_a_different_gate_token(): void
    {
        $target = $this->project('Tampered gate replay', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['brief-script'],
        ]);
        $orchestrator->decide(
            $target['project'],
            $target['run'],
            'approve',
            gateToken: $this->gateToken($orchestrator, $target),
        );
        $events = app(AutomationStateStore::class)->events($target['project'], $target['run']);
        $decision = collect($events)->firstWhere('event', 'gate_decided');
        $this->assertIsArray($decision);
        $this->assertIsString($decision['_path'] ?? null);
        $staleToken = collect($events)
            ->first(fn (array $event): bool => ($event['id'] ?? null) !== ($decision['gate_token'] ?? null))['id'] ?? null;
        $this->assertIsString($staleToken);
        $document = MarkdownDocument::read($decision['_path']);
        $metadata = $document['metadata'];
        $metadata['gate_token'] = $staleToken;
        MarkdownDocument::write($decision['_path'], $metadata, $document['body']);

        $this->assertRuntimeExceptionContains(
            fn () => $orchestrator->status($target['project'], $target['run']),
            'exact pending gate token',
        );

        $metadata['gate_token'] = $decision['gate_token'];
        $metadata['evaluation_id'] = 'evaluation-forged';
        MarkdownDocument::write($decision['_path'], $metadata, $document['body']);
        $this->assertRuntimeExceptionContains(
            fn () => $orchestrator->status($target['project'], $target['run']),
            'exact review evaluation',
        );
    }

    public function test_gate_decision_records_require_token_and_decision_specific_evidence(): void
    {
        $target = $this->project('Gate record evidence', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $status = $orchestrator->status($target['project'], $target['run']);
        $this->assertNotNull($status);
        $states = app(AutomationStateStore::class);
        $base = [
            'phase' => 'concepts',
            'decision' => 'retry_with_feedback',
            'evaluation_id' => $status['gate']['evaluation_id'],
            'selection_ids' => $status['gate']['selection_ids'],
            'feedback_sha256' => hash('sha256', 'Use a stronger opening.'),
        ];

        $this->assertRuntimeExceptionContains(
            fn () => $states->appendEvent(
                $target['project'],
                $target['run'],
                'gate_decided',
                $base,
            ),
            'gate_token',
        );
        $this->assertRuntimeExceptionContains(
            fn () => $states->appendEvent(
                $target['project'],
                $target['run'],
                'gate_decided',
                array_merge($base, [
                    'gate_token' => $status['gate']['gate_token'],
                    'feedback_sha256' => 'not-a-sha256',
                ]),
            ),
            'valid feedback hash',
        );
        $this->assertRuntimeExceptionContains(
            fn () => $states->appendEvent(
                $target['project'],
                $target['run'],
                'gate_decided',
                array_merge($base, [
                    'gate_token' => $status['gate']['gate_token'],
                    'decision' => 'approve',
                    'evaluation_id' => null,
                    'feedback_sha256' => null,
                ]),
            ),
            'exact evaluation evidence',
        );
        $this->assertRuntimeExceptionContains(
            fn () => $states->appendEvent(
                $target['project'],
                $target['run'],
                'gate_approved',
                ['phase' => 'concepts'],
            ),
            'unsupported',
        );
    }

    public function test_projection_rejects_approve_for_a_human_action_gate(): void
    {
        $target = $this->project('Human action is not approval', 'concepts');
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->simulate($target['project'], $target['run'], 'concepts', true);
        $concept = $workspace->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $workspace->select($target['project'], $target['run'], 'concepts', $concept['id']);
        $orchestrator = app(CreativeRunOrchestrator::class);
        $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $status = $orchestrator->status($target['project'], $target['run']);
        $this->assertNotNull($status);
        $this->assertSame('human_action', $status['gate']['kind']);
        app(AutomationStateStore::class)->appendEvent(
            $target['project'],
            $target['run'],
            'gate_decided',
            [
                'phase' => 'concepts',
                'gate_token' => $status['gate']['gate_token'],
                'decision' => 'approve',
                'evaluation_id' => 'evaluation-forged',
                'selection_ids' => $status['gate']['selection_ids'],
                'feedback_sha256' => null,
            ],
        );

        $this->assertRuntimeExceptionContains(
            fn () => $orchestrator->status($target['project'], $target['run']),
            'exact review evaluation',
        );
    }

    public function test_a_failed_evaluation_retries_without_overwriting_the_first_attempt(): void
    {
        $realEvaluator = new SimulationArtifactEvaluator;
        $failOnce = new class($realEvaluator) implements ArtifactEvaluator
        {
            private int $calls = 0;

            public function __construct(private readonly SimulationArtifactEvaluator $real) {}

            public function evaluate(string $phase, array $artifacts, array $requiredSlots, int $threshold): array
            {
                $evaluation = $this->real->evaluate($phase, $artifacts, $requiredSlots, $threshold);
                $this->calls++;

                if ($this->calls === 1) {
                    $evaluation['passed'] = false;
                    $evaluation['recommendations'] = [];
                    $evaluation['fatal_issues'] = ['Scripted first-attempt rejection.'];
                    $evaluation['diagnosis'] = 'Scripted first-attempt rejection.';
                }

                return $evaluation;
            }
        };
        $this->app->instance(ArtifactEvaluator::class, $failOnce);
        $target = $this->project('Retry run', 'concepts');

        $state = app(CreativeRunOrchestrator::class)->start($target['project'], $target['run'], [
            'human_gates' => [],
            'max_attempts_per_phase' => 2,
        ]);

        $this->assertSame('complete', $state['status']);
        $this->assertSame(2, $state['attempts_by_phase']['concepts']);
        $concepts = app(CreativeWorkspaceRepository::class)->workspace($target['project'], $target['run'], 'concepts');
        $this->assertCount(6, $concepts['artifacts']);
        $attemptIds = collect($concepts['artifacts'])->pluck('metadata.generation_attempt_id')->unique();
        $this->assertCount(2, $attemptIds);
    }

    public function test_attempt_and_budget_limits_pause_for_manual_action(): void
    {
        $alwaysFail = new class(new SimulationArtifactEvaluator) implements ArtifactEvaluator
        {
            public function __construct(private readonly SimulationArtifactEvaluator $real) {}

            public function evaluate(string $phase, array $artifacts, array $requiredSlots, int $threshold): array
            {
                $evaluation = $this->real->evaluate($phase, $artifacts, $requiredSlots, $threshold);
                $evaluation['passed'] = false;
                $evaluation['recommendations'] = [];
                $evaluation['fatal_issues'] = ['No candidate accepted.'];
                $evaluation['diagnosis'] = 'No candidate accepted.';

                return $evaluation;
            }
        };
        $this->app->instance(ArtifactEvaluator::class, $alwaysFail);
        $target = $this->project('Attempt limit', 'concepts');
        $limited = app(CreativeRunOrchestrator::class)->start($target['project'], $target['run'], [
            'human_gates' => [],
            'max_attempts_per_phase' => 1,
        ]);

        $this->assertSame('awaiting_review', $limited['status']);
        $this->assertSame('human_action', $limited['step']);
        $this->assertStringContainsString('retry limit', (string) $limited['gate_reason']);

        $this->app->forgetInstance(ArtifactEvaluator::class);
        $budgetTarget = $this->project('Unit budget', 'concepts');
        $budgeted = app(CreativeRunOrchestrator::class)->start($budgetTarget['project'], $budgetTarget['run'], [
            'human_gates' => [],
            'max_simulation_units' => 1,
        ]);

        $this->assertSame('awaiting_review', $budgeted['status']);
        $this->assertSame('brief-script', $budgeted['current_phase']);
        $this->assertStringContainsString('budget', (string) $budgeted['gate_reason']);
    }

    public function test_cancellation_preserves_files_and_resume_continues(): void
    {
        $target = $this->project('Cancel and resume', 'ideas');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $paused = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['ideas'],
        ]);
        $this->assertSame('awaiting_review', $paused['status']);
        $before = count(File::allFiles($this->creativeRoot));

        $cancelled = $orchestrator->cancel($target['project'], $target['run']);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertGreaterThanOrEqual($before, count(File::allFiles($this->creativeRoot)));

        $resumed = $orchestrator->resume($target['project'], $target['run']);
        $this->assertSame('awaiting_review', $resumed['status']);
        $this->assertSame('ideas', $resumed['gate_phase']);
        $completed = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'manual_override',
            gateToken: $this->gateToken($orchestrator, $target),
        );
        $this->assertSame('complete', $completed['status']);
        $events = app(AutomationStateStore::class)->events($target['project'], $target['run']);
        $this->assertContains('automation_cancelled', array_column($events, 'event'));
        $this->assertContains('automation_resumed', array_column($events, 'event'));
    }

    public function test_cancellation_preserves_a_committed_active_attempt_for_evaluation_after_resume(): void
    {
        $target = $this->project('Cancel committed attempt', 'concepts');
        $states = app(AutomationStateStore::class);
        $states->start(
            $target['project'],
            $target['run'],
            $states->newId('automation'),
            app(AutomationPolicy::class)->make('concepts', ['human_gates' => ['concepts']]),
        );
        $orchestrator = app(CreativeRunOrchestrator::class);

        $generated = $orchestrator->step($target['project'], $target['run']);

        $this->assertSame('evaluating', $generated['step']);
        $attemptId = $generated['active_attempt_id'];
        $this->assertIsString($attemptId);
        $attempt = app(GenerationAttemptStore::class)->attempt(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
        );
        $this->assertSame('committed', $attempt['result']['status']);

        $cancelled = $orchestrator->cancel($target['project'], $target['run']);
        $this->assertSame('cancelled', $cancelled['status']);
        $events = collect($states->events($target['project'], $target['run']));
        $this->assertCount(1, $events->where('event', 'attempt_committed')->where('attempt_id', $attemptId));
        $this->assertCount(0, $events->where('event', 'attempt_interrupted')->where('attempt_id', $attemptId));

        $resumed = $orchestrator->resume($target['project'], $target['run']);

        $this->assertSame('awaiting_review', $resumed['status']);
        $this->assertSame('concepts', $resumed['gate_phase']);
        $this->assertSame(1, $resumed['attempts_by_phase']['concepts']);
        $this->assertCount(1, app(GenerationAttemptStore::class)->attempts(
            $target['project'],
            $target['run'],
            'concepts',
        ));
        $this->assertSame(0, app(CreativeIntegrityAuditor::class)
            ->audit($target['project'], $target['run'])['summary']['errors']);
    }

    public function test_cancellation_reconstructs_and_terminalizes_an_event_only_attempt(): void
    {
        $target = $this->project('Cancel event-only attempt', 'concepts');
        $context = app(CreativeWorkspaceRepository::class)
            ->phaseContext($target['project'], $target['run'], 'concepts');
        $attemptId = app(CreativeGenerationService::class)->newAttemptId('concepts');
        $eventId = $this->startEventOnlyAttempt($target, $context, $attemptId, hash('sha256', ''));

        $cancelled = app(CreativeRunOrchestrator::class)->cancel($target['project'], $target['run']);

        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame(1, $cancelled['total_attempts']);
        $attempt = app(GenerationAttemptStore::class)->attempt(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
        );
        $this->assertSame($eventId, $attempt['request']['recovered_from_event_id']);
        $this->assertSame('cancelled', $attempt['result']['status']);
        $this->assertSame([], $attempt['result']['artifact_ids']);
        $events = collect(app(AutomationStateStore::class)->events($target['project'], $target['run']));
        $this->assertCount(1, $events->where('event', 'attempt_interrupted')->where('attempt_id', $attemptId));
        $this->assertSame(0, app(CreativeIntegrityAuditor::class)
            ->audit($target['project'], $target['run'])['summary']['errors']);
    }

    public function test_projection_rejects_a_cancelled_ghost_attempt_reservation(): void
    {
        $target = $this->project('Cancelled ghost reservation', 'concepts');
        $context = app(CreativeWorkspaceRepository::class)
            ->phaseContext($target['project'], $target['run'], 'concepts');
        $attemptId = app(CreativeGenerationService::class)->newAttemptId('concepts');
        $this->startEventOnlyAttempt($target, $context, $attemptId, hash('sha256', ''));
        app(AutomationStateStore::class)->appendEvent(
            $target['project'],
            $target['run'],
            'automation_cancelled',
            [
                'phase' => 'concepts',
                'active_attempt_id' => $attemptId,
                'selection_ids' => [],
            ],
            'Simulate a legacy cancellation that skipped attempt reconstruction.',
        );

        $this->assertRuntimeExceptionContains(
            fn () => app(CreativeRunOrchestrator::class)->status($target['project'], $target['run']),
            'no complete request/result evidence',
        );
        $report = app(CreativeIntegrityAuditor::class)->audit($target['project'], $target['run']);
        $this->assertContains('automation_replay_invalid', array_column($report['issues'], 'code'));
    }

    public function test_resume_marks_an_uncommitted_attempt_interrupted_before_retrying(): void
    {
        $target = $this->project('Interrupted attempt', 'concepts');
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->appendFeedback(
            $target['project'],
            $target['run'],
            'concepts',
            'phase',
            'Keep this exact note pending after interruption.',
        );
        $context = $workspace->phaseContext($target['project'], $target['run'], 'concepts');
        $policy = app(AutomationPolicy::class)->make('concepts', ['human_gates' => []]);
        $states = app(AutomationStateStore::class);
        $automationId = $states->newId('automation');
        $states->start($target['project'], $target['run'], $automationId, $policy);
        $generation = app(CreativeGenerationService::class);
        $attemptId = $generation->newAttemptId('concepts');
        $states->appendEvent($target['project'], $target['run'], 'attempt_started', [
            'phase' => 'concepts',
            'attempt_id' => $attemptId,
            'attempt_number' => 1,
            'reserved_simulation_units' => 1,
            'prior_evaluation_id' => null,
        ], 'Test reservation interrupted before a terminal result was written.');
        app(GenerationAttemptStore::class)->begin(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
            [
                'automation_id' => $automationId,
                'attempt_number' => 1,
                'prior_evaluation_id' => null,
                'prior_evaluation_diagnosis' => null,
                'retry_context_hash' => null,
                'retry_decision_event_id' => null,
                'decision_feedback_sha256' => null,
                'generation_feedback_sha256' => hash('sha256', ''),
                'lineage_hash' => hash('sha256', $target['project']),
                'driver' => 'simulation',
                'source_artifact_ids' => [$target['project']],
                'required_slots' => ['primary'],
                'expected_output_count' => 3,
                'reserved_simulation_units' => 1,
                'cost_limit_usd' => 0.0,
            ],
        );
        app(FeedbackApplicationStore::class)->reserve(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
            $context['feedback'],
        );

        $orchestrator = app(CreativeRunOrchestrator::class);
        $recovered = $orchestrator->step($target['project'], $target['run']);

        $this->assertSame('pending', $recovered['status']);
        $interrupted = app(GenerationAttemptStore::class)->attempt(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
        );
        $this->assertSame('interrupted', $interrupted['result']['status']);
        $this->assertSame('simulation', $interrupted['result']['driver']);
        $this->assertSame(3, $interrupted['result']['expected_output_count']);
        $this->assertSame(1, $interrupted['result']['simulation_units']);
        $this->assertSame(0.0, $interrupted['result']['cost_usd']);
        $application = collect(app(FeedbackApplicationStore::class)
            ->applications($target['project'], $target['run']))
            ->firstWhere('attempt_id', $attemptId);
        $this->assertIsArray($application);
        $this->assertSame('not_applied', $application['result']['status']);
        $this->assertStringContainsString(
            'Keep this exact note pending after interruption.',
            File::get(app(CreativePathResolver::class)->currentFeedbackPath(
                $target['project'],
                $target['run'],
                'concepts',
            )),
        );

        $state = $orchestrator->runUntilPause($target['project'], $target['run']);
        $this->assertSame('complete', $state['status']);
        $this->assertSame(2, $state['attempts_by_phase']['concepts']);
    }

    public function test_resume_reconstructs_an_event_only_attempt_as_a_valid_interrupted_pair(): void
    {
        $target = $this->project('Event-only attempt recovery', 'concepts');
        $context = app(CreativeWorkspaceRepository::class)
            ->phaseContext($target['project'], $target['run'], 'concepts');
        $attemptId = app(CreativeGenerationService::class)->newAttemptId('concepts');
        $eventId = $this->startEventOnlyAttempt($target, $context, $attemptId, hash('sha256', ''));

        $recovered = app(CreativeRunOrchestrator::class)->step($target['project'], $target['run']);

        $this->assertSame('pending', $recovered['status']);
        $attempt = app(GenerationAttemptStore::class)->attempt(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
        );
        $this->assertSame($eventId, $attempt['request']['recovered_from_event_id']);
        $this->assertSame('simulation', $attempt['request']['driver']);
        $this->assertSame('interrupted', $attempt['result']['status']);
        $this->assertSame([], $attempt['result']['artifact_ids']);
        $this->assertSame(3, $attempt['result']['expected_output_count']);

        $complete = app(CreativeRunOrchestrator::class)->runUntilPause($target['project'], $target['run']);
        $this->assertSame('complete', $complete['status']);
        $this->assertSame(2, $complete['attempts_by_phase']['concepts']);
        $this->assertSame(0, app(CreativeIntegrityAuditor::class)
            ->audit($target['project'], $target['run'])['summary']['errors']);
    }

    public function test_resume_terminalizes_feedback_reserved_before_the_attempt_request_without_clearing_it(): void
    {
        $target = $this->project('Feedback-only reservation recovery', 'concepts');
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->appendFeedback(
            $target['project'],
            $target['run'],
            'concepts',
            'phase',
            'Keep this exact note pending after reservation recovery.',
        );
        $context = $workspace->phaseContext($target['project'], $target['run'], 'concepts');
        $attemptId = app(CreativeGenerationService::class)->newAttemptId('concepts');
        $reservation = app(FeedbackApplicationStore::class)->reserveGenerationFeedback(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
            '',
        );
        $this->startEventOnlyAttempt(
            $target,
            $context,
            $attemptId,
            $reservation['generation_feedback_sha256'],
        );

        $recovered = app(CreativeRunOrchestrator::class)->step($target['project'], $target['run']);

        $this->assertSame('pending', $recovered['status']);
        $attempt = app(GenerationAttemptStore::class)->attempt(
            $target['project'],
            $target['run'],
            'concepts',
            $attemptId,
        );
        $this->assertSame(
            $reservation['generation_feedback_sha256'],
            $attempt['request']['generation_feedback_sha256'],
        );
        $this->assertSame('interrupted', $attempt['result']['status']);
        $application = collect(app(FeedbackApplicationStore::class)
            ->applications($target['project'], $target['run']))
            ->firstWhere('attempt_id', $attemptId);
        $this->assertIsArray($application);
        $this->assertSame('not_applied', $application['result']['status']);
        $this->assertStringContainsString(
            'Keep this exact note pending after reservation recovery.',
            File::get(app(CreativePathResolver::class)->currentFeedbackPath(
                $target['project'],
                $target['run'],
                'concepts',
            )),
        );
        $complete = app(CreativeRunOrchestrator::class)->runUntilPause($target['project'], $target['run']);
        $this->assertSame('complete', $complete['status']);
        $this->assertSame(0, app(CreativeIntegrityAuditor::class)
            ->audit($target['project'], $target['run'])['summary']['errors']);
    }

    public function test_resume_applies_reserved_feedback_when_the_generation_result_committed_before_feedback_terminalization(): void
    {
        $target = $this->project('Committed feedback recovery', 'concepts');
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->appendFeedback(
            $target['project'],
            $target['run'],
            'concepts',
            'phase',
            'Apply the snapshot committed by the recovered attempt.',
        );
        $context = $workspace->phaseContext($target['project'], $target['run'], 'concepts');
        $policy = app(AutomationPolicy::class)->make('concepts', ['human_gates' => []]);
        $states = app(AutomationStateStore::class);
        $automationId = $states->newId('automation');
        $states->start($target['project'], $target['run'], $automationId, $policy);
        $generation = app(CreativeGenerationService::class);
        $attemptId = $generation->newAttemptId('concepts');
        $states->appendEvent($target['project'], $target['run'], 'attempt_started', [
            'phase' => 'concepts',
            'attempt_id' => $attemptId,
            'attempt_number' => 1,
            'reserved_simulation_units' => 1,
            'prior_evaluation_id' => null,
        ], 'Test interruption after committed generation but before feedback terminalization.');
        $generation->generate(
            $target['project'],
            $target['run'],
            'concepts',
            $context['inputs'],
            $context['feedback'],
            $context['required_slots'],
            $context['expected_sources_by_slot'],
            true,
            $attemptId,
            [
                'automation_id' => $automationId,
                'attempt_number' => 1,
                'prior_evaluation_id' => null,
                'prior_evaluation_diagnosis' => null,
                'retry_context_hash' => null,
                'retry_decision_event_id' => null,
                'decision_feedback_sha256' => null,
                'generation_feedback_sha256' => hash('sha256', $context['feedback']),
                'lineage_hash' => hash('sha256', $target['project']),
            ],
        );

        $applications = app(FeedbackApplicationStore::class);
        $application = collect($applications->applications($target['project'], $target['run']))
            ->firstWhere('attempt_id', $attemptId);
        $this->assertIsArray($application);
        $snapshot = $application['request']['sources'][0]['pending_body'];
        File::delete($application['path'].'/result.md');
        File::delete(File::files(app(CreativePathResolver::class)
            ->feedbackHistoryPath($target['project'], $target['run'])));
        $currentPath = app(CreativePathResolver::class)->currentFeedbackPath(
            $target['project'],
            $target['run'],
            'concepts',
        );
        $current = MarkdownDocument::read($currentPath);
        MarkdownDocument::write(
            $currentPath,
            $current['metadata'],
            "# Current feedback · concepts\n\n{$snapshot}",
        );
        $workspace->appendFeedback(
            $target['project'],
            $target['run'],
            'concepts',
            'phase',
            'Keep this concurrently appended note pending.',
        );

        $recovered = app(CreativeRunOrchestrator::class)->step($target['project'], $target['run']);

        $this->assertSame('selecting', $recovered['step']);
        $application = collect($applications->applications($target['project'], $target['run']))
            ->firstWhere('attempt_id', $attemptId);
        $this->assertSame('applied', $application['result']['status']);
        $this->assertCount(1, File::files(app(CreativePathResolver::class)
            ->feedbackHistoryPath($target['project'], $target['run'])));
        $currentBody = File::get($currentPath);
        $this->assertStringNotContainsString('Apply the snapshot committed', $currentBody);
        $this->assertStringContainsString('Keep this concurrently appended note pending.', $currentBody);
    }

    public function test_resume_backfills_a_missing_selection_event_from_the_exact_automatic_selection_record(): void
    {
        $target = $this->project('Interrupted selection event', 'concepts');
        $states = app(AutomationStateStore::class);
        $policy = app(AutomationPolicy::class)->make('concepts', [
            'human_gates' => [],
            'score_threshold' => 0,
        ]);
        $states->start(
            $target['project'],
            $target['run'],
            $states->newId('automation'),
            $policy,
        );
        $orchestrator = app(CreativeRunOrchestrator::class);

        $generated = $orchestrator->step($target['project'], $target['run']);
        $this->assertSame('evaluating', $generated['step']);
        $evaluated = $orchestrator->step($target['project'], $target['run']);
        $this->assertSame('selecting', $evaluated['step']);
        $evaluationId = $evaluated['latest_evaluation_id'];
        $evaluation = $states->evaluation(
            $target['project'],
            $target['run'],
            'concepts',
            $evaluationId,
        );
        $artifactId = $evaluation['recommendations']['primary'];

        app(CreativeWorkspaceRepository::class)->select(
            $target['project'],
            $target['run'],
            'concepts',
            $artifactId,
            'automation',
            $evaluationId,
            'Simulate interruption after the immutable selection write.',
        );
        $this->assertCount(0, collect($states->events($target['project'], $target['run']))
            ->where('event', 'selection_recorded'));

        $recovered = $orchestrator->step($target['project'], $target['run']);

        $this->assertSame('brief-script', $recovered['current_phase']);
        $selectionEvents = collect($states->events($target['project'], $target['run']))
            ->where('event', 'selection_recorded')
            ->values();
        $this->assertCount(1, $selectionEvents);
        $this->assertSame('primary', $selectionEvents[0]['slot']);
        $this->assertSame($artifactId, $selectionEvents[0]['artifact_id']);
        $this->assertSame($evaluationId, $selectionEvents[0]['evaluation_id']);

        $replayed = $orchestrator->status($target['project'], $target['run']);
        $this->assertNotNull($replayed);
        $this->assertSame('pending', $replayed['state']['status']);
        $this->assertSame('brief-script', $replayed['state']['current_phase']);
        $this->assertTrue($replayed['checkpoint_consistent']);
        $this->assertSame([], $replayed['projection_issues']);
        $this->assertCount(1, collect($states->events($target['project'], $target['run']))
            ->where('event', 'selection_recorded'));
    }

    public function test_resume_recovers_a_policy_only_interrupted_bootstrap(): void
    {
        $target = $this->project('Interrupted bootstrap', 'concepts');
        $automationId = 'automation-interrupted-bootstrap';
        $policy = app(AutomationPolicy::class)->make('concepts', ['human_gates' => []]);
        $policyPath = $this->creativeRoot."/{$target['project']}/runs/{$target['run']}/automation/policy.md";
        MarkdownDocument::write($policyPath, array_merge($policy, [
            'id' => $automationId,
            'type' => 'automation_policy',
            'automation_id' => $automationId,
            'created_at' => now('UTC')->toIso8601String(),
        ]), '# Interrupted automatic policy', false);
        $policyHash = hash_file('sha256', $policyPath);

        $state = app(CreativeRunOrchestrator::class)->resume($target['project'], $target['run']);

        $this->assertSame('complete', $state['status']);
        $this->assertSame($policyHash, hash_file('sha256', $policyPath));
        $events = app(AutomationStateStore::class)->events($target['project'], $target['run']);
        $this->assertContains('automation_bootstrap_recovered', array_column($events, 'event'));
    }

    public function test_projection_recovers_a_missing_checkpoint_without_resetting_attempts_or_a_pending_gate(): void
    {
        $target = $this->project('Lost checkpoint', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $paused = $orchestrator->start($target['project'], $target['run']);
        $this->assertSame('brief-script', $paused['gate_phase']);
        $attemptsBefore = $paused['attempts_by_phase'];
        $unitsBefore = $paused['simulation_units_spent'];
        $statePath = app(CreativePathResolver::class)
            ->runPath($target['project'], $target['run']).'/automation/state.md';
        File::delete($statePath);

        $projected = $orchestrator->status($target['project'], $target['run']);

        $this->assertNotNull($projected);
        $this->assertTrue($projected['recovery_available']);
        $this->assertFalse($projected['checkpoint_consistent']);
        $this->assertSame('awaiting_review', $projected['state']['status']);
        $this->assertSame('brief-script', $projected['state']['gate_phase']);
        $this->assertSame($attemptsBefore, $projected['state']['attempts_by_phase']);
        $this->assertSame($unitsBefore, $projected['state']['simulation_units_spent']);

        try {
            $orchestrator->resume($target['project'], $target['run']);
            $this->fail('Resume must not decide a reconstructed human gate.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('explicit gate decision', $exception->getMessage());
        }
        $nextGate = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'approve',
            gateToken: $projected['gate']['gate_token'],
        );
        $this->assertSame('awaiting_review', $nextGate['status']);
        $this->assertSame('sequence', $nextGate['gate_phase']);
        $this->assertGreaterThan($unitsBefore, $nextGate['simulation_units_spent']);
        $events = app(AutomationStateStore::class)->events($target['project'], $target['run']);
        $this->assertContains('automation_bootstrap_recovered', array_column($events, 'event'));
        $this->assertContains('checkpoint_reconciled', array_column($events, 'event'));
    }

    public function test_projection_recovers_a_reused_configured_gate_before_its_gate_request_was_written(): void
    {
        $target = $this->project('Interrupted reused gate', 'concepts');
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->simulate($target['project'], $target['run'], 'concepts', true);
        $concept = $workspace->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $workspace->select($target['project'], $target['run'], 'concepts', $concept['id']);
        $states = app(AutomationStateStore::class);
        $policy = app(AutomationPolicy::class)->make('concepts', [
            'human_gates' => ['concepts'],
        ]);
        $states->start(
            $target['project'],
            $target['run'],
            $states->newId('automation'),
            $policy,
        );
        $states->appendEvent($target['project'], $target['run'], 'phase_reused', [
            'phase' => 'concepts',
        ], 'The process stopped after recording reuse but before recording its configured gate request.');
        File::delete(app(CreativePathResolver::class)
            ->runPath($target['project'], $target['run']).'/automation/state.md');
        $orchestrator = app(CreativeRunOrchestrator::class);

        $projected = $orchestrator->status($target['project'], $target['run']);

        $this->assertNotNull($projected);
        $this->assertTrue($projected['recovery_available']);
        $this->assertSame('awaiting_review', $projected['state']['status']);
        $this->assertSame('human_action', $projected['state']['step']);
        $this->assertSame('concepts', $projected['state']['gate_phase']);
        $gateToken = $projected['gate']['gate_token'];
        try {
            $orchestrator->resume($target['project'], $target['run']);
            $this->fail('Resume must not bypass a configured gate recovered from phase reuse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('explicit gate decision', $exception->getMessage());
        }
        $recovered = $orchestrator->status($target['project'], $target['run']);
        $this->assertSame($gateToken, $recovered['gate']['gate_token']);

        $complete = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'manual_override',
            null,
            $gateToken,
        );
        $this->assertSame('complete', $complete['status']);
        $this->assertCount(1, collect($states->events($target['project'], $target['run']))
            ->where('event', 'gate_decided')
            ->where('phase', 'concepts'));
    }

    public function test_projection_detects_and_repairs_a_valid_but_inconsistent_checkpoint(): void
    {
        $target = $this->project('Inconsistent checkpoint', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $paused = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['brief-script'],
        ]);
        $statePath = app(CreativePathResolver::class)
            ->runPath($target['project'], $target['run']).'/automation/state.md';
        $document = MarkdownDocument::read($statePath);
        $tampered = $document['metadata'];
        $tampered['attempts_by_phase']['concepts'] = 2;
        $tampered['total_attempts'] = array_sum($tampered['attempts_by_phase']);
        $tampered['simulation_units_spent'] = 50;
        MarkdownDocument::write($statePath, $tampered, $document['body']);

        $status = $orchestrator->status($target['project'], $target['run']);

        $this->assertNotNull($status);
        $this->assertFalse($status['checkpoint_consistent']);
        $this->assertSame($paused['total_attempts'], $status['state']['total_attempts']);
        $this->assertSame($paused['simulation_units_spent'], $status['state']['simulation_units_spent']);
        try {
            $orchestrator->resume($target['project'], $target['run']);
            $this->fail('Resume must not decide a repaired human gate.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('explicit gate decision', $exception->getMessage());
        }
        $complete = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'approve',
            gateToken: $status['gate']['gate_token'],
        );
        $this->assertSame('complete', $complete['status']);
        $this->assertLessThan(50, $complete['simulation_units_spent']);
        $events = app(AutomationStateStore::class)->events($target['project'], $target['run']);
        $this->assertContains('checkpoint_reconciled', array_column($events, 'event'));
    }

    public function test_projection_repairs_a_malformed_mutable_checkpoint_from_immutable_evidence(): void
    {
        $target = $this->project('Malformed checkpoint', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $paused = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $statePath = app(CreativePathResolver::class)
            ->runPath($target['project'], $target['run']).'/automation/state.md';
        File::put($statePath, "---\nstatus: [unterminated\n---\n");

        $status = $orchestrator->status($target['project'], $target['run']);

        $this->assertNotNull($status);
        $this->assertTrue($status['recovery_available']);
        $this->assertNotEmpty($status['checkpoint_error']);
        $this->assertSame('awaiting_review', $status['state']['status']);
        $this->assertSame($paused['total_attempts'], $status['state']['total_attempts']);
        $complete = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'approve',
            gateToken: $status['gate']['gate_token'],
        );
        $this->assertSame('complete', $complete['status']);
        $events = app(AutomationStateStore::class)->events($target['project'], $target['run']);
        $this->assertContains('checkpoint_reconciled', array_column($events, 'event'));
    }

    public function test_a_complete_checkpoint_reopens_and_repairs_when_upstream_selection_changes(): void
    {
        $target = $this->project('Reconcile complete run', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $complete = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => [],
        ]);
        $this->assertSame('complete', $complete['status']);
        $workspace = app(CreativeWorkspaceRepository::class);
        $concepts = $workspace->workspace($target['project'], $target['run'], 'concepts');
        $alternative = collect($concepts['artifacts'])->first(
            fn (array $artifact): bool => ($artifact['selected'] ?? false) !== true,
        );
        $this->assertIsArray($alternative);
        $workspace->select($target['project'], $target['run'], 'concepts', $alternative['id']);

        $reopened = $orchestrator->status($target['project'], $target['run']);

        $this->assertNotNull($reopened);
        $this->assertSame('pending', $reopened['state']['status']);
        $this->assertSame('repair', $reopened['state']['step']);
        $this->assertSame('brief-script', $reopened['state']['current_phase']);
        $this->assertNotEmpty($reopened['projection_issues']);

        $repaired = $orchestrator->resume($target['project'], $target['run']);
        $this->assertSame('complete', $repaired['status']);
        $this->assertSame(2, $repaired['attempts_by_phase']['brief-script']);
        $events = app(AutomationStateStore::class)->events($target['project'], $target['run']);
        $this->assertContains('completed_checkpoint_reopened', array_column($events, 'event'));
    }

    public function test_explicit_manual_override_records_exact_selections_and_is_idempotent(): void
    {
        $target = $this->project('Manual override decision', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $paused = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $this->assertSame('concepts', $paused['gate_phase']);
        $workspace = app(CreativeWorkspaceRepository::class);
        $concepts = $workspace->workspace($target['project'], $target['run'], 'concepts');
        $alternative = collect($concepts['artifacts'])->first(
            fn (array $artifact): bool => ($artifact['selected'] ?? false) !== true,
        );
        $this->assertIsArray($alternative);
        $workspace->select($target['project'], $target['run'], 'concepts', $alternative['id']);
        $gateToken = $this->gateToken($orchestrator, $target);

        try {
            $orchestrator->decide(
                $target['project'],
                $target['run'],
                'approve',
                gateToken: $gateToken,
            );
            $this->fail('Approve should reject selections that differ from the recommendation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('manual_override', $exception->getMessage());
        }

        $completed = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'manual_override',
            gateToken: $gateToken,
        );
        $this->assertSame('complete', $completed['status']);
        $same = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'manual_override',
            gateToken: $gateToken,
        );
        $this->assertSame($completed['total_attempts'], $same['total_attempts']);
        $decisions = collect(app(AutomationStateStore::class)->events($target['project'], $target['run']))
            ->where('event', 'gate_decided')
            ->values();
        $this->assertCount(1, $decisions);
        $this->assertSame('manual_override', $decisions[0]['decision']);
        $this->assertSame(['primary' => $alternative['id']], $decisions[0]['selection_ids']);
    }

    public function test_retry_with_feedback_links_prior_diagnosis_and_improves_the_simulated_rubric(): void
    {
        $target = $this->project('Retry decision', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $paused = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $priorEvaluationId = $paused['latest_evaluation_id'];

        $retried = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'retry_with_feedback',
            'Make the product mechanism more explicit in the opening beat.',
            $this->gateToken($orchestrator, $target),
        );

        $this->assertSame('awaiting_review', $retried['status']);
        $this->assertSame('concepts', $retried['gate_phase']);
        $this->assertSame(2, $retried['attempts_by_phase']['concepts']);
        $attempts = app(GenerationAttemptStore::class)->attempts(
            $target['project'],
            $target['run'],
            'concepts',
        );
        $latestAttempt = collect($attempts)->first(
            fn (array $attempt): bool => ($attempt['request']['attempt_number'] ?? null) === 2,
        );
        $this->assertIsArray($latestAttempt);
        $this->assertSame($priorEvaluationId, $latestAttempt['request']['prior_evaluation_id']);
        $this->assertNotEmpty($latestAttempt['request']['prior_evaluation_diagnosis']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $latestAttempt['request']['retry_context_hash']);
        $retryDecision = collect(app(AutomationStateStore::class)
            ->events($target['project'], $target['run']))
            ->first(fn (array $event): bool => ($event['event'] ?? null) === 'gate_decided'
                && ($event['decision'] ?? null) === 'retry_with_feedback');
        $this->assertIsArray($retryDecision);
        $this->assertSame($retryDecision['id'], $latestAttempt['request']['retry_decision_event_id']);
        $this->assertSame($retryDecision['feedback_sha256'], $latestAttempt['request']['decision_feedback_sha256']);
        $phaseState = app(CreativeWorkspaceRepository::class)->automationPhaseState(
            $target['project'],
            $target['run'],
            'concepts',
        );
        $retryArtifacts = collect($phaseState['artifacts'])
            ->where('metadata.generation_attempt_id', $latestAttempt['attempt_id']);
        $this->assertCount(3, $retryArtifacts);
        $this->assertTrue($retryArtifacts->every(
            fn (array $artifact): bool => ($artifact['metadata']['retry_context_applied'] ?? null) === true
                && ($artifact['metadata']['retry_context_sha256'] ?? null)
                    === $latestAttempt['request']['generation_feedback_sha256'],
        ));
        $latestEvaluation = app(AutomationStateStore::class)->evaluation(
            $target['project'],
            $target['run'],
            'concepts',
            $retried['latest_evaluation_id'],
        );
        $this->assertSame('structural_eligibility', $latestEvaluation['rubric_name']);
        $this->assertTrue(collect($latestEvaluation['scores'])->every(
            fn (array $score): bool => ($score['dimensions']['retry_context_applied']['met'] ?? null) === true,
        ));
        $feedbackPath = app(CreativePathResolver::class)->currentFeedbackPath(
            $target['project'],
            $target['run'],
            'concepts',
        );
        $this->assertStringNotContainsString('Make the product mechanism', File::get($feedbackPath));
        $historyPath = app(CreativePathResolver::class)
            ->feedbackHistoryPath($target['project'], $target['run']);
        $history = File::isDirectory($historyPath) ? File::files($historyPath) : [];
        $this->assertCount(1, $history);
        $historyMetadata = MarkdownDocument::read($history[0]->getPathname())['metadata'];
        $this->assertSame('applied', $historyMetadata['status']);
        $this->assertSame($latestAttempt['attempt_id'], $historyMetadata['generation_attempt_id']);
        $this->assertSame(
            $latestAttempt['request']['generation_feedback_sha256'],
            $historyMetadata['implementation_references'][0]['generation_feedback_sha256'] ?? null,
        );
    }

    public function test_retry_decision_reuses_feedback_durably_appended_before_its_event_without_duplicating_it(): void
    {
        $target = $this->project('Interrupted retry decision', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $paused = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $gateToken = $this->gateToken($orchestrator, $target);
        $feedback = 'Use one exact durable retry note.';
        $marker = "<!-- videoauto:automation-retry gate_token={$gateToken} feedback_sha256="
            .hash('sha256', $feedback).' -->';
        app(CreativeWorkspaceRepository::class)->appendFeedback(
            $target['project'],
            $target['run'],
            'concepts',
            'phase',
            $feedback."\n\n{$marker}",
        );

        $retried = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'retry_with_feedback',
            $feedback,
            $gateToken,
        );

        $this->assertSame('awaiting_review', $retried['status']);
        $retryAttempt = collect(app(GenerationAttemptStore::class)->attempts(
            $target['project'],
            $target['run'],
            'concepts',
        ))->first(fn (array $attempt): bool => ($attempt['request']['attempt_number'] ?? null) === 2);
        $this->assertIsArray($retryAttempt);
        $application = collect(app(FeedbackApplicationStore::class)
            ->applications($target['project'], $target['run']))
            ->firstWhere('attempt_id', $retryAttempt['attempt_id']);
        $this->assertIsArray($application);
        $reservedBody = collect($application['request']['sources'])
            ->pluck('pending_body')
            ->implode("\n");
        $this->assertSame(1, substr_count($reservedBody, $feedback));
        $this->assertSame(1, substr_count($reservedBody, $marker));
        $this->assertCount(1, collect(app(AutomationStateStore::class)
            ->events($target['project'], $target['run']))
            ->where('event', 'gate_decided')
            ->where('gate_token', $gateToken));
        $this->assertNotSame($paused['latest_evaluation_id'], $retried['latest_evaluation_id']);
    }

    public function test_retry_feedback_is_applied_even_when_a_reused_manual_phase_has_no_prior_evaluation(): void
    {
        $target = $this->project('Retry reused phase', 'concepts');
        $workspace = app(CreativeWorkspaceRepository::class);
        $workspace->simulate($target['project'], $target['run'], 'concepts', true);
        $concept = $workspace->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $workspace->select($target['project'], $target['run'], 'concepts', $concept['id']);
        $orchestrator = app(CreativeRunOrchestrator::class);
        $reusedGate = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $this->assertSame('human_action', $reusedGate['step']);
        $this->assertNull($reusedGate['latest_evaluation_id']);

        $retried = $orchestrator->decide(
            $target['project'],
            $target['run'],
            'retry_with_feedback',
            'Generate a fresh structurally traceable set.',
            $this->gateToken($orchestrator, $target),
        );

        $this->assertSame('awaiting_review', $retried['status']);
        $this->assertSame('gate', $retried['step']);
        $this->assertSame(1, $retried['attempts_by_phase']['concepts']);
        $attempt = collect(app(GenerationAttemptStore::class)
            ->attempts($target['project'], $target['run'], 'concepts'))
            ->first(fn (array $candidate): bool => is_string($candidate['request']['automation_id'] ?? null));
        $this->assertIsArray($attempt);
        $this->assertNull($attempt['request']['prior_evaluation_id']);
        $this->assertNotNull($attempt['request']['retry_decision_event_id']);
        $phaseState = $workspace->automationPhaseState($target['project'], $target['run'], 'concepts');
        $retryArtifacts = collect($phaseState['artifacts'])
            ->where('metadata.generation_attempt_id', $attempt['attempt_id']);
        $this->assertTrue($retryArtifacts->every(
            fn (array $artifact): bool => ($artifact['metadata']['retry_context_applied'] ?? null) === true
                && ($artifact['metadata']['retry_context_sha256'] ?? null)
                    === $attempt['request']['generation_feedback_sha256'],
        ));
    }

    public function test_decision_route_requires_feedback_for_retry_and_records_cancel(): void
    {
        $target = $this->project('Decision route', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $url = "/projects/{$target['project']}/runs/{$target['run']}/automation/decision";
        $gateToken = $this->gateToken($orchestrator, $target);

        $this->from(route('creative.workspace', [
            'project' => $target['project'],
            'run' => $target['run'],
            'phase' => 'concepts',
        ]))
            ->post($url, [
                'decision' => 'retry_with_feedback',
                'gate_token' => $gateToken,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('feedback');
        $this->post($url, [
            'decision' => 'cancel',
            'gate_token' => $gateToken,
        ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(
            'cancelled',
            app(CreativeRunOrchestrator::class)->status($target['project'], $target['run'])['state']['status'],
        );
        Http::assertNothingSent();
    }

    public function test_resume_finishes_a_durable_cancel_request_after_checkpoint_loss(): void
    {
        $target = $this->project('Interrupted cancellation', 'concepts');
        $orchestrator = app(CreativeRunOrchestrator::class);
        $paused = $orchestrator->start($target['project'], $target['run'], [
            'human_gates' => ['concepts'],
        ]);
        $states = app(AutomationStateStore::class);
        $states->appendEvent($target['project'], $target['run'], 'cancel_requested', [
            'phase' => 'concepts',
            'active_attempt_id' => null,
        ], 'Cancellation was durably requested immediately before process interruption.');
        File::delete(app(CreativePathResolver::class)
            ->runPath($target['project'], $target['run']).'/automation/state.md');

        $cancelled = $orchestrator->resume($target['project'], $target['run']);

        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame($paused['total_attempts'], $cancelled['total_attempts']);
        $events = $states->events($target['project'], $target['run']);
        $this->assertCount(1, collect($events)->where('event', 'cancel_requested'));
        $this->assertContains('automation_cancelled', array_column($events, 'event'));
    }

    /** @return array{project: string, run: string, phase: string} */
    private function project(string $name, string $startPhase): array
    {
        return app(CreativeWorkspaceRepository::class)->createProject(
            $name,
            'Prove automatic creative behavior without contacting a provider.',
            $startPhase,
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

    /**
     * @param  array{project: string, run: string, phase: string}  $target
     * @param  array<string, mixed>  $context
     */
    private function startEventOnlyAttempt(
        array $target,
        array $context,
        string $attemptId,
        string $generationFeedbackHash,
    ): string {
        $states = app(AutomationStateStore::class);
        $policy = app(AutomationPolicy::class)->make('concepts', ['human_gates' => []]);
        $automationId = $states->newId('automation');
        $states->start($target['project'], $target['run'], $automationId, $policy);
        $sourceIds = collect($context['inputs'])->pluck('id')->sort()->values()->all();
        $generation = app(CreativeGenerationService::class);

        return $states->appendEvent($target['project'], $target['run'], 'attempt_started', [
            'phase' => 'concepts',
            'attempt_id' => $attemptId,
            'attempt_number' => 1,
            'driver' => 'simulation',
            'source_artifact_ids' => $sourceIds,
            'required_slots' => $context['required_slots'],
            'expected_output_count' => $generation->expectedOutputCount('concepts', $context['required_slots']),
            'reserved_simulation_units' => $generation->estimatedSimulationUnits('concepts', $context['required_slots']),
            'prior_evaluation_id' => null,
            'prior_evaluation_diagnosis' => null,
            'retry_context_hash' => null,
            'retry_decision_event_id' => null,
            'decision_feedback_sha256' => null,
            'generation_feedback_sha256' => $generationFeedbackHash,
            'lineage_hash' => hash('sha256', implode("\0", $sourceIds)),
            'cost_limit_usd' => 0.0,
        ], 'Test interruption before the generation attempt request was written.');
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
