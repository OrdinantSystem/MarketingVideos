<?php

namespace App\Creative\Generation;

use App\Creative\CreativeArtifactValidator;
use App\Creative\CreativePathResolver;
use App\Creative\Feedback\FeedbackApplicationStore;
use App\Creative\FilesystemCreativeStore;
use App\Creative\MarkdownDocument;
use App\Creative\Media\SimulatedMediaBatchMaterializer;
use App\Creative\ShotPlanResolver;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CreativeGenerationService
{
    public function __construct(
        private readonly PhaseGeneratorRegistry $generators,
        private readonly FilesystemCreativeStore $store,
        private readonly CreativePathResolver $paths,
        private readonly GenerationAttemptStore $attempts,
        private readonly CreativeArtifactValidator $validator,
        private readonly ShotPlanResolver $shotPlans,
        private readonly SimulatedMediaBatchMaterializer $media,
        private readonly FeedbackApplicationStore $feedbackApplications,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @param  list<string>  $requiredSlots
     * @param  array<string, list<string>>  $expectedSourcesBySlot
     * @param  array<string, mixed>  $attemptContext
     * @return array{driver: string, attempt_id: string, artifact_ids: list<string>, simulation_units: int, cost_usd: float|null}
     */
    public function generate(
        string $project,
        string $run,
        string $phase,
        array $inputs,
        string $feedback,
        array $requiredSlots,
        array $expectedSourcesBySlot,
        bool $simulationOnly = false,
        ?string $attemptId = null,
        array $attemptContext = [],
    ): array {
        if ($requiredSlots === []) {
            throw new RuntimeException('The phase has no resolved output slots. Select a valid script-defined shot plan first.');
        }

        $generator = $this->generators->select($phase, $simulationOnly);
        if (! $generator->supports($phase)) {
            throw new RuntimeException("The {$generator->driver()} generator does not support {$phase}.");
        }

        $attemptId ??= $this->newAttemptId($phase);
        $sequenceContract = null;
        if ($phase === 'sequence') {
            $sequenceContract = $this->shotPlans->sequenceContract($inputs);
            if (! $sequenceContract['valid'] || ! is_array($sequenceContract['shot_plan'])) {
                throw new RuntimeException('Selected scenes do not form a valid sequence contract: '.implode(' ', $sequenceContract['errors']));
            }
        }

        $sourceIds = $sequenceContract !== null
            ? $sequenceContract['scene_artifact_ids']
            : $this->sourceIds($inputs);
        $expectedOutputCount = $this->expectedOutputCount($phase, $requiredSlots);
        $reservedUnits = $generator->driver() === 'simulation'
            ? $this->estimatedSimulationUnits($phase, $requiredSlots)
            : 0;
        $generationContext = $this->generationContext($feedback, $attemptContext);
        $feedbackReservation = $this->feedbackApplications->reserveGenerationFeedback(
            $project,
            $run,
            $phase,
            $attemptId,
            $generationContext,
        );
        $feedback = $feedbackReservation['generation_feedback'];
        $feedbackReserved = $feedbackReservation['reserved'];
        $attemptContext['generation_feedback_sha256'] = $feedbackReservation['generation_feedback_sha256'];
        $attemptContext['generation_context_sha256'] = $generationContext !== ''
            ? hash('sha256', $generationContext)
            : null;
        $attemptBegan = false;

        try {
            $this->attempts->begin($project, $run, $phase, $attemptId, array_merge($attemptContext, [
                'driver' => $generator->driver(),
                'source_artifact_ids' => $sourceIds,
                'required_slots' => $requiredSlots,
                'expected_output_count' => $expectedOutputCount,
                'reserved_simulation_units' => $reservedUnits,
                'cost_limit_usd' => $generator->driver() === 'simulation' ? 0.0 : null,
            ]));
            $attemptBegan = true;
            $batch = $generator->generate(new PhaseGenerationRequest(
                $phase,
                $inputs,
                $feedback,
                $requiredSlots,
            ));
            $drafts = $batch['drafts'];
            if (count($drafts) !== $expectedOutputCount) {
                throw new RuntimeException('Generation returned '.count($drafts)." artifacts; {$expectedOutputCount} were required. Nothing was committed.");
            }

            $simulation = $generator->driver() === 'simulation';
            $batchMetadata = $batch['metadata'];
            $cost = array_key_exists('cost_usd', $batchMetadata) && is_numeric($batchMetadata['cost_usd'])
                ? (float) $batchMetadata['cost_usd']
                : ($simulation ? 0.0 : null);
            $commonMetadata = array_merge([
                'schema_version' => 1,
                'generation_attempt_id' => $attemptId,
                'generator' => $generator->driver(),
                'prompt_version' => $phase.'-'.$generator->driver().'-v1',
                'source_artifact_ids' => $sourceIds,
                'settings' => [
                    'driver' => $generator->driver(),
                    'lightweight' => (bool) config('creative.generation.lightweight', true),
                ],
                'usage' => [],
                'cost_usd' => $cost,
                'status' => $simulation && in_array($phase, ['images', 'scenes'], true) ? 'simulated' : 'ready',
                'simulation' => $simulation,
            ], $batchMetadata, [
                'generation_attempt_id' => $attemptId,
                'generator' => $generator->driver(),
                'source_artifact_ids' => $sourceIds,
                'cost_usd' => $cost,
                'simulation' => $simulation,
            ]);
            $expectedShotsBySlot = $this->expectedShotsBySlot($phase, $inputs);

            $documents = [];
            foreach ($drafts as $draftIndex => $draft) {
                $id = $this->newArtifactId($phase, $draft['title']);
                $draftMetadata = $draft['metadata'];
                if ($phase === 'brief-script' && ! array_key_exists('shot_plan', $draftMetadata)) {
                    $resolved = $this->shotPlans->resolve([
                        'metadata' => $draftMetadata,
                        'body' => $draft['body'],
                    ]);
                    if (! $resolved['valid'] || ! is_array($resolved['shot_plan'])) {
                        throw new RuntimeException('Generated brief/script has no valid shot plan: '.implode(' ', $resolved['errors']));
                    }

                    $draftMetadata['shot_plan'] = $resolved['shot_plan'];
                }

                if ($phase === 'sequence' && is_array($sequenceContract)) {
                    $draftMetadata['shot_plan'] = $sequenceContract['shot_plan'];
                    $draftMetadata['scene_artifact_ids'] = $sequenceContract['scene_artifact_ids'];
                    $draftMetadata['source_artifact_ids'] = $sequenceContract['scene_artifact_ids'];
                    $draft['body'] = $this->shotPlans->normalizeSequenceBody(
                        $draft['body'],
                        $sequenceContract['shot_plan'],
                        $sequenceContract['scene_artifact_ids'],
                    );
                }

                if (! array_key_exists('asset_path', $draftMetadata)) {
                    $draftMetadata['asset_path'] = match ($draft['artifact_type']) {
                        'image_sidecar' => "assets/{$id}.png",
                        'video_sidecar' => "assets/{$id}.mp4",
                        default => null,
                    };
                    if ($draftMetadata['asset_path'] === null) {
                        unset($draftMetadata['asset_path']);
                    }
                }

                $metadata = array_merge($commonMetadata, $draftMetadata, [
                    'id' => $id,
                    'artifact_type' => $draft['artifact_type'],
                    'phase' => $phase,
                    'slot' => $draft['slot'],
                    'title' => $draft['title'],
                    'summary' => $draft['summary'],
                    'candidate_order' => $draftIndex + 1,
                    'created_at' => $this->timestamp(),
                    'version' => 1,
                ]);
                $documents[] = [
                    'id' => $id,
                    'metadata' => $metadata,
                    'body' => $draft['body'],
                ];
            }

            $batchValidation = $this->validator->validateBatch($documents, $phase, $requiredSlots);
            if (! $batchValidation['valid']) {
                throw new RuntimeException('Generated batch failed validation: '.implode(' ', $batchValidation['errors']));
            }

            if ($simulation && in_array($phase, ['images', 'scenes'], true)) {
                $documents = $this->media->materialize(
                    $project,
                    $run,
                    $phase,
                    $attemptId,
                    $documents,
                );
            }

            foreach ($documents as $document) {
                $metadata = $document['metadata'];
                $slot = is_string($metadata['slot'] ?? null) ? $metadata['slot'] : '';
                $artifact = [
                    'metadata' => $metadata,
                    'body' => $document['body'],
                    'sections' => MarkdownDocument::sections($document['body']),
                    'id' => $document['id'],
                    'slot' => $slot,
                    'filename' => $document['id'].'.md',
                    'asset_status' => $metadata['asset_status'] ?? null,
                ];
                $validation = $this->validator->validate(
                    $artifact,
                    $phase,
                    $expectedSourcesBySlot[$slot] ?? null,
                    $requiredSlots,
                    $expectedShotsBySlot[$slot] ?? null,
                    $phase === 'sequence' && is_array($sequenceContract)
                        ? $sequenceContract['shot_plan']
                        : null,
                );
                if (! $validation['valid']) {
                    throw new RuntimeException('Generated artifact failed validation: '.implode(' ', $validation['errors']));
                }
            }

            $artifactIds = [];
            $hashes = [];
            foreach ($documents as $document) {
                $this->store->writeArtifact(
                    $project,
                    $run,
                    $phase,
                    $document['id'],
                    $document['metadata'],
                    $document['body'],
                );
                $artifactIds[] = $document['id'];
                $hash = hash_file(
                    'sha256',
                    $this->paths->outputsPath($project, $run, $phase).'/'.$document['id'].'.md',
                );
                if (! is_string($hash)) {
                    throw new RuntimeException('Unable to hash a committed creative artifact.');
                }
                $hashes[$document['id']] = $hash;
            }

            $this->attempts->finish($project, $run, $phase, $attemptId, 'committed', [
                'driver' => $generator->driver(),
                'artifact_ids' => $artifactIds,
                'artifact_sha256' => $hashes,
                'output_count' => count($artifactIds),
                'expected_output_count' => $expectedOutputCount,
                'simulation_units' => $reservedUnits,
                'cost_usd' => $cost,
            ], 'Every candidate passed validation and the immutable artifact batch was committed.');

            if ($feedbackReserved) {
                try {
                    $this->feedbackApplications->markApplied(
                        $project,
                        $run,
                        $phase,
                        $attemptId,
                        $artifactIds,
                    );
                } catch (Throwable $feedbackException) {
                    report($feedbackException);
                }
            }

            return [
                'driver' => $generator->driver(),
                'attempt_id' => $attemptId,
                'artifact_ids' => $artifactIds,
                'simulation_units' => $reservedUnits,
                'cost_usd' => $cost,
            ];
        } catch (Throwable $exception) {
            if ($attemptBegan && ! $this->attempts->resultExists($project, $run, $phase, $attemptId)) {
                $this->attempts->finish($project, $run, $phase, $attemptId, 'failed', [
                    'driver' => $generator->driver(),
                    'artifact_ids' => [],
                    'output_count' => 0,
                    'expected_output_count' => $expectedOutputCount,
                    'simulation_units' => $reservedUnits,
                    'cost_usd' => $generator->driver() === 'simulation' ? 0.0 : null,
                    'error' => Str::limit($exception->getMessage(), 500),
                ], 'The attempt failed before a complete candidate batch could be committed.');
            }

            if ($feedbackReserved) {
                try {
                    $this->feedbackApplications->markNotApplied(
                        $project,
                        $run,
                        $phase,
                        $attemptId,
                        $exception->getMessage(),
                    );
                } catch (Throwable $feedbackException) {
                    report($feedbackException);
                }
            }

            throw $exception;
        }
    }

    /** @return array{configured_driver: string, effective_driver: string, label: string, external: bool, cost: string, description: string} */
    public function state(string $phase): array
    {
        return $this->generators->state($phase);
    }

    /** @param list<string> $requiredSlots */
    public function estimatedSimulationUnits(string $phase, array $requiredSlots): int
    {
        return match ($phase) {
            'ideas', 'concepts', 'brief-script', 'sequence' => 1,
            'images' => max(1, count($requiredSlots) * 2),
            'scenes' => max(1, count($requiredSlots) * 2),
            default => throw new RuntimeException('Unknown creative phase.'),
        };
    }

    public function newAttemptId(string $phase): string
    {
        return now('UTC')->format('Ymd-His').'--generation--'.Str::lower(Str::random(6)).'--'.Str::slug($phase);
    }

    /** @param array<string, mixed> $attemptContext */
    private function generationContext(string $feedback, array $attemptContext): string
    {
        $hasRetryContext = is_string($attemptContext['prior_evaluation_id'] ?? null)
            || is_string($attemptContext['retry_decision_event_id'] ?? null);
        if (! $hasRetryContext) {
            return '';
        }

        $diagnosis = trim((string) ($attemptContext['prior_evaluation_diagnosis']
            ?? 'A person requested a bounded retry with explicit feedback.'));
        $priorEvaluationId = is_string($attemptContext['prior_evaluation_id'] ?? null)
            ? $attemptContext['prior_evaluation_id']
            : 'none';
        $context = "[automatic-retry-context]\nPrior evaluation: {$priorEvaluationId}\nDiagnosis: {$diagnosis}";
        $normalizedFeedback = trim($feedback);
        if ($normalizedFeedback !== $context
            && ! str_ends_with($normalizedFeedback, "\n\n{$context}")) {
            throw new RuntimeException('Automatic retry generation context does not match its attempt evidence.');
        }

        return $context;
    }

    /** @param list<string> $requiredSlots */
    public function expectedOutputCount(string $phase, array $requiredSlots): int
    {
        return match ($phase) {
            'ideas', 'concepts', 'brief-script', 'sequence' => 3,
            'images' => count($requiredSlots) * 2,
            'scenes' => count($requiredSlots),
            default => throw new RuntimeException('Unknown creative phase.'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return list<string>
     */
    private function sourceIds(array $inputs): array
    {
        $ids = [];
        foreach ($inputs as $input) {
            if (is_string($input['id'] ?? null) && $input['id'] !== '') {
                $ids[] = $input['id'];
            }
        }

        if ($ids === []) {
            throw new RuntimeException('Generation requires at least one traceable source artifact.');
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return array<string, array<string, mixed>>
     */
    private function expectedShotsBySlot(string $phase, array $inputs): array
    {
        if ($phase === 'images') {
            $script = $inputs[0] ?? null;
            if (! is_array($script)) {
                return [];
            }

            $resolution = $this->shotPlans->resolve($script);
            $shots = $resolution['valid'] && is_array($resolution['shot_plan'])
                ? ($resolution['shot_plan']['shots'] ?? [])
                : [];
        } elseif ($phase === 'scenes') {
            $shots = array_map(
                static fn (array $input): mixed => is_array($input['metadata'] ?? null)
                    ? ($input['metadata']['shot'] ?? null)
                    : null,
                $inputs,
            );
        } else {
            return [];
        }

        $bySlot = [];
        foreach ($shots as $shot) {
            if (! is_array($shot) || ! is_string($shot['id'] ?? null)) {
                continue;
            }

            $bySlot[$shot['id']] = $shot;
        }

        return $bySlot;
    }

    private function newArtifactId(string $phase, string $title): string
    {
        return now('UTC')->format('Ymd-His').'--'.Str::slug($phase).'--'.Str::lower(Str::random(6)).'--'.Str::limit(Str::slug($title), 42, '');
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.uP');
    }
}
