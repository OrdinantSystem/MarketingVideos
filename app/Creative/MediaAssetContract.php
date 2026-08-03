<?php

namespace App\Creative;

final class MediaAssetContract
{
    public const STATUS_READY = 'ready';

    public const STATUS_ABSENT_SIMULATION = 'absent_simulation';

    /**
     * Container duration is frame-quantized. A ready scene may differ from its
     * requested shot duration by at most one 8 fps simulation frame (125 ms).
     */
    public const VIDEO_DURATION_TOLERANCE_SECONDS = 0.125;

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    public function validate(array $metadata): array
    {
        $artifactType = $metadata['artifact_type'] ?? null;
        if (! in_array($artifactType, ['image_sidecar', 'video_sidecar'], true)) {
            return [];
        }

        $errors = [];
        $assetPath = is_string($metadata['asset_path'] ?? null)
            ? trim($metadata['asset_path'])
            : '';
        if ($assetPath === '') {
            $errors[] = 'Media sidecar requires a non-empty asset_path.';
        } elseif (! $this->isSafeRelativePath($assetPath)) {
            $errors[] = 'Media asset_path is unsafe or escapes the creative run.';
        }

        $assetStatus = $metadata['asset_status'] ?? null;
        if (! in_array($assetStatus, [self::STATUS_READY, self::STATUS_ABSENT_SIMULATION], true)) {
            $errors[] = 'Media asset_status must be ready or absent_simulation.';

            return array_values(array_unique($errors));
        }

        if ($assetStatus === self::STATUS_ABSENT_SIMULATION) {
            if (($metadata['status'] ?? null) !== 'simulated' || ($metadata['simulation'] ?? null) !== true) {
                $errors[] = 'An absent_simulation asset is eligible only for a deliberate simulated artifact.';
            }

            foreach (['asset_sha256', 'asset_bytes', 'media_probe'] as $field) {
                if (array_key_exists($field, $metadata)) {
                    $errors[] = "An absent_simulation asset must not declare {$field}.";
                }
            }

            return array_values(array_unique($errors));
        }

        if (($metadata['status'] ?? null) !== 'ready') {
            $errors[] = 'A ready media asset requires overall artifact status ready.';
        }

        if (! is_string($metadata['asset_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $metadata['asset_sha256']) !== 1) {
            $errors[] = 'A ready media asset requires a lowercase 64-character asset_sha256.';
        }

        if (! is_int($metadata['asset_bytes'] ?? null) || $metadata['asset_bytes'] <= 0) {
            $errors[] = 'A ready media asset requires a positive integer asset_bytes.';
        }

        $probe = $metadata['media_probe'] ?? null;
        if (! is_array($probe) || array_is_list($probe)) {
            $errors[] = 'A ready media asset requires a media_probe mapping.';

            return array_values(array_unique($errors));
        }

        $expectedMediaType = $artifactType === 'image_sidecar' ? 'image' : 'video';
        if (($probe['media_type'] ?? null) !== $expectedMediaType) {
            $errors[] = "Media probe media_type must be {$expectedMediaType}.";
        }

        foreach (['mime_type', 'codec'] as $field) {
            if (! is_string($probe[$field] ?? null) || trim($probe[$field]) === '') {
                $errors[] = "Media probe requires a non-empty {$field}.";
            }
        }

        foreach (['width', 'height'] as $field) {
            if (! is_int($probe[$field] ?? null) || $probe[$field] <= 0) {
                $errors[] = "Media probe requires a positive integer {$field}.";
            }
        }

        if ($expectedMediaType === 'video') {
            $probeDuration = is_numeric($probe['duration_seconds'] ?? null)
                ? (float) $probe['duration_seconds']
                : null;
            if ($probeDuration === null || ! is_finite($probeDuration) || $probeDuration <= 0) {
                $errors[] = 'Video media probe requires a positive duration_seconds.';
            }

            $shot = $metadata['shot'] ?? null;
            $start = is_array($shot) && is_numeric($shot['start_seconds'] ?? null)
                ? (float) $shot['start_seconds']
                : null;
            $end = is_array($shot) && is_numeric($shot['end_seconds'] ?? null)
                ? (float) $shot['end_seconds']
                : null;
            if ($start === null
                || $end === null
                || ! is_finite($start)
                || ! is_finite($end)
                || $end <= $start) {
                $errors[] = 'A ready video sidecar requires valid shot start_seconds and end_seconds.';
            } elseif ($probeDuration !== null
                && is_finite($probeDuration)
                && $probeDuration > 0
                && abs($probeDuration - ($end - $start)) > self::VIDEO_DURATION_TOLERANCE_SECONDS) {
                $errors[] = sprintf(
                    'Video media probe duration_seconds must match the shot duration within %.3f seconds.',
                    self::VIDEO_DURATION_TOLERANCE_SECONDS,
                );
            }
        }

        if (array_key_exists('audio_codec', $probe)
            && $probe['audio_codec'] !== null
            && (! is_string($probe['audio_codec']) || trim($probe['audio_codec']) === '')) {
            $errors[] = 'Media probe audio_codec must be a non-empty string or null.';
        }

        return array_values(array_unique($errors));
    }

    public function isSafeRelativePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', trim($path));

        return $normalized !== ''
            && ! str_contains($normalized, "\0")
            && ! str_starts_with($normalized, '/')
            && preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized) !== 1
            && ! collect(explode('/', $normalized))->contains(
                fn (string $segment): bool => in_array($segment, ['', '.', '..'], true),
            );
    }
}
