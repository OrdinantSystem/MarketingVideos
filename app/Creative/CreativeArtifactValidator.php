<?php

namespace App\Creative;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Throwable;

final class CreativeArtifactValidator
{
    public function __construct(
        private readonly ShotPlanResolver $shotPlans,
        private readonly MediaAssetContract $mediaAssets,
    ) {}

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<string>|null  $expectedSourceIds
     * @param  list<string>|null  $effectiveAllowedSlots
     * @param  array<string, mixed>|null  $expectedShot
     * @param  array<string, mixed>|null  $expectedSequencePlan
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, schema_version: int|null}
     */
    public function validate(
        array $artifact,
        string $expectedPhase,
        ?array $expectedSourceIds = null,
        ?array $effectiveAllowedSlots = null,
        ?array $expectedShot = null,
        ?array $expectedSequencePlan = null,
    ): array {
        $errors = [];
        $warnings = [];
        $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
        $hasSchemaVersion = array_key_exists('schema_version', $metadata);
        $schemaVersion = is_int($metadata['schema_version'] ?? null)
            ? $metadata['schema_version']
            : null;

        if (is_string($artifact['parse_error'] ?? null)) {
            $errors[] = 'The Markdown or YAML front matter cannot be parsed.';
        }

        foreach (['id', 'artifact_type', 'phase', 'slot', 'title', 'summary', 'created_at'] as $field) {
            if (! is_string($metadata[$field] ?? null) || trim((string) $metadata[$field]) === '') {
                $errors[] = "Missing or empty front-matter field: {$field}.";
            }
        }

        if (($metadata['phase'] ?? null) !== $expectedPhase) {
            $errors[] = "Artifact phase must be {$expectedPhase}.";
        }

        $allowedTypes = [
            'ideas' => 'markdown',
            'concepts' => 'markdown',
            'brief-script' => 'markdown',
            'images' => 'image_sidecar',
            'scenes' => 'video_sidecar',
            'sequence' => 'markdown',
        ];

        if (($metadata['artifact_type'] ?? null) !== ($allowedTypes[$expectedPhase] ?? null)) {
            $errors[] = 'Artifact type does not match the phase contract.';
        }

        $errors = array_merge($errors, $this->mediaAssets->validate($metadata));

        $selectionMode = config("creative.phases.{$expectedPhase}.selection_mode", 'single');
        $allowedSlots = $effectiveAllowedSlots;
        if ($allowedSlots === null && $selectionMode === 'single') {
            $configuredSlots = config("creative.phases.{$expectedPhase}.slots", ['primary']);
            $allowedSlots = is_array($configuredSlots) ? array_values($configuredSlots) : ['primary'];
        }

        if ($allowedSlots !== null && ! in_array($metadata['slot'] ?? null, $allowedSlots, true)) {
            $errors[] = 'Artifact slot is not required by the effective phase plan.';
        }

        if (in_array($expectedPhase, ['images', 'scenes'], true)) {
            $shotValidation = $this->shotPlans->validateSnapshot(
                $metadata['shot'] ?? null,
                is_string($metadata['slot'] ?? null) ? $metadata['slot'] : '',
                $expectedShot,
            );
            $errors = array_merge($errors, $shotValidation['errors']);
        }

        if (! is_string($artifact['body'] ?? null) || trim((string) $artifact['body']) === '') {
            $errors[] = 'Artifact body is empty.';
        }

        if (is_string($metadata['created_at'] ?? null)) {
            try {
                new DateTimeImmutable($metadata['created_at']);
            } catch (Throwable) {
                $errors[] = 'Artifact created_at is not a valid timestamp.';
            }
        }

        if (is_string($artifact['filename'] ?? null) && is_string($metadata['id'] ?? null)) {
            $filenameId = Str::beforeLast($artifact['filename'], '.md');

            if ($filenameId !== $metadata['id']) {
                $errors[] = 'Artifact ID does not match its filename.';
            }
        }

        if (! $hasSchemaVersion) {
            if ($expectedPhase === 'brief-script') {
                $shotPlan = $this->shotPlans->resolve($artifact);
                $errors = array_merge($errors, $shotPlan['errors']);
                $warnings = array_merge($warnings, $shotPlan['warnings']);
            }

            $warnings[] = 'Legacy artifact: schema_version and strict provenance are not available.';
        } elseif ($schemaVersion === null) {
            $errors[] = 'Artifact schema_version must be an integer.';
        } elseif ($schemaVersion !== 1) {
            $errors[] = 'Unsupported artifact schema_version.';
        } else {
            $this->validateVersionOne(
                $artifact,
                $metadata,
                $expectedPhase,
                $expectedSourceIds,
                $expectedSequencePlan,
                $errors,
                $warnings,
            );
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'schema_version' => $schemaVersion,
        ];
    }

    /**
     * Validate the shape of one complete generated batch before any artifact is committed.
     *
     * @param  list<array<string, mixed>>  $artifacts
     * @param  list<string>  $requiredSlots
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateBatch(array $artifacts, string $phase, array $requiredSlots): array
    {
        $errors = [];
        $expectedCount = in_array($phase, ['ideas', 'concepts', 'brief-script', 'sequence'], true)
            ? 3
            : match ($phase) {
                'images' => count($requiredSlots) * 2,
                'scenes' => count($requiredSlots),
                default => 0,
            };

        if ($requiredSlots === [] || count($requiredSlots) !== count(array_unique($requiredSlots))) {
            $errors[] = 'Generated batch requires a non-empty unique slot plan.';
        }

        if (count($artifacts) !== $expectedCount) {
            $errors[] = "Generated {$phase} batch must contain exactly {$expectedCount} candidates.";
        }

        $ids = [];
        $orders = [];
        $slots = [];
        $fingerprints = [];
        $variantsBySlot = [];

        foreach ($artifacts as $index => $artifact) {
            $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
            $id = is_string($artifact['id'] ?? null)
                ? trim($artifact['id'])
                : (is_string($metadata['id'] ?? null) ? trim($metadata['id']) : '');
            $slot = is_string($metadata['slot'] ?? null) ? trim($metadata['slot']) : '';
            $order = $metadata['candidate_order'] ?? null;

            if ($id === '') {
                $errors[] = 'Every generated candidate must have an artifact ID before batch validation.';
            } else {
                $ids[] = $id;
            }
            if (! is_int($order) || $order < 1) {
                $errors[] = 'Every generated candidate must have a positive integer candidate_order.';
            } else {
                $orders[] = $order;
            }
            if ($slot === '') {
                $errors[] = 'Every generated candidate must have a slot.';
            } else {
                $slots[] = $slot;
            }

            if (in_array($phase, ['ideas', 'concepts', 'brief-script', 'sequence'], true)) {
                if ($slot !== 'primary') {
                    $errors[] = "Every {$phase} candidate must use the primary slot.";
                }

                $fingerprints[] = hash('sha256', json_encode([
                    'artifact_type' => $metadata['artifact_type'] ?? null,
                    'slot' => $slot,
                    'title' => $metadata['title'] ?? null,
                    'summary' => $metadata['summary'] ?? null,
                    'body' => trim((string) ($artifact['body'] ?? '')),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string) $index);
            }

            if ($phase === 'images' && $slot !== '') {
                $variantsBySlot[$slot][] = $metadata['variant'] ?? null;
            }
        }

        if (count($ids) !== count(array_unique($ids))) {
            $errors[] = 'Generated candidate artifact IDs must be unique within the batch.';
        }

        sort($orders);
        if ($expectedCount > 0 && $orders !== range(1, $expectedCount)) {
            $errors[] = 'Generated candidate_order values must cover the complete batch exactly once.';
        }

        if (in_array($phase, ['ideas', 'concepts', 'brief-script', 'sequence'], true)
            && count($fingerprints) !== count(array_unique($fingerprints))) {
            $errors[] = "Generated {$phase} batch must contain three unique primary candidates.";
        }

        if ($phase === 'images') {
            foreach ($requiredSlots as $slot) {
                $variants = $variantsBySlot[$slot] ?? [];
                sort($variants);
                if ($variants !== ['A', 'B']) {
                    $errors[] = "Generated images batch must contain exactly variants A and B for {$slot}.";
                }
            }
            if (array_values(array_unique($slots)) !== array_values(array_unique(array_intersect($slots, $requiredSlots)))
                || collect($slots)->contains(fn (string $slot): bool => ! in_array($slot, $requiredSlots, true))) {
                $errors[] = 'Generated images batch contains a slot outside the required shot plan.';
            }
        }

        if ($phase === 'scenes') {
            foreach ($requiredSlots as $slot) {
                if (count(array_filter($slots, static fn (string $candidate): bool => $candidate === $slot)) !== 1) {
                    $errors[] = "Generated scenes batch must contain exactly one candidate for {$slot}.";
                }
            }
            if (collect($slots)->contains(fn (string $slot): bool => ! in_array($slot, $requiredSlots, true))) {
                $errors[] = 'Generated scenes batch contains a slot outside the required shot plan.';
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $metadata
     * @param  list<string>|null  $expectedSourceIds
     * @param  array<string, mixed>|null  $expectedSequencePlan
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function validateVersionOne(
        array $artifact,
        array $metadata,
        string $phase,
        ?array $expectedSourceIds,
        ?array $expectedSequencePlan,
        array &$errors,
        array &$warnings,
    ): void {
        foreach (['generation_attempt_id', 'generator', 'prompt_version', 'status'] as $field) {
            if (! is_string($metadata[$field] ?? null) || trim((string) $metadata[$field]) === '') {
                $errors[] = "Missing or empty front-matter field: {$field}.";
            }
        }

        if (! in_array($metadata['status'] ?? null, ['ready', 'simulated'], true)) {
            $errors[] = 'Artifact status must be ready or simulated before selection.';
        }

        if (! is_bool($metadata['simulation'] ?? null)) {
            $errors[] = 'Artifact simulation must be a boolean.';
        }

        foreach (['settings', 'usage'] as $field) {
            if (! is_array($metadata[$field] ?? null)) {
                $errors[] = "Artifact {$field} must be a mapping or list.";
            }
        }

        if (! array_key_exists('cost_usd', $metadata)
            || (! is_numeric($metadata['cost_usd']) && $metadata['cost_usd'] !== null)) {
            $errors[] = 'Artifact cost_usd must be numeric or null when the provider did not report it.';
        } elseif (is_numeric($metadata['cost_usd']) && (float) $metadata['cost_usd'] < 0) {
            $errors[] = 'Artifact cost_usd cannot be negative.';
        } elseif ($metadata['cost_usd'] === null) {
            $warnings[] = 'Provider cost was not reported for this generation attempt.';
        }

        $sourceIds = $this->stringList($metadata['source_artifact_ids'] ?? null);
        if ($sourceIds === null || $sourceIds === []) {
            $errors[] = 'source_artifact_ids must be a non-empty list of artifact IDs.';
        } elseif (count($sourceIds) !== count(array_unique($sourceIds))) {
            $errors[] = 'source_artifact_ids cannot contain duplicates.';
        }

        if ($expectedSourceIds !== null && $sourceIds !== null) {
            $actual = $sourceIds;
            $expected = array_values(array_unique($expectedSourceIds));

            if ($phase !== 'sequence') {
                sort($actual);
                sort($expected);
            }

            if ($actual !== $expected) {
                $errors[] = 'Artifact sources do not match the current effective selection.';
            }
        }

        if ($phase === 'images') {
            if (! in_array($metadata['variant'] ?? null, ['A', 'B'], true)) {
                $errors[] = 'Image sidecar variant must be A or B.';
            }
        }

        if ($phase === 'brief-script') {
            $this->validateVersionOneScript($artifact, $metadata, $errors);
        }

        if ($phase === 'sequence') {
            $this->validateVersionOneSequence(
                $artifact,
                $metadata,
                $sourceIds,
                $expectedSequencePlan,
                $errors,
            );
        }

        $requiredSections = [
            'ideas' => ['idea', 'recognizable moment', 'emotional tension', 'product connection', 'what this tests'],
            'concepts' => ['hook', 'emotional turn', 'product mechanism', 'creative hypothesis', 'risks or constraints'],
            'brief-script' => ['purpose', 'audience', 'story', 'tone and constraints', '15 second script', 'shot intentions', 'call to action'],
            'images' => ['visual', 'direction', 'generation prompt', 'asset status'],
            'scenes' => ['motion', 'duration', 'asset status'],
            'sequence' => ['scene order', 'exact timing', 'captions', 'voice timing', 'sound', 'transitions', 'aspect ratio', 'total duration', 'editor notes'],
        ];
        $sectionTitles = collect(is_array($artifact['sections'] ?? null) ? $artifact['sections'] : [])
            ->map(fn (mixed $section): string => is_array($section)
                ? $this->normalizeHeading((string) ($section['title'] ?? ''))
                : '')
            ->filter()
            ->values();

        foreach ($requiredSections[$phase] ?? [] as $required) {
            if (! $sectionTitles->contains(fn (string $title): bool => str_contains($title, $required))) {
                $errors[] = "Missing required section: {$required}.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $errors
     */
    private function validateVersionOneScript(array $artifact, array $metadata, array &$errors): void
    {
        if (! array_key_exists('shot_plan', $metadata)) {
            $errors[] = 'Schema-v1 brief/script artifact requires a structured shot_plan in front matter.';
            $structured = null;
        } else {
            $structured = $this->shotPlans->validate($metadata['shot_plan']);
            $errors = array_merge($errors, $structured['errors']);
        }

        $timed = $this->shotPlans->fromTimedMarkdown(
            is_string($artifact['body'] ?? null) ? $artifact['body'] : '',
        );
        if (! $timed['valid'] || ! is_array($timed['shot_plan'])) {
            foreach ($timed['errors'] as $error) {
                $errors[] = "Schema-v1 brief/script human-readable timing is invalid: {$error}";
            }

            return;
        }

        if (is_array($structured)
            && $structured['valid']
            && is_array($structured['shot_plan'])
            && $this->planSignature($structured['shot_plan']) !== $this->planSignature($timed['shot_plan'])) {
            $errors[] = 'Structured shot_plan does not match the human-readable timed script.';
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $metadata
     * @param  list<string>|null  $sourceIds
     * @param  array<string, mixed>|null  $expectedSequencePlan
     * @param  list<string>  $errors
     */
    private function validateVersionOneSequence(
        array $artifact,
        array $metadata,
        ?array $sourceIds,
        ?array $expectedSequencePlan,
        array &$errors,
    ): void {
        if (! array_key_exists('shot_plan', $metadata)) {
            $errors[] = 'Schema-v1 sequence artifact requires a structured shot_plan in front matter.';
            $structured = null;
        } else {
            $structured = $this->shotPlans->validate($metadata['shot_plan']);
            $errors = array_merge($errors, $structured['errors']);
        }

        $sceneIds = $this->stringList($metadata['scene_artifact_ids'] ?? null);
        if ($sceneIds === null || $sceneIds === []) {
            $errors[] = 'Schema-v1 sequence artifact requires a non-empty ordered scene_artifact_ids list.';
        } elseif (count($sceneIds) !== count(array_unique($sceneIds))) {
            $errors[] = 'Sequence scene_artifact_ids cannot contain duplicates.';
        }

        if ($sceneIds !== null && $sourceIds !== null && $sceneIds !== $sourceIds) {
            $errors[] = 'Sequence source_artifact_ids must exactly equal ordered scene_artifact_ids.';
        }

        if (is_array($structured)
            && $structured['valid']
            && is_array($structured['shot_plan'])
            && $sceneIds !== null
            && count($structured['shot_plan']['shots']) !== count($sceneIds)) {
            $errors[] = 'Sequence shot_plan must contain exactly one shot for every ordered scene artifact.';
        }

        if (is_array($structured)
            && $structured['valid']
            && is_array($structured['shot_plan'])
            && $sceneIds !== null
            && $sceneIds !== []
            && count($structured['shot_plan']['shots']) === count($sceneIds)) {
            $errors = array_merge($errors, $this->shotPlans->validateSequenceBody(
                is_string($artifact['body'] ?? null) ? $artifact['body'] : '',
                $structured['shot_plan'],
                $sceneIds,
            ));
        }

        if ($expectedSequencePlan !== null) {
            $expected = $this->shotPlans->validate($expectedSequencePlan);
            if (! $expected['valid'] || ! is_array($expected['shot_plan'])) {
                $errors[] = 'Current selected scenes do not resolve to a valid expected sequence shot plan.';
            } elseif (is_array($structured)
                && $structured['valid']
                && is_array($structured['shot_plan'])
                && $this->planSignature($structured['shot_plan']) !== $this->planSignature($expected['shot_plan'])) {
                $errors[] = 'Sequence shot_plan does not match the current selected scene snapshots.';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planSignature(array $plan): array
    {
        $shots = is_array($plan['shots'] ?? null) ? $plan['shots'] : [];

        return [
            'version' => $plan['version'] ?? null,
            'total_duration_seconds' => $plan['total_duration_seconds'] ?? null,
            'shots' => array_values(array_map(static fn (mixed $shot): array => is_array($shot) ? [
                'id' => $shot['id'] ?? null,
                'order' => $shot['order'] ?? null,
                'start_seconds' => $shot['start_seconds'] ?? null,
                'end_seconds' => $shot['end_seconds'] ?? null,
                'purpose' => $shot['purpose'] ?? null,
                'visual' => $shot['visual'] ?? null,
            ] : [], $shots)),
        ];
    }

    /** @return list<string>|null */
    private function stringList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                return null;
            }

            $strings[] = $item;
        }

        return $strings;
    }

    private function normalizeHeading(string $heading): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($heading)));

        return trim(is_string($normalized) ? $normalized : '');
    }
}
