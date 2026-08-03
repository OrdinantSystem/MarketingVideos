<?php

namespace App\Creative;

final class EffectiveSelectionResolver
{
    public function __construct(
        private readonly CreativeArtifactValidator $validator,
        private readonly CreativeArtifactEligibilityVerifier $eligibility,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array<string, mixed>>  $artifacts
     * @param  list<string>  $requiredSlots
     * @param  array<string, list<string>>  $expectedSourceIdsBySlot
     * @param  array<string, array<string, mixed>>  $expectedShotsBySlot
     * @param  array<string, mixed>|null  $expectedSequencePlan
     * @return array{resolved: list<array{selection: array<string, mixed>, artifact: array<string, mixed>}>, effective: list<array<string, mixed>>, invalid: list<array{selection: array<string, mixed>, errors: list<string>}>, complete: bool, has_records: bool}
     */
    public function resolve(
        string $phase,
        array $records,
        array $artifacts,
        array $requiredSlots,
        array $expectedSourceIdsBySlot,
        bool $sourceReady,
        array $expectedShotsBySlot = [],
        ?string $project = null,
        ?string $run = null,
        ?array $expectedSequencePlan = null,
    ): array {
        $effective = [];
        $invalid = [];
        $resolved = [];

        foreach ($records as $record) {
            $slot = is_string($record['slot'] ?? null) ? $record['slot'] : '';
            if (! in_array($slot, $requiredSlots, true)) {
                $invalid[] = [
                    'selection' => $record,
                    'errors' => ['Selection slot is not configured for this phase.'],
                ];
            }
        }

        foreach ($requiredSlots as $slot) {
            $slotRecords = array_values(array_filter(
                $records,
                fn (array $record): bool => ($record['slot'] ?? null) === $slot,
            ));
            usort($slotRecords, fn (array $left, array $right): int => $this->newestFirst($left, $right));

            if ($slotRecords === []) {
                continue;
            }

            $selection = $slotRecords[0];
            $effective[] = $selection;
            $errors = $this->selectionErrors($selection, $phase, $slot);

            if (! $sourceReady) {
                $errors[] = 'The previous phase is incomplete or invalid.';
            }

            $artifactId = is_string($selection['artifact_id'] ?? null) ? $selection['artifact_id'] : '';
            $matches = array_values(array_filter(
                $artifacts,
                fn (array $artifact): bool => ($artifact['id'] ?? null) === $artifactId,
            ));

            if (count($matches) !== 1) {
                $errors[] = count($matches) === 0
                    ? 'The selected artifact is missing.'
                    : 'The selected artifact ID is duplicated.';
            }

            $artifact = $matches[0] ?? null;
            if (is_array($artifact)) {
                if (($selection['artifact_path'] ?? null) !== ($artifact['path'] ?? null)) {
                    $errors[] = 'Selection artifact_path does not match the resolved artifact.';
                }

                $validation = $this->validator->validate(
                    $artifact,
                    $phase,
                    $expectedSourceIdsBySlot[$slot] ?? null,
                    $requiredSlots,
                    $expectedShotsBySlot[$slot] ?? null,
                    $expectedSequencePlan,
                );
                $errors = array_merge($errors, $validation['errors']);

                if ($project !== null && $run !== null) {
                    $eligibility = $this->eligibility->verify(
                        $project,
                        $run,
                        $phase,
                        $artifact,
                        $requiredSlots,
                        $expectedSourceIdsBySlot,
                        $expectedShotsBySlot,
                        $expectedSequencePlan,
                    );
                    $errors = array_merge($errors, $eligibility['errors']);
                }
            }

            $errors = array_values(array_unique($errors));
            if ($errors !== [] || ! is_array($artifact)) {
                $invalid[] = ['selection' => $selection, 'errors' => $errors];

                continue;
            }

            $resolved[] = ['selection' => $selection, 'artifact' => $artifact];
        }

        $resolvedSlots = array_map(
            fn (array $item): string => (string) $item['selection']['slot'],
            $resolved,
        );
        sort($resolvedSlots);
        $expectedSlots = $requiredSlots;
        sort($expectedSlots);

        return [
            'resolved' => $resolved,
            'effective' => $effective,
            'invalid' => $invalid,
            'complete' => $sourceReady && $expectedSlots !== [] && $resolvedSlots === $expectedSlots,
            'has_records' => $records !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return list<string>
     */
    private function selectionErrors(array $selection, string $phase, string $slot): array
    {
        $errors = [];

        foreach (['id', 'type', 'phase', 'slot', 'artifact_id', 'artifact_path', 'created_at'] as $field) {
            if (! is_string($selection[$field] ?? null) || trim((string) $selection[$field]) === '') {
                $errors[] = "Selection is missing {$field}.";
            }
        }

        if (($selection['type'] ?? null) !== 'selection') {
            $errors[] = 'Selection type must be selection.';
        }

        if (($selection['phase'] ?? null) !== $phase || ($selection['slot'] ?? null) !== $slot) {
            $errors[] = 'Selection phase or slot does not match its directory.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function newestFirst(array $left, array $right): int
    {
        $sequence = ((int) ($right['sequence'] ?? 0)) <=> ((int) ($left['sequence'] ?? 0));
        if ($sequence !== 0) {
            return $sequence;
        }

        $timestamp = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        if ($timestamp !== 0) {
            return $timestamp;
        }

        return strcmp((string) ($right['_filename'] ?? ''), (string) ($left['_filename'] ?? ''));
    }
}
