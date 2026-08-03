<?php

namespace App\Creative\Automation;

use App\Creative\CreativeLockManager;
use App\Creative\CreativePathResolver;
use App\Creative\MarkdownDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AutomationStateStore
{
    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativeLockManager $locks,
        private readonly AutomationRecordValidator $validator,
    ) {}

    public function exists(string $project, string $run): bool
    {
        return $this->policyExists($project, $run)
            && $this->stateExists($project, $run);
    }

    public function policyExists(string $project, string $run): bool
    {
        return File::isFile($this->policyPath($project, $run));
    }

    public function stateExists(string $project, string $run): bool
    {
        return File::isFile($this->statePath($project, $run));
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public function start(string $project, string $run, string $automationId, array $policy): array
    {
        return $this->locks->exclusive("automation:{$project}:{$run}", function () use ($project, $run, $automationId, $policy): array {
            if ($this->policyExists($project, $run) && ! $this->stateExists($project, $run)) {
                return $this->recoverPolicyOnlyUnlocked($project, $run);
            }

            if ($this->policyExists($project, $run)) {
                throw new RuntimeException('This run already has an automatic-mode history. Resume it instead of replacing it.');
            }

            if ($this->stateExists($project, $run)) {
                throw new RuntimeException('Automatic state exists without its immutable policy. Run the integrity checker before continuing.');
            }

            $createdAt = $this->timestamp();
            $policyMetadata = array_merge($policy, [
                'id' => $automationId,
                'type' => 'automation_policy',
                'automation_id' => $automationId,
                'created_at' => $createdAt,
            ]);
            $this->validator->policy($policyMetadata);
            MarkdownDocument::write(
                $this->policyPath($project, $run),
                $policyMetadata,
                "# Automatic run policy\n\nThis immutable policy permits only the local simulation generator. It limits attempts and simulation work before any phase may advance.",
                false,
            );

            $state = $this->initialState(
                $automationId,
                (string) ($policy['start_phase'] ?? 'concepts'),
                $createdAt,
            );
            $this->writeState($project, $run, $state);
            $this->appendEventUnlocked($project, $run, 'automation_started', [
                'automation_id' => $automationId,
                'start_phase' => $state['current_phase'],
            ], 'Automatic simulation run started.');

            return $state;
        });
    }

    /** @return array<string, mixed> */
    public function recoverPolicyOnly(string $project, string $run): array
    {
        return $this->locks->exclusive("automation:{$project}:{$run}", function () use ($project, $run): array {
            if ($this->exists($project, $run)) {
                return $this->state($project, $run);
            }

            if (! $this->policyExists($project, $run) || $this->stateExists($project, $run)) {
                throw new RuntimeException('The automatic-mode bootstrap cannot be recovered from its current files.');
            }

            return $this->recoverPolicyOnlyUnlocked($project, $run);
        });
    }

    /** @return array<string, mixed> */
    public function policy(string $project, string $run): array
    {
        $policy = $this->readMetadata($this->policyPath($project, $run), 'Automation policy');
        $this->validator->policy($policy);

        return $policy;
    }

    /** @return array<string, mixed> */
    public function state(string $project, string $run): array
    {
        $state = $this->readMetadata($this->statePath($project, $run), 'Automation state');
        $this->validator->state($state, $this->policy($project, $run));

        return $state;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function updateState(string $project, string $run, array $changes): array
    {
        return $this->locks->exclusive("automation:{$project}:{$run}", function () use ($project, $run, $changes): array {
            $state = array_merge($this->state($project, $run), $changes, ['updated_at' => $this->timestamp()]);
            $this->writeState($project, $run, $state);

            return $state;
        });
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function replaceState(string $project, string $run, array $state): array
    {
        return $this->locks->exclusive("automation:{$project}:{$run}", function () use ($project, $run, $state): array {
            $state['updated_at'] = $this->timestamp();
            $this->writeState($project, $run, $state);

            return $state;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function appendEvent(
        string $project,
        string $run,
        string $event,
        array $metadata = [],
        string $message = '',
    ): string {
        return $this->locks->exclusive(
            "automation:{$project}:{$run}",
            fn (): string => $this->appendEventUnlocked($project, $run, $event, $metadata, $message),
        );
    }

    /** @return list<array<string, mixed>> */
    public function events(string $project, string $run): array
    {
        $directory = $this->eventsPath($project, $run);
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename())
            ->map(function (\SplFileInfo $file): array {
                try {
                    $document = MarkdownDocument::read($file->getPathname());

                    return $document['metadata'] + [
                        '_path' => $file->getPathname(),
                        '_filename' => $file->getFilename(),
                        '_body' => $document['body'],
                    ];
                } catch (Throwable $exception) {
                    return [
                        'parse_error' => $exception->getMessage(),
                        '_path' => $file->getPathname(),
                        '_filename' => $file->getFilename(),
                    ];
                }
            })->all());
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    public function writeEvaluation(
        string $project,
        string $run,
        string $phase,
        string $evaluationId,
        array $evaluation,
    ): void {
        $scores = is_array($evaluation['scores'] ?? null) ? $evaluation['scores'] : [];
        $rows = collect($scores)->map(function (mixed $score): string {
            if (! is_array($score)) {
                return '';
            }

            return '| '.(string) ($score['candidate_order'] ?? '').' | '.(string) ($score['slot'] ?? '').' | '.(string) ($score['artifact_id'] ?? '').' | '.(string) ($score['score'] ?? '').' | '.(($score['passed'] ?? false) ? 'yes' : 'no').' |';
        })->filter()->implode("\n");
        $recommendations = collect(is_array($evaluation['recommendations'] ?? null) ? $evaluation['recommendations'] : [])
            ->map(fn (mixed $artifactId, mixed $slot): string => '- `'.(string) $slot.'` → `'.(string) $artifactId.'`')
            ->implode("\n");
        $body = "# Automatic evaluation\n\n## Candidate scores\n\n| Order | Slot | Artifact | Score | Passed |\n| ---: | --- | --- | ---: | --- |\n{$rows}\n\n## Recommendation\n\n{$recommendations}\n\n## Diagnosis\n\n".(string) ($evaluation['diagnosis'] ?? 'No diagnosis recorded.');

        $metadata = array_merge($evaluation, [
            'schema_version' => 1,
            'id' => $evaluationId,
            'type' => 'artifact_evaluation',
            'phase' => $phase,
            'simulation' => true,
            'created_at' => $this->timestamp(),
        ]);
        $this->validator->evaluation(
            $metadata,
            $this->policy($project, $run),
            $phase,
            $evaluationId.'.md',
        );
        MarkdownDocument::write(
            $this->evaluationsPath($project, $run, $phase).'/'.$evaluationId.'.md',
            $metadata,
            $body,
            false,
        );
    }

    /** @return array<string, mixed> */
    public function evaluation(string $project, string $run, string $phase, string $evaluationId): array
    {
        $evaluation = $this->readMetadata(
            $this->evaluationsPath($project, $run, $phase).'/'.$evaluationId.'.md',
            'Automation evaluation',
        );
        $this->validator->evaluation(
            $evaluation,
            $this->policy($project, $run),
            $phase,
            $evaluationId.'.md',
        );

        return $evaluation;
    }

    /** @return list<array<string, mixed>> */
    public function evaluations(string $project, string $run, string $phase): array
    {
        $directory = $this->evaluationsPath($project, $run, $phase);
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename())
            ->map(function (\SplFileInfo $file): array {
                try {
                    return MarkdownDocument::read($file->getPathname())['metadata'] + [
                        '_filename' => $file->getFilename(),
                        '_path' => $file->getPathname(),
                    ];
                } catch (Throwable $exception) {
                    return [
                        'parse_error' => $exception->getMessage(),
                        '_filename' => $file->getFilename(),
                        '_path' => $file->getPathname(),
                    ];
                }
            })->all());
    }

    public function newId(string $type): string
    {
        return now('UTC')->format('Ymd-His').'--'.Str::slug($type).'--'.Str::lower(Str::random(6));
    }

    private function policyPath(string $project, string $run): string
    {
        return $this->paths->runPath($project, $run).'/automation/policy.md';
    }

    private function statePath(string $project, string $run): string
    {
        return $this->paths->runPath($project, $run).'/automation/state.md';
    }

    private function eventsPath(string $project, string $run): string
    {
        return $this->paths->runPath($project, $run).'/automation/events';
    }

    private function evaluationsPath(string $project, string $run, string $phase): string
    {
        return dirname($this->paths->outputsPath($project, $run, $phase)).'/evaluations';
    }

    /** @return array<string, mixed> */
    private function recoverPolicyOnlyUnlocked(string $project, string $run): array
    {
        $policy = $this->policy($project, $run);
        $automationId = is_string($policy['automation_id'] ?? null)
            ? $policy['automation_id']
            : (is_string($policy['id'] ?? null) ? $policy['id'] : '');
        $startPhase = is_string($policy['start_phase'] ?? null) ? $policy['start_phase'] : '';
        if ($automationId === '' || $startPhase === '') {
            throw new RuntimeException('The interrupted automatic policy is missing its identity or start phase.');
        }

        $createdAt = is_string($policy['created_at'] ?? null)
            ? $policy['created_at']
            : $this->timestamp();
        $state = $this->initialState($automationId, $startPhase, $createdAt);
        $this->writeState($project, $run, $state);
        $this->appendEventUnlocked($project, $run, 'automation_bootstrap_recovered', [
            'automation_id' => $automationId,
            'start_phase' => $startPhase,
        ], 'The immutable policy survived an interrupted startup, so the missing checkpoint was reconstructed before work resumed.');

        return $state;
    }

    /** @return array<string, mixed> */
    private function initialState(string $automationId, string $startPhase, string $createdAt): array
    {
        return [
            'schema_version' => 1,
            'id' => $automationId,
            'type' => 'automation_state',
            'automation_id' => $automationId,
            'status' => 'pending',
            'step' => 'ready',
            'current_phase' => $startPhase,
            'attempts_by_phase' => [],
            'total_attempts' => 0,
            'simulation_units_spent' => 0,
            'active_attempt_id' => null,
            'latest_evaluation_id' => null,
            'gate_phase' => null,
            'gate_reason' => null,
            'cancel_requested' => false,
            'retry_requested' => false,
            'last_error' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** @param array<string, mixed> $state */
    private function writeState(string $project, string $run, array $state): void
    {
        $this->validator->state($state, $this->policy($project, $run));
        $body = "# Automatic run state\n\n- Status: **".(string) ($state['status'] ?? 'unknown')."**\n- Current phase: **".(string) ($state['current_phase'] ?? 'none')."**\n- Step: **".(string) ($state['step'] ?? 'unknown')."**\n- Simulation units spent: **".(string) ($state['simulation_units_spent'] ?? 0)."**\n\nThis file is an atomic checkpoint. Immutable event and evaluation files preserve the explanation history.";
        MarkdownDocument::write($this->statePath($project, $run), $state, $body);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function appendEventUnlocked(
        string $project,
        string $run,
        string $event,
        array $metadata,
        string $message,
    ): string {
        $events = $this->events($project, $run);
        $policy = $this->policy($project, $run);
        foreach ($events as $index => $existing) {
            if (is_string($existing['parse_error'] ?? null)) {
                throw new RuntimeException('Cannot append to malformed automation event history.');
            }
            $this->validator->event(
                $existing,
                $policy,
                $index + 1,
                is_string($existing['_filename'] ?? null) ? $existing['_filename'] : null,
            );
        }
        $sequence = count($events) + 1;
        $eventId = sprintf('%06d', $sequence).'--'.Str::slug($event).'--'.Str::lower(Str::random(6));
        $eventMetadata = array_merge($metadata, [
            'schema_version' => 1,
            'id' => $eventId,
            'type' => 'automation_event',
            'automation_id' => $policy['automation_id'],
            'event' => $event,
            'sequence' => $sequence,
            'message' => $message !== '' ? $message : 'Automatic workflow state changed.',
            'created_at' => $this->timestamp(),
        ]);
        $this->validator->event($eventMetadata, $policy, $sequence, $eventId.'.md');
        MarkdownDocument::write(
            $this->eventsPath($project, $run).'/'.$eventId.'.md',
            $eventMetadata,
            '# '.Str::headline($event)."\n\n".($message !== '' ? $message : 'Automatic workflow state changed.'),
            false,
        );

        return $eventId;
    }

    /** @return array<string, mixed> */
    private function readMetadata(string $path, string $label): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException("{$label} does not exist.");
        }

        try {
            return MarkdownDocument::read($path)['metadata'];
        } catch (Throwable $exception) {
            throw new RuntimeException("{$label} is malformed.", previous: $exception);
        }
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.uP');
    }
}
