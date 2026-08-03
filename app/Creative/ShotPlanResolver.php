<?php

namespace App\Creative;

final class ShotPlanResolver
{
    private const MAX_SHOTS = 12;

    private const MAX_DURATION_SECONDS = 15.0;

    private const TIMING_TOLERANCE = 0.001;

    /**
     * @param  array<string, mixed>  $artifact
     * @return array{valid: bool, shot_plan: array<string, mixed>|null, errors: list<string>, warnings: list<string>, origin: 'metadata'|'legacy_markdown'|null}
     */
    public function resolve(array $artifact): array
    {
        $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];

        if (array_key_exists('shot_plan', $metadata)) {
            return $this->validate($metadata['shot_plan'], 'metadata');
        }

        $legacy = $this->fromTimedMarkdown(is_string($artifact['body'] ?? null) ? $artifact['body'] : '');
        if (! $legacy['valid']) {
            return $legacy;
        }

        $legacy['warnings'][] = 'Shot plan was derived from legacy timed Markdown because front matter has no structured shot_plan.';

        return $legacy;
    }

    /**
     * Convert the timed entries under `## 15-second script` into a canonical plan.
     *
     * @return array{valid: bool, shot_plan: array<string, mixed>|null, errors: list<string>, warnings: list<string>, origin: 'legacy_markdown'|null}
     */
    public function fromTimedMarkdown(string $body): array
    {
        if (preg_match('/^##\s+15[\s-]*second\s+script\s*\R(.*?)(?=^##\s+|\z)/imsu', $body, $sectionMatch) !== 1) {
            return $this->legacyResult($this->failure(
                'Brief/script artifact has no parseable `## 15-second script` section.',
                'legacy_markdown',
            ));
        }

        $section = trim($sectionMatch[1]);
        $pattern = '/\*\*\s*(?<start>\d+(?:\.\d+)?)\s*[\x{2013}\x{2014}-]\s*(?<end>\d+(?:\.\d+)?)\s*s(?:\s*[\x{2013}\x{2014}-]\s*(?<purpose>[^*\r\n]+?))?\s*\*\*/iu';
        $matched = preg_match_all($pattern, $section, $matches, PREG_OFFSET_CAPTURE);

        if (! is_int($matched) || $matched < 1) {
            return $this->legacyResult($this->failure(
                'The 15-second script must contain at least one bold timed shot entry.',
                'legacy_markdown',
            ));
        }

        $shots = [];
        for ($index = 0; $index < $matched; $index++) {
            $marker = $matches[0][$index][0];
            $markerOffset = $matches[0][$index][1];
            $visualStart = $markerOffset + strlen($marker);
            $visualEnd = $index + 1 < $matched
                ? $matches[0][$index + 1][1]
                : strlen($section);
            $visual = $this->plainText(substr($section, $visualStart, $visualEnd - $visualStart));
            $purpose = trim((string) ($matches['purpose'][$index][0] ?? ''));

            $shots[] = [
                'id' => sprintf('shot-%02d', $index + 1),
                'order' => $index + 1,
                'start_seconds' => (float) $matches['start'][$index][0],
                'end_seconds' => (float) $matches['end'][$index][0],
                'purpose' => $purpose !== '' ? $purpose : 'Beat '.($index + 1),
                'visual' => $visual,
            ];
        }

        $plan = [
            'version' => 1,
            'total_duration_seconds' => (float) $shots[array_key_last($shots)]['end_seconds'],
            'shots' => $shots,
        ];

        return $this->legacyResult($this->validate($plan, 'legacy_markdown'));
    }

    /**
     * Build the exact ordered sequence contract from selected scene artifacts.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return array{valid: bool, shot_plan: array<string, mixed>|null, scene_artifact_ids: list<string>, errors: list<string>}
     */
    public function sequenceContract(array $scenes): array
    {
        if ($scenes === []) {
            return [
                'valid' => false,
                'shot_plan' => null,
                'scene_artifact_ids' => [],
                'errors' => ['Sequence generation requires at least one selected scene.'],
            ];
        }

        $errors = [];
        $entries = [];
        $seenArtifactIds = [];
        $seenSlots = [];

        foreach ($scenes as $index => $scene) {
            $position = $index + 1;
            $metadata = is_array($scene['metadata'] ?? null) ? $scene['metadata'] : [];
            $artifactId = is_string($scene['id'] ?? null)
                ? trim($scene['id'])
                : (is_string($metadata['id'] ?? null) ? trim($metadata['id']) : '');
            $slot = is_string($scene['slot'] ?? null)
                ? trim($scene['slot'])
                : (is_string($metadata['slot'] ?? null) ? trim($metadata['slot']) : '');
            $shot = $metadata['shot'] ?? null;

            if ($artifactId === '') {
                $errors[] = "Selected scene {$position} has no artifact ID.";
            } elseif (in_array($artifactId, $seenArtifactIds, true)) {
                $errors[] = "Selected scene artifact ID {$artifactId} is duplicated.";
            } else {
                $seenArtifactIds[] = $artifactId;
            }

            if ($slot === '') {
                $errors[] = "Selected scene {$position} has no shot slot.";
            } elseif (in_array($slot, $seenSlots, true)) {
                $errors[] = "Selected scene slot {$slot} is duplicated.";
            } else {
                $seenSlots[] = $slot;
            }

            $snapshot = $this->validateSnapshot($shot, $slot);
            foreach ($snapshot['errors'] as $error) {
                $errors[] = "Selected scene {$position}: {$error}";
            }

            if ($artifactId !== '' && $slot !== '' && is_array($shot) && $snapshot['valid']) {
                $entries[] = [
                    'artifact_id' => $artifactId,
                    'shot' => $shot,
                ];
            }
        }

        usort($entries, static fn (array $left, array $right): int => ((int) $left['shot']['order']) <=> ((int) $right['shot']['order']));

        if ($entries === []) {
            return [
                'valid' => false,
                'shot_plan' => null,
                'scene_artifact_ids' => [],
                'errors' => array_values(array_unique($errors)),
            ];
        }

        $shots = array_map(static fn (array $entry): array => $entry['shot'], $entries);
        $plan = $this->validate([
            'version' => 1,
            'total_duration_seconds' => (float) ($shots[array_key_last($shots)]['end_seconds'] ?? 0),
            'shots' => $shots,
        ]);
        $errors = array_merge($errors, $plan['errors']);

        if (count($entries) !== count($scenes)) {
            $errors[] = 'Every selected scene must contribute exactly one canonical shot to the sequence.';
        }

        return [
            'valid' => $errors === [] && $plan['valid'] && is_array($plan['shot_plan']),
            'shot_plan' => $plan['valid'] && is_array($plan['shot_plan']) ? $plan['shot_plan'] : null,
            'scene_artifact_ids' => array_map(
                static fn (array $entry): string => $entry['artifact_id'],
                $entries,
            ),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * Replace the human-readable Sequence lineage sections with the exact
     * ordered contract represented by front matter. Provider prose may still
     * vary in the other editor-facing sections.
     *
     * @param  array<string, mixed>  $shotPlan
     * @param  list<string>  $sceneArtifactIds
     */
    public function normalizeSequenceBody(string $body, array $shotPlan, array $sceneArtifactIds): string
    {
        foreach ($this->sequenceBodySections($shotPlan, $sceneArtifactIds) as $heading => $content) {
            $body = $this->replaceMarkdownSection($body, $heading, $content);
        }

        return trim($body);
    }

    /**
     * @param  array<string, mixed>  $shotPlan
     * @param  list<string>  $sceneArtifactIds
     * @return list<string>
     */
    public function validateSequenceBody(string $body, array $shotPlan, array $sceneArtifactIds): array
    {
        $sections = MarkdownDocument::sections($body);
        $errors = [];

        foreach ($this->sequenceBodySections($shotPlan, $sceneArtifactIds) as $heading => $expectedContent) {
            $matches = array_values(array_filter(
                $sections,
                fn (array $section): bool => $this->normalizedHeading($section['title']) === $this->normalizedHeading($heading),
            ));

            if (count($matches) !== 1) {
                $errors[] = "Sequence body must contain exactly one ## {$heading} section.";

                continue;
            }

            $actualContent = str_replace(["\r\n", "\r"], "\n", trim($matches[0]['content']));
            if ($actualContent !== $expectedContent) {
                $errors[] = "Sequence body {$heading} does not exactly match the ordered scene manifest.";
            }
        }

        return $errors;
    }

    /**
     * @return array{valid: bool, shot_plan: array<string, mixed>|null, errors: list<string>, warnings: list<string>, origin: 'metadata'|'legacy_markdown'|null}
     */
    public function validate(mixed $shotPlan, string $origin = 'metadata'): array
    {
        if (! is_array($shotPlan) || array_is_list($shotPlan)) {
            return $this->failure('shot_plan must be a mapping.', $origin);
        }

        $errors = [];
        if (($shotPlan['version'] ?? null) !== 1) {
            $errors[] = 'shot_plan.version must be 1.';
        }

        $totalDuration = $this->number($shotPlan['total_duration_seconds'] ?? null);
        if ($totalDuration === null) {
            $errors[] = 'shot_plan.total_duration_seconds must be numeric.';
        } elseif ($totalDuration <= 0 || $totalDuration > self::MAX_DURATION_SECONDS) {
            $errors[] = 'shot_plan.total_duration_seconds must be greater than 0 and no more than 15.';
        }

        $shots = $shotPlan['shots'] ?? null;
        if (! is_array($shots) || ! array_is_list($shots)) {
            $errors[] = 'shot_plan.shots must be a list.';
            $shots = [];
        } elseif ($shots === [] || count($shots) > self::MAX_SHOTS) {
            $errors[] = 'shot_plan.shots must contain between 1 and 12 shots.';
        }

        $canonicalShots = [];
        $seenIds = [];
        $previousEnd = null;

        foreach ($shots as $index => $shot) {
            $position = $index + 1;
            if (! is_array($shot) || array_is_list($shot)) {
                $errors[] = "Shot {$position} must be a mapping.";

                continue;
            }

            $id = is_string($shot['id'] ?? null) ? trim($shot['id']) : '';
            $order = $shot['order'] ?? null;
            $start = $this->number($shot['start_seconds'] ?? null);
            $end = $this->number($shot['end_seconds'] ?? null);
            $purpose = is_string($shot['purpose'] ?? null) ? trim($shot['purpose']) : '';
            $visual = is_string($shot['visual'] ?? null) ? trim($shot['visual']) : '';
            $expectedId = sprintf('shot-%02d', $position);

            if ($id !== $expectedId) {
                $errors[] = "Shot {$position} id must be {$expectedId}.";
            }
            if ($id !== '' && in_array($id, $seenIds, true)) {
                $errors[] = "Shot ID {$id} is duplicated.";
            }
            if ($id !== '') {
                $seenIds[] = $id;
            }
            if (! is_int($order) || $order !== $position) {
                $errors[] = "Shot {$position} order must be {$position}.";
            }
            if ($start === null) {
                $errors[] = "Shot {$position} start_seconds must be numeric.";
            }
            if ($end === null) {
                $errors[] = "Shot {$position} end_seconds must be numeric.";
            }
            if ($start !== null && $end !== null && $end <= $start) {
                $errors[] = "Shot {$position} must end after it starts.";
            }
            if ($start !== null && ($start < 0 || $start > self::MAX_DURATION_SECONDS)) {
                $errors[] = "Shot {$position} start_seconds must be between 0 and 15.";
            }
            if ($end !== null && ($end <= 0 || $end > self::MAX_DURATION_SECONDS)) {
                $errors[] = "Shot {$position} end_seconds must be greater than 0 and no more than 15.";
            }
            if ($start !== null && $position === 1 && ! $this->sameTime($start, 0.0)) {
                $errors[] = 'The first shot must start at 0 seconds.';
            }
            if ($start !== null && $previousEnd !== null && ! $this->sameTime($start, $previousEnd)) {
                $errors[] = "Shot {$position} must start when the previous shot ends.";
            }
            if ($purpose === '') {
                $errors[] = "Shot {$position} purpose must be a non-empty string.";
            }
            if ($visual === '') {
                $errors[] = "Shot {$position} visual must be a non-empty string.";
            }

            if ($end !== null) {
                $previousEnd = $end;
            }

            if ($id !== '' && is_int($order) && $start !== null && $end !== null && $purpose !== '' && $visual !== '') {
                $canonicalShots[] = array_merge($shot, [
                    'id' => $id,
                    'order' => $order,
                    'start_seconds' => $start,
                    'end_seconds' => $end,
                    'purpose' => $purpose,
                    'visual' => $visual,
                ]);
            }
        }

        if ($totalDuration !== null && $previousEnd !== null && ! $this->sameTime($totalDuration, $previousEnd)) {
            $errors[] = 'shot_plan.total_duration_seconds must equal the final shot end_seconds.';
        }

        $errors = array_values(array_unique($errors));
        if ($errors !== []) {
            return [
                'valid' => false,
                'shot_plan' => null,
                'errors' => $errors,
                'warnings' => [],
                'origin' => in_array($origin, ['metadata', 'legacy_markdown'], true) ? $origin : null,
            ];
        }

        return [
            'valid' => true,
            'shot_plan' => [
                'version' => 1,
                'total_duration_seconds' => $totalDuration,
                'shots' => $canonicalShots,
            ],
            'errors' => [],
            'warnings' => [],
            'origin' => $origin === 'legacy_markdown' ? 'legacy_markdown' : 'metadata',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $expectedShot
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateSnapshot(mixed $shot, string $expectedSlot, ?array $expectedShot = null): array
    {
        if (! is_array($shot) || array_is_list($shot)) {
            return [
                'valid' => false,
                'errors' => ['Media artifact requires a canonical shot metadata snapshot.'],
            ];
        }

        $errors = [];
        $id = $shot['id'] ?? null;
        $order = $shot['order'] ?? null;
        $start = $this->number($shot['start_seconds'] ?? null);
        $end = $this->number($shot['end_seconds'] ?? null);
        $purpose = $shot['purpose'] ?? null;
        $visual = $shot['visual'] ?? null;

        if (! is_string($id) || $id !== $expectedSlot) {
            $errors[] = 'Media shot snapshot id must match the artifact slot.';
        }
        if (! is_int($order) || $order < 1 || $id !== sprintf('shot-%02d', $order)) {
            $errors[] = 'Media shot snapshot order and id are not canonical.';
        }
        if ($start === null || $start < 0 || $start > self::MAX_DURATION_SECONDS) {
            $errors[] = 'Media shot snapshot start_seconds must be numeric and between 0 and 15.';
        }
        if ($end === null || $end <= 0 || $end > self::MAX_DURATION_SECONDS) {
            $errors[] = 'Media shot snapshot end_seconds must be numeric, greater than 0, and no more than 15.';
        }
        if ($start !== null && $end !== null && $end <= $start) {
            $errors[] = 'Media shot snapshot must end after it starts.';
        }
        if (! is_string($purpose) || $purpose === '' || trim($purpose) !== $purpose) {
            $errors[] = 'Media shot snapshot purpose must be a canonical non-empty string.';
        }
        if (! is_string($visual) || $visual === '' || trim($visual) !== $visual) {
            $errors[] = 'Media shot snapshot visual must be a canonical non-empty string.';
        }

        if ($expectedShot !== null) {
            foreach (['id', 'order', 'purpose', 'visual'] as $field) {
                if (($shot[$field] ?? null) !== ($expectedShot[$field] ?? null)) {
                    $errors[] = "Media shot snapshot does not match the selected script shot plan: {$field}.";
                }
            }

            foreach (['start_seconds', 'end_seconds'] as $field) {
                $actual = $this->number($shot[$field] ?? null);
                $expected = $this->number($expectedShot[$field] ?? null);
                if ($actual === null || $expected === null || $actual !== $expected) {
                    $errors[] = "Media shot snapshot does not match the selected script shot plan: {$field}.";
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array{valid: false, shot_plan: null, errors: list<string>, warnings: list<string>, origin: 'metadata'|'legacy_markdown'|null}
     */
    private function failure(string $error, ?string $origin = null): array
    {
        return [
            'valid' => false,
            'shot_plan' => null,
            'errors' => [$error],
            'warnings' => [],
            'origin' => in_array($origin, ['metadata', 'legacy_markdown'], true) ? $origin : null,
        ];
    }

    /**
     * @param  array{valid: bool, shot_plan: array<string, mixed>|null, errors: list<string>, warnings: list<string>, origin: 'metadata'|'legacy_markdown'|null}  $result
     * @return array{valid: bool, shot_plan: array<string, mixed>|null, errors: list<string>, warnings: list<string>, origin: 'legacy_markdown'|null}
     */
    private function legacyResult(array $result): array
    {
        return [
            'valid' => $result['valid'],
            'shot_plan' => $result['shot_plan'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'origin' => $result['origin'] === 'legacy_markdown' ? 'legacy_markdown' : null,
        ];
    }

    private function number(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function sameTime(float $left, float $right): bool
    {
        return abs($left - $right) <= self::TIMING_TOLERANCE;
    }

    private function plainText(string $markdown): string
    {
        $withoutEmphasis = preg_replace('/[*_`]+/u', '', $markdown) ?? $markdown;
        $singleLine = preg_replace('/\s+/u', ' ', $withoutEmphasis) ?? $withoutEmphasis;

        return trim($singleLine);
    }

    /**
     * @param  array<string, mixed>  $shotPlan
     * @param  list<string>  $sceneArtifactIds
     * @return array<string, string>
     */
    private function sequenceBodySections(array $shotPlan, array $sceneArtifactIds): array
    {
        $shots = is_array($shotPlan['shots'] ?? null) ? array_values($shotPlan['shots']) : [];
        $sceneOrder = [];
        $exactTiming = [];

        foreach ($shots as $index => $shot) {
            if (! is_array($shot)) {
                continue;
            }

            $purpose = preg_replace('/\s+/u', ' ', trim((string) ($shot['purpose'] ?? ''))) ?? '';
            $purpose = str_replace('`', '\\`', $purpose);
            $shotId = (string) ($shot['id'] ?? '');
            $sceneId = $sceneArtifactIds[$index] ?? '';
            $sceneOrder[] = sprintf(
                '%d. `%s` — %s — `%s`',
                (int) ($shot['order'] ?? ($index + 1)),
                $shotId,
                $purpose,
                $sceneId,
            );
            $exactTiming[] = sprintf(
                '- %s–%ss · `%s` · %s',
                $this->formatSeconds((float) ($shot['start_seconds'] ?? 0)),
                $this->formatSeconds((float) ($shot['end_seconds'] ?? 0)),
                $shotId,
                $purpose,
            );
        }

        return [
            'Scene order' => implode("\n", $sceneOrder),
            'Exact timing' => implode("\n", $exactTiming),
            'Total duration' => $this->formatSeconds((float) ($shotPlan['total_duration_seconds'] ?? 0)).' seconds.',
        ];
    }

    private function replaceMarkdownSection(string $body, string $heading, string $content): string
    {
        $pattern = '/(^##\s+'.preg_quote($heading, '/').'\s*\R)(.*?)(?=^##\s+|\z)/imsu';
        $replaced = 0;
        $normalized = preg_replace_callback(
            $pattern,
            static fn (array $matches): string => rtrim($matches[1])."\n\n{$content}\n\n",
            $body,
            1,
            $replaced,
        );

        if (is_string($normalized) && $replaced === 1) {
            return rtrim($normalized);
        }

        return rtrim($body)."\n\n## {$heading}\n\n{$content}";
    }

    private function normalizedHeading(string $heading): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', strtolower($heading));

        return trim(is_string($normalized) ? $normalized : '');
    }

    private function formatSeconds(float $seconds): string
    {
        $formatted = rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');

        return str_contains($formatted, '.') ? $formatted : $formatted.'.0';
    }
}
