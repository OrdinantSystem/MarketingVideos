<?php

namespace App\Creative;

use App\Creative\Generation\GenerationAttemptStore;
use DateTimeImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

final class CreativeArtifactEligibilityVerifier
{
    /** @var array<string, array{fingerprint: string, sha256: string|null}> */
    private array $hashCache = [];

    public const ERROR_SCHEMA_V1_ATTEMPT_REQUEST_MISSING = 'Schema-v1 artifact has no generation attempt request manifest.';

    public const ERROR_SCHEMA_V1_ATTEMPT_RESULT_MISSING = 'Schema-v1 artifact has no generation attempt result manifest; generation is incomplete or uncommitted.';

    public const WARNING_LEGACY_ATTEMPT_MANIFEST_MISSING = 'Legacy artifact has no generation attempt manifest, so its committed bytes cannot be verified.';

    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly GenerationAttemptStore $attempts,
        private readonly MediaAssetContract $mediaAssets,
        private readonly CreativeArtifactValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<string>|null  $expectedRequiredSlots
     * @param  array<string, list<string>>|null  $expectedSourcesBySlot
     * @param  array<string, array<string, mixed>>  $expectedShotsBySlot
     * @param  array<string, mixed>|null  $expectedSequencePlan
     * @return array{eligible: bool, errors: list<string>, warnings: list<string>}
     */
    public function verify(
        string $project,
        string $run,
        string $phase,
        array $artifact,
        ?array $expectedRequiredSlots = null,
        ?array $expectedSourcesBySlot = null,
        array $expectedShotsBySlot = [],
        ?array $expectedSequencePlan = null,
    ): array {
        $errors = [];
        $warnings = [];

        $this->verifyAttempt(
            $project,
            $run,
            $phase,
            $artifact,
            $expectedRequiredSlots,
            $expectedSourcesBySlot,
            $expectedShotsBySlot,
            $expectedSequencePlan,
            $errors,
            $warnings,
        );
        $this->verifyMediaAsset($project, $run, $phase, $artifact, $errors);

        return [
            'eligible' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<string>|null  $expectedRequiredSlots
     * @param  array<string, list<string>>|null  $expectedSourcesBySlot
     * @param  array<string, array<string, mixed>>  $expectedShotsBySlot
     * @param  array<string, mixed>|null  $expectedSequencePlan
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function verifyAttempt(
        string $project,
        string $run,
        string $phase,
        array $artifact,
        ?array $expectedRequiredSlots,
        ?array $expectedSourcesBySlot,
        array $expectedShotsBySlot,
        ?array $expectedSequencePlan,
        array &$errors,
        array &$warnings,
    ): void {
        $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
        $attemptId = is_string($metadata['generation_attempt_id'] ?? null)
            ? trim($metadata['generation_attempt_id'])
            : '';
        if ($attemptId === '') {
            if (($metadata['schema_version'] ?? null) === 1) {
                $errors[] = self::ERROR_SCHEMA_V1_ATTEMPT_REQUEST_MISSING;
            }

            return;
        }

        if (! $this->safeIdentifier($attemptId)) {
            $errors[] = 'Artifact generation_attempt_id is unsafe.';

            return;
        }

        if (! $this->attempts->requestExists($project, $run, $phase, $attemptId)) {
            if (($metadata['schema_version'] ?? null) === 1) {
                $errors[] = self::ERROR_SCHEMA_V1_ATTEMPT_REQUEST_MISSING;
            } else {
                $warnings[] = self::WARNING_LEGACY_ATTEMPT_MANIFEST_MISSING;
            }

            return;
        }

        if (! $this->attempts->resultExists($project, $run, $phase, $attemptId)) {
            $errors[] = ($metadata['schema_version'] ?? null) === 1
                ? self::ERROR_SCHEMA_V1_ATTEMPT_RESULT_MISSING
                : 'Artifact generation attempt is incomplete or uncommitted.';

            return;
        }

        try {
            $attempt = $this->attempts->attempt($project, $run, $phase, $attemptId);
        } catch (Throwable) {
            $errors[] = 'Artifact generation attempt manifest cannot be read.';

            return;
        }

        if (($metadata['schema_version'] ?? null) === 1) {
            $this->verifySchemaOneAttempt(
                $project,
                $run,
                $phase,
                $attemptId,
                $artifact,
                $attempt['request'],
                $attempt['result'],
                $expectedRequiredSlots,
                $expectedSourcesBySlot,
                $expectedShotsBySlot,
                $expectedSequencePlan,
                $errors,
            );

            return;
        }

        $result = $attempt['result'];
        $artifactId = is_string($artifact['id'] ?? null) ? $artifact['id'] : '';
        $artifactIds = is_array($result['artifact_ids'] ?? null) && array_is_list($result['artifact_ids'])
            ? $result['artifact_ids']
            : [];
        $artifactIdsAreValid = $artifactIds !== [] && count($artifactIds) === count(array_filter(
            $artifactIds,
            fn (mixed $id): bool => is_string($id) && trim($id) !== '' && $this->safeIdentifier($id),
        ));
        if (! is_array($result)
            || ($result['status'] ?? null) !== 'committed'
            || ($result['attempt_id'] ?? null) !== $attemptId
            || ($result['phase'] ?? null) !== $phase
            || ! $artifactIdsAreValid
            || $artifactId === ''
            || ! in_array($artifactId, $artifactIds, true)) {
            $errors[] = 'Artifact generation attempt is incomplete or uncommitted.';

            return;
        }

        if (count($artifactIds) !== count(array_unique($artifactIds))
            || (int) ($result['output_count'] ?? -1) !== count($artifactIds)
            || (int) ($result['expected_output_count'] ?? -1) !== count($artifactIds)) {
            $errors[] = 'Artifact generation attempt does not describe one complete committed batch.';
        }

        $hashes = is_array($result['artifact_sha256'] ?? null) ? $result['artifact_sha256'] : [];
        $expectedHash = $hashes[$artifactId] ?? null;
        if (! is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            $errors[] = 'Committed generation attempt has no valid SHA-256 for this artifact.';

            return;
        }

        if (! $this->safeIdentifier($artifactId)) {
            $errors[] = 'Committed artifact ID is unsafe.';

            return;
        }

        $artifactPath = $this->paths->outputsPath($project, $run, $phase).'/'.$artifactId.'.md';
        if (! File::isFile($artifactPath)) {
            $errors[] = 'Committed artifact file is missing.';

            return;
        }

        $actualHash = hash_file('sha256', $artifactPath);
        if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
            $errors[] = 'Artifact bytes do not match the committed generation attempt SHA-256.';
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $result
     * @param  list<string>|null  $expectedRequiredSlots
     * @param  array<string, list<string>>|null  $expectedSourcesBySlot
     * @param  array<string, array<string, mixed>>  $expectedShotsBySlot
     * @param  array<string, mixed>|null  $expectedSequencePlan
     * @param  list<string>  $errors
     */
    private function verifySchemaOneAttempt(
        string $project,
        string $run,
        string $phase,
        string $attemptId,
        array $artifact,
        array $request,
        ?array $result,
        ?array $expectedRequiredSlots,
        ?array $expectedSourcesBySlot,
        array $expectedShotsBySlot,
        ?array $expectedSequencePlan,
        array &$errors,
    ): void {
        $requestErrors = $this->schemaOneRequestErrors($request, $phase, $attemptId);
        foreach ($requestErrors as $error) {
            $errors[] = 'Generation attempt request is invalid: '.$error;
        }

        if (! is_array($result)) {
            $errors[] = self::ERROR_SCHEMA_V1_ATTEMPT_RESULT_MISSING;

            return;
        }

        $resultErrors = $this->schemaOneResultErrors($result, $phase, $attemptId);
        foreach ($resultErrors as $error) {
            $errors[] = 'Generation attempt result is invalid: '.$error;
        }

        if ($requestErrors !== [] || $resultErrors !== []) {
            return;
        }

        /** @var string $requestDriver */
        $requestDriver = $request['driver'];
        /** @var list<string> $sourceIds */
        $sourceIds = $request['source_artifact_ids'];
        /** @var list<string> $requiredSlots */
        $requiredSlots = $request['required_slots'];
        $expectedOutputCount = $this->phaseOutputCount($phase, $requiredSlots);

        if ($expectedRequiredSlots !== null
            && $requiredSlots !== $expectedRequiredSlots) {
            $errors[] = 'Artifact generation attempt required_slots do not match the current effective phase slots.';
        }

        if ($requestDriver !== $result['driver']) {
            $errors[] = 'Generation attempt request and result drivers do not match.';
        }
        if ($request['expected_output_count'] !== $result['expected_output_count']) {
            $errors[] = 'Generation attempt request and result expected output counts do not match.';
        }
        if ($expectedOutputCount !== null && $request['expected_output_count'] !== $expectedOutputCount) {
            $errors[] = 'Generation attempt expected output count does not match the phase contract.';
        }
        if ($result['simulation_units'] > $request['reserved_simulation_units']) {
            $errors[] = 'Generation attempt consumed more simulation_units than it reserved.';
        }
        if ($request['cost_limit_usd'] !== null
            && ($result['cost_usd'] === null
                || (float) $result['cost_usd'] > (float) $request['cost_limit_usd'])) {
            $errors[] = 'Generation attempt cost_usd exceeds or cannot prove compliance with its reserved cost_limit_usd.';
        }

        /** @var list<string> $artifactIds */
        $artifactIds = $result['artifact_ids'];
        if ($result['status'] !== 'committed') {
            $errors[] = 'Artifact generation attempt is not committed.';

            return;
        }
        if ($result['output_count'] !== count($artifactIds)
            || $result['expected_output_count'] !== count($artifactIds)) {
            $errors[] = 'Artifact generation attempt does not describe one complete committed batch.';
        }

        $artifactMetadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
        $artifactId = is_string($artifact['id'] ?? null) ? $artifact['id'] : '';
        if (! $this->safeIdentifier($artifactId)
            || ($artifactMetadata['id'] ?? null) !== $artifactId
            || ($artifactMetadata['generation_attempt_id'] ?? null) !== $attemptId
            || ($artifactMetadata['phase'] ?? null) !== $phase) {
            $errors[] = 'Artifact identity does not match its generation attempt.';
        }
        if (! in_array($artifactId, $artifactIds, true)) {
            $errors[] = 'Artifact is not a member of its committed generation attempt.';
        }
        $settings = is_array($artifactMetadata['settings'] ?? null) ? $artifactMetadata['settings'] : [];
        if (($artifactMetadata['generator'] ?? null) !== $requestDriver
            || (($settings['driver'] ?? null) !== $requestDriver)) {
            $errors[] = 'Artifact generator lineage does not match its generation attempt driver.';
        }
        if (! $this->sourceLineageMatches(
            $phase,
            $artifactMetadata['source_artifact_ids'] ?? null,
            $sourceIds,
        )) {
            $errors[] = 'Artifact source lineage does not match its generation attempt request.';
        }
        if (! in_array($artifactMetadata['slot'] ?? null, $requiredSlots, true)) {
            $errors[] = 'Artifact slot was not reserved by its generation attempt request.';
        }
        if (($requestDriver === 'simulation') !== ($artifactMetadata['simulation'] ?? null)) {
            $errors[] = 'Artifact simulation flag does not match its generation attempt driver.';
        }
        if (! $this->sameCost($artifactMetadata['cost_usd'] ?? null, $result['cost_usd'])) {
            $errors[] = 'Artifact cost_usd does not match its generation attempt result.';
        }

        /** @var array<string, mixed> $hashes */
        $hashes = $result['artifact_sha256'];
        if (array_keys($hashes) !== $artifactIds) {
            $errors[] = 'Committed generation attempt hashes do not cover the exact artifact batch in order.';

            return;
        }

        $storedArtifacts = [];
        foreach ($artifactIds as $committedArtifactId) {
            $expectedHash = $hashes[$committedArtifactId] ?? null;
            if (! is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
                $errors[] = "Committed generation attempt has no valid SHA-256 for artifact [{$committedArtifactId}].";

                continue;
            }

            $artifactPath = $this->paths->outputsPath($project, $run, $phase).'/'.$committedArtifactId.'.md';
            if (! File::isFile($artifactPath)) {
                $errors[] = "Committed artifact [{$committedArtifactId}] is missing.";

                continue;
            }

            $actualHash = hash_file('sha256', $artifactPath);
            if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                $errors[] = "Committed artifact [{$committedArtifactId}] bytes do not match its generation attempt SHA-256.";

                continue;
            }

            try {
                $document = MarkdownDocument::read($artifactPath);
            } catch (Throwable) {
                $errors[] = "Committed artifact [{$committedArtifactId}] cannot be parsed as a creative document.";

                continue;
            }

            $metadata = $document['metadata'];
            $settings = is_array($metadata['settings'] ?? null) ? $metadata['settings'] : [];
            $storedArtifact = $document + [
                'id' => $metadata['id'] ?? $committedArtifactId,
                'slot' => $metadata['slot'] ?? 'primary',
                'filename' => $committedArtifactId.'.md',
            ];
            $storedArtifacts[] = $storedArtifact;

            if (($metadata['schema_version'] ?? null) !== 1
                || ($metadata['id'] ?? null) !== $committedArtifactId
                || ($metadata['phase'] ?? null) !== $phase
                || ($metadata['generation_attempt_id'] ?? null) !== $attemptId) {
                $errors[] = "Committed artifact [{$committedArtifactId}] identity does not match the schema-v1 generation batch.";
            }
            if (($metadata['generator'] ?? null) !== $requestDriver
                || (($settings['driver'] ?? null) !== $requestDriver)) {
                $errors[] = "Committed artifact [{$committedArtifactId}] generator lineage does not match the generation attempt.";
            }
            if (! $this->sourceLineageMatches(
                $phase,
                $metadata['source_artifact_ids'] ?? null,
                $sourceIds,
            )) {
                $errors[] = "Committed artifact [{$committedArtifactId}] source lineage does not match the generation attempt.";
            }
            if (! in_array($metadata['slot'] ?? null, $requiredSlots, true)) {
                $errors[] = "Committed artifact [{$committedArtifactId}] slot was not reserved by the generation attempt.";
            }
            if (($requestDriver === 'simulation') !== ($metadata['simulation'] ?? null)) {
                $errors[] = "Committed artifact [{$committedArtifactId}] simulation flag does not match the generation attempt.";
            }
            if (! $this->sameCost($metadata['cost_usd'] ?? null, $result['cost_usd'])) {
                $errors[] = "Committed artifact [{$committedArtifactId}] cost_usd does not match the generation attempt result.";
            }

            $validation = $this->validator->validate(
                $storedArtifact,
                $phase,
                $expectedSourcesBySlot[$metadata['slot'] ?? '']
                    ?? ($phase === 'scenes' ? null : $sourceIds),
                $requiredSlots,
                $expectedShotsBySlot[$metadata['slot'] ?? ''] ?? null,
                $expectedSequencePlan,
            );
            foreach ($validation['errors'] as $error) {
                $errors[] = "Committed artifact [{$committedArtifactId}] is invalid: {$error}";
            }

            $this->verifyMediaAsset($project, $run, $phase, $storedArtifact, $errors);
        }

        if (count($storedArtifacts) === count($artifactIds)) {
            $batch = $this->validator->validateBatch($storedArtifacts, $phase, $requiredSlots);
            foreach ($batch['errors'] as $error) {
                $errors[] = 'Committed generation batch is invalid: '.$error;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    private function schemaOneRequestErrors(array $request, string $phase, string $attemptId): array
    {
        $errors = [];
        if (($request['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be 1.';
        }
        if (($request['type'] ?? null) !== 'generation_attempt_request') {
            $errors[] = 'type must be generation_attempt_request.';
        }
        if (($request['id'] ?? null) !== $attemptId || ($request['attempt_id'] ?? null) !== $attemptId) {
            $errors[] = 'id and attempt_id must match the attempt directory.';
        }
        if (($request['phase'] ?? null) !== $phase) {
            $errors[] = 'phase must match the artifact phase.';
        }
        if (! $this->timestamp($request['started_at'] ?? null)) {
            $errors[] = 'started_at must be a valid timestamp.';
        }
        if (! is_string($request['driver'] ?? null) || trim($request['driver']) === '') {
            $errors[] = 'driver must be a non-empty string.';
        }
        if (! $this->safeIdentifierList($request['source_artifact_ids'] ?? null, true)) {
            $errors[] = 'source_artifact_ids must be a non-empty, unique list of safe IDs.';
        }
        if (! $this->safeIdentifierList($request['required_slots'] ?? null, true)) {
            $errors[] = 'required_slots must be a non-empty, unique list of safe IDs.';
        }
        if (! is_int($request['expected_output_count'] ?? null) || $request['expected_output_count'] < 1) {
            $errors[] = 'expected_output_count must be a positive integer.';
        }
        if (! is_int($request['reserved_simulation_units'] ?? null)
            || $request['reserved_simulation_units'] < 0) {
            $errors[] = 'reserved_simulation_units must be a non-negative integer.';
        }
        if (! array_key_exists('cost_limit_usd', $request)
            || ($request['cost_limit_usd'] !== null
                && (! is_numeric($request['cost_limit_usd']) || (float) $request['cost_limit_usd'] < 0))) {
            $errors[] = 'cost_limit_usd must be null or a non-negative number.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function schemaOneResultErrors(array $result, string $phase, string $attemptId): array
    {
        $errors = [];
        if (($result['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be 1.';
        }
        if (($result['type'] ?? null) !== 'generation_attempt_result') {
            $errors[] = 'type must be generation_attempt_result.';
        }
        if (($result['id'] ?? null) !== $attemptId.'--result'
            || ($result['attempt_id'] ?? null) !== $attemptId) {
            $errors[] = 'id and attempt_id must match the attempt directory.';
        }
        if (($result['phase'] ?? null) !== $phase) {
            $errors[] = 'phase must match the artifact phase.';
        }
        if (! $this->timestamp($result['finished_at'] ?? null)) {
            $errors[] = 'finished_at must be a valid timestamp.';
        }
        if (! is_string($result['driver'] ?? null) || trim($result['driver']) === '') {
            $errors[] = 'driver must be a non-empty string.';
        }
        if (! in_array($result['status'] ?? null, ['committed', 'failed', 'cancelled', 'interrupted'], true)) {
            $errors[] = 'status must be committed, failed, cancelled, or interrupted.';
        }
        if (! $this->safeIdentifierList($result['artifact_ids'] ?? null, false)) {
            $errors[] = 'artifact_ids must be a unique list of safe IDs.';
        }
        if (! is_int($result['output_count'] ?? null) || $result['output_count'] < 0) {
            $errors[] = 'output_count must be a non-negative integer.';
        }
        if (! is_int($result['expected_output_count'] ?? null) || $result['expected_output_count'] < 1) {
            $errors[] = 'expected_output_count must be a positive integer.';
        }
        if (! is_int($result['simulation_units'] ?? null) || $result['simulation_units'] < 0) {
            $errors[] = 'simulation_units must be a non-negative integer.';
        }
        if (! array_key_exists('cost_usd', $result)
            || ($result['cost_usd'] !== null
                && (! is_numeric($result['cost_usd']) || (float) $result['cost_usd'] < 0))) {
            $errors[] = 'cost_usd must be null or a non-negative number.';
        }
        if (($result['status'] ?? null) === 'committed'
            && (! is_array($result['artifact_sha256'] ?? null) || array_is_list($result['artifact_sha256']))) {
            $errors[] = 'artifact_sha256 must be a mapping.';
        }

        return $errors;
    }

    private function safeIdentifierList(mixed $value, bool $nonEmpty): bool
    {
        if (! is_array($value) || ! array_is_list($value) || ($nonEmpty && $value === [])) {
            return false;
        }

        foreach ($value as $identifier) {
            if (! is_string($identifier) || ! $this->safeIdentifier($identifier)) {
                return false;
            }
        }

        return count($value) === count(array_unique($value));
    }

    /** @param list<string> $expected */
    private function sameStringSet(mixed $actual, array $expected): bool
    {
        if (! is_array($actual) || ! $this->safeIdentifierList($actual, true)) {
            return false;
        }

        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    /** @param list<string> $requested */
    private function sourceLineageMatches(string $phase, mixed $actual, array $requested): bool
    {
        if ($phase === 'sequence') {
            return is_array($actual)
                && $this->safeIdentifierList($actual, true)
                && $actual === $requested;
        }

        if ($phase !== 'scenes') {
            return $this->sameStringSet($actual, $requested);
        }

        if (! is_array($actual)
            || ! $this->safeIdentifierList($actual, true)
            || count($actual) !== 1) {
            return false;
        }

        return in_array($actual[0], $requested, true);
    }

    private function sameCost(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return is_numeric($left) && is_numeric($right) && (float) $left === (float) $right;
    }

    /** @param list<string> $requiredSlots */
    private function phaseOutputCount(string $phase, array $requiredSlots): ?int
    {
        return match ($phase) {
            'ideas', 'concepts', 'brief-script', 'sequence' => 3,
            'images' => count($requiredSlots) * 2,
            'scenes' => count($requiredSlots),
            default => null,
        };
    }

    private function timestamp(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<string>  $errors
     */
    private function verifyMediaAsset(
        string $project,
        string $run,
        string $phase,
        array $artifact,
        array &$errors,
    ): void {
        $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
        if (! in_array($metadata['artifact_type'] ?? null, ['image_sidecar', 'video_sidecar'], true)) {
            return;
        }

        $errors = array_merge($errors, $this->mediaAssets->validate($metadata));
        $assetPath = is_string($metadata['asset_path'] ?? null) ? trim($metadata['asset_path']) : '';
        if ($assetPath === '' || ! $this->mediaAssets->isSafeRelativePath($assetPath)) {
            return;
        }

        $normalized = str_replace('\\', '/', $assetPath);
        $assetStatus = $metadata['asset_status'] ?? null;
        if (! in_array($assetStatus, [MediaAssetContract::STATUS_READY, MediaAssetContract::STATUS_ABSENT_SIMULATION], true)) {
            return;
        }

        $existing = $this->existingMediaAssets($project, $run, $phase, $normalized, $errors);
        if ($assetStatus === MediaAssetContract::STATUS_ABSENT_SIMULATION) {
            if ($existing !== []) {
                $errors[] = 'An absent_simulation sidecar unexpectedly resolves to a binary asset.';
            }

            return;
        }

        if ($existing === []) {
            if (! collect($errors)->contains(
                fn (string $error): bool => str_contains($error, 'resolves outside the creative run'),
            )) {
                $errors[] = 'Media sidecar declares a ready asset, but the binary file is missing.';
            }

            return;
        }

        if (count($existing) > 1) {
            $errors[] = 'Media asset_path resolves to more than one binary asset.';

            return;
        }

        $asset = $existing[0];
        $expectedHash = $metadata['asset_sha256'] ?? null;
        if (is_string($expectedHash) && preg_match('/^[a-f0-9]{64}$/', $expectedHash) === 1) {
            $actualHash = $this->fileSha256($asset);
            if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                $errors[] = 'Media binary bytes do not match asset_sha256.';
            }
        }

        $expectedBytes = $metadata['asset_bytes'] ?? null;
        if (is_int($expectedBytes) && $expectedBytes > 0) {
            $actualBytes = File::size($asset);
            if ($actualBytes !== $expectedBytes) {
                $errors[] = 'Media binary byte size does not match asset_bytes.';
            }
        }
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function existingMediaAssets(
        string $project,
        string $run,
        string $phase,
        string $assetPath,
        array &$errors,
    ): array {
        $outputsPath = $this->paths->outputsPath($project, $run, $phase);
        $runPath = realpath($this->paths->runPath($project, $run));
        $existing = [];

        foreach (array_unique([$outputsPath.'/'.$assetPath, dirname($outputsPath).'/'.$assetPath]) as $candidate) {
            if (! File::isFile($candidate)) {
                continue;
            }

            $realAsset = realpath($candidate);
            if ($realAsset === false
                || $runPath === false
                || ! str_starts_with($realAsset, $runPath.DIRECTORY_SEPARATOR)) {
                $errors[] = 'Media asset_path resolves outside the creative run.';

                continue;
            }

            $existing[] = $realAsset;
        }

        return array_values(array_unique($existing));
    }

    private function safeIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.-]*$/i', $identifier) === 1
            && ! in_array($identifier, ['.', '..'], true);
    }

    private function fileSha256(string $path): ?string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (! is_array($stat)) {
            return null;
        }

        $fingerprint = implode(':', [
            (string) $stat['dev'],
            (string) $stat['ino'],
            (string) $stat['size'],
            (string) $stat['mtime'],
            (string) $stat['ctime'],
        ]);
        $cached = $this->hashCache[$path] ?? null;
        if (is_array($cached) && hash_equals($cached['fingerprint'], $fingerprint)) {
            return $cached['sha256'];
        }

        $hash = hash_file('sha256', $path);
        $sha256 = is_string($hash) ? $hash : null;
        $this->hashCache[$path] = ['fingerprint' => $fingerprint, 'sha256' => $sha256];

        return $sha256;
    }
}
