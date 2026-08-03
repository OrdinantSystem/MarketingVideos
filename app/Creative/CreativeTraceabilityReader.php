<?php

namespace App\Creative;

use App\Creative\Automation\AutomationStateStore;
use App\Creative\Feedback\FeedbackApplicationStore;
use App\Creative\Generation\GenerationAttemptStore;
use App\Creative\Media\MediaJobStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

final class CreativeTraceabilityReader
{
    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly GenerationAttemptStore $attempts,
        private readonly AutomationStateStore $automation,
        private readonly MediaJobStore $mediaJobs,
        private readonly FeedbackApplicationStore $feedbackApplications,
    ) {}

    /**
     * @return array{
     *     attempts: list<array<string, mixed>>,
     *     evaluations: list<array<string, mixed>>,
     *     selections: list<array<string, mixed>>,
     *     source_decisions: list<array<string, mixed>>,
     *     feedback_applications: list<array<string, mixed>>,
     *     media_jobs: list<array<string, mixed>>,
     *     automation_events: list<array<string, mixed>>
     * }
     */
    public function phase(string $project, string $run, string $phase): array
    {
        $phaseRoot = dirname($this->paths->outputsPath($project, $run, $phase));

        return [
            'attempts' => $this->generationAttempts($project, $run, $phase),
            'evaluations' => $this->documents($phaseRoot.'/evaluations'),
            'selections' => $this->documents($this->paths->selectionsPath($project, $run, $phase)),
            'source_decisions' => $this->documents($this->paths->ideaSourceDecisionsPath($project, $run)),
            'feedback_applications' => $this->feedbackApplicationRecords($project, $run, $phase),
            'media_jobs' => $this->mediaJobRecords($project, $run, $phase),
            'automation_events' => $this->automationEvents($project, $run),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function feedbackApplicationRecords(string $project, string $run, string $phase): array
    {
        return array_values(collect($this->feedbackApplications->applications($project, $run))
            ->filter(function (array $application) use ($phase): bool {
                if (is_string($application['parse_error'] ?? null)) {
                    return true;
                }

                $request = is_array($application['request'] ?? null) ? $application['request'] : [];
                $result = is_array($application['result'] ?? null) ? $application['result'] : [];
                $applicationPhase = $result['phase'] ?? $request['phase'] ?? null;

                return $applicationPhase === $phase;
            })
            ->map(function (array $application): array {
                $request = is_array($application['request'] ?? null) ? $application['request'] : null;
                $result = is_array($application['result'] ?? null) ? $application['result'] : null;
                $sourceSnapshots = [];

                foreach (is_array($request['sources'] ?? null) ? $request['sources'] : [] as $source) {
                    if (! is_array($source)) {
                        continue;
                    }

                    $sourceSnapshots[] = [
                        'scope' => is_string($source['scope'] ?? null) ? $source['scope'] : 'unknown',
                        'path' => is_string($source['path'] ?? null) ? $source['path'] : null,
                        'pending_sha256' => is_string($source['pending_sha256'] ?? null)
                            ? $source['pending_sha256']
                            : null,
                    ];
                }

                $requestRecord = $request;
                if ($requestRecord !== null) {
                    // The trace needs immutable identities and hashes, not another copy of feedback prose.
                    $requestRecord['sources'] = $sourceSnapshots;
                }

                $implementationReferences = [];
                foreach (is_array($result['implementation_references'] ?? null) ? $result['implementation_references'] : [] as $reference) {
                    if (is_array($reference)) {
                        $implementationReferences[] = $reference;
                    }
                }

                $historyIds = [];
                foreach (is_array($result['history_ids'] ?? null) ? $result['history_ids'] : [] as $historyId) {
                    if (is_string($historyId)) {
                        $historyIds[] = $historyId;
                    }
                }

                $applicationPhase = $result['phase'] ?? $request['phase'] ?? null;
                $status = $result['status'] ?? $request['status'] ?? null;
                $generationFeedbackHash = $result['generation_feedback_sha256']
                    ?? $request['generation_feedback_sha256']
                    ?? null;

                return [
                    'attempt_id' => $application['attempt_id'],
                    'phase' => is_string($applicationPhase) ? $applicationPhase : null,
                    'status' => is_string($status) ? $status : 'unreadable',
                    'path' => is_string($application['path'] ?? null)
                        ? $this->relativePath($application['path'])
                        : null,
                    'created_at' => is_string($request['created_at'] ?? null) ? $request['created_at'] : null,
                    'finished_at' => is_string($result['finished_at'] ?? null) ? $result['finished_at'] : null,
                    'source_snapshots' => $sourceSnapshots,
                    'generation_feedback_sha256' => is_string($generationFeedbackHash)
                        ? $generationFeedbackHash
                        : null,
                    'implementation_references' => $implementationReferences,
                    'history_ids' => $historyIds,
                    'request' => $requestRecord,
                    'result' => $result,
                    'parse_error' => is_string($application['parse_error'] ?? null)
                        ? $application['parse_error']
                        : null,
                ];
            })
            ->sortByDesc(fn (array $application): string => (string) ($application['created_at'] ?? $application['attempt_id']))
            ->values()
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function generationAttempts(string $project, string $run, string $phase): array
    {
        return array_values(collect($this->attempts->attempts($project, $run, $phase))
            ->map(function (array $attempt): array {
                $request = is_array($attempt['request']) ? $attempt['request'] : [];
                $result = is_array($attempt['result']) ? $attempt['result'] : [];

                return [
                    'id' => $attempt['attempt_id'],
                    'path' => $this->relativePath($attempt['path']),
                    'started_at' => $request['started_at'] ?? null,
                    'finished_at' => $result['finished_at'] ?? null,
                    'driver' => $result['driver'] ?? $request['driver'] ?? 'unknown',
                    'status' => $result['status'] ?? 'incomplete',
                    'artifact_ids' => is_array($result['artifact_ids'] ?? null) ? $result['artifact_ids'] : [],
                    'simulation_units' => $result['simulation_units'] ?? $request['reserved_simulation_units'] ?? null,
                    'cost_usd' => $result['cost_usd'] ?? null,
                    'request' => $request,
                    'result' => $attempt['result'],
                    'parse_errors' => $attempt['parse_errors'],
                ];
            })
            ->sortByDesc(fn (array $attempt): string => (string) ($attempt['started_at'] ?? $attempt['id']))
            ->values()
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function mediaJobRecords(string $project, string $run, string $phase): array
    {
        return array_values(collect($this->mediaJobs->jobs($project, $run, $phase))
            ->map(function (array $job): array {
                $events = collect($job['events'])->map(function (array $event): array {
                    $path = is_string($event['_path'] ?? null) ? $event['_path'] : '';

                    return collect($event)
                        ->except(['_path'])
                        ->all() + ['path' => $this->relativePath($path)];
                })->values()->all();

                return [
                    'id' => $job['job_id'],
                    'path' => $this->relativePath($job['path']),
                    'created_at' => is_array($job['request'])
                        ? ($job['request']['created_at'] ?? null)
                        : null,
                    'request' => $job['request'],
                    'state' => $job['state'],
                    'result' => $job['result'],
                    'events' => $events,
                    'parse_errors' => $job['parse_errors'],
                ];
            })
            ->sortByDesc(fn (array $job): string => (string) ($job['created_at'] ?? $job['id']))
            ->values()
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function automationEvents(string $project, string $run): array
    {
        try {
            return array_values(collect($this->automation->events($project, $run))
                ->map(function (array $event): array {
                    $path = is_string($event['_path'] ?? null) ? $event['_path'] : '';

                    return collect($event)
                        ->except(['_path'])
                        ->all() + ['path' => $this->relativePath($path)];
                })
                ->sortByDesc(fn (array $event): int => (int) ($event['sequence'] ?? 0))
                ->values()
                ->all());
        } catch (Throwable $exception) {
            return [[
                'event' => 'events_unavailable',
                'parse_error' => $exception->getMessage(),
                'path' => null,
            ]];
        }
    }

    /** @return list<array<string, mixed>> */
    private function documents(string $directory): array
    {
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(function (\SplFileInfo $file): array {
                try {
                    $document = MarkdownDocument::read($file->getPathname());
                    $metadata = $document['metadata'];

                    return $document + [
                        'id' => $metadata['id'] ?? $file->getBasename('.md'),
                        'title' => $metadata['title'] ?? Str::headline((string) ($metadata['event'] ?? $metadata['type'] ?? $file->getBasename('.md'))),
                        'path' => $this->relativePath($file->getPathname()),
                    ];
                } catch (Throwable $exception) {
                    return [
                        'id' => $file->getBasename('.md'),
                        'title' => Str::headline($file->getBasename('.md')),
                        'path' => $this->relativePath($file->getPathname()),
                        'metadata' => [],
                        'body' => '',
                        'sections' => [],
                        'parse_error' => $exception->getMessage(),
                    ];
                }
            })
            ->sortByDesc(fn (array $document): string => (string) ($document['metadata']['created_at'] ?? $document['id']))
            ->values()
            ->all());
    }

    private function relativePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        return Str::after($path, $this->paths->root().'/');
    }
}
