<?php

namespace App\Creative\Rendering;

use App\Creative\CreativePathResolver;
use App\Creative\Media\MediaProbeService;
use App\Creative\MediaAssetContract;
use App\Creative\ShotPlanResolver;
use DateTimeImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class RenderCompletionValidator
{
    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly MediaAssetContract $mediaAssets,
        private readonly MediaProbeService $probe,
        private readonly ShotPlanResolver $shotPlans,
        private readonly RenderTimelineContract $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $result
     */
    public function validate(
        string $project,
        string $run,
        string $renderId,
        array $request,
        array $result,
    ): void {
        $errors = [];
        if (($request['schema_version'] ?? null) !== 1
            || ($request['id'] ?? null) !== $renderId
            || ($request['type'] ?? null) !== 'render_request'
            || ($request['render_id'] ?? null) !== $renderId
            || ($request['project_id'] ?? null) !== $project
            || ($request['run_id'] ?? null) !== $run
            || ($request['simulation'] ?? null) !== true
            || ! $this->timestamp($request['created_at'] ?? null)) {
            $errors[] = 'The immutable render request identity is invalid.';
        }
        if (($result['schema_version'] ?? null) !== 1
            || ($result['id'] ?? null) !== $renderId.'--result'
            || ($result['type'] ?? null) !== 'render_result'
            || ($result['render_id'] ?? null) !== $renderId
            || ($result['status'] ?? null) !== 'completed'
            || ($result['simulation'] ?? null) !== true
            || ! $this->timestamp($result['finished_at'] ?? null)) {
            $errors[] = 'The immutable completed render manifest identity is invalid.';
        }

        foreach (['renderer', 'sequence_artifact_id', 'sequence_selection_id', 'scene_artifact_ids', 'scene_selection_ids', 'scene_asset_sha256'] as $field) {
            if (! array_key_exists($field, $request)
                || ! array_key_exists($field, $result)
                || $request[$field] !== $result[$field]) {
                $errors[] = "The completed render manifest does not match request field {$field}.";
            }
        }

        $sceneIds = $request['scene_artifact_ids'] ?? null;
        $sceneSelectionIds = $request['scene_selection_ids'] ?? null;
        $sceneHashes = $request['scene_asset_sha256'] ?? null;
        if (! is_string($request['renderer'] ?? null)
            || trim($request['renderer']) === ''
            || ! is_string($request['sequence_artifact_id'] ?? null)
            || trim($request['sequence_artifact_id']) === ''
            || ! is_string($request['sequence_selection_id'] ?? null)
            || trim($request['sequence_selection_id']) === ''
            || ! $this->stringList($sceneIds)
            || ! $this->stringList($sceneSelectionIds)
            || count($sceneIds) !== count($sceneSelectionIds)
            || ! is_array($sceneHashes)
            || array_is_list($sceneHashes)
            || array_keys($sceneHashes) !== $sceneIds) {
            $errors[] = 'The immutable render request lineage shape is invalid.';
        } else {
            foreach ($sceneIds as $sceneId) {
                if (! is_string($sceneHashes[$sceneId] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/', $sceneHashes[$sceneId]) !== 1) {
                    $errors[] = 'The immutable render request has an invalid scene asset hash.';

                    break;
                }
            }
        }

        $shotPlan = $this->shotPlans->validate($request['shot_plan'] ?? null);
        $settings = is_array($request['settings'] ?? null) ? $request['settings'] : [];
        $framesPerSecond = $settings['frames_per_second'] ?? null;
        if (! $shotPlan['valid'] || ! is_array($shotPlan['shot_plan'])) {
            $errors[] = 'The immutable render request has no valid shot plan.';
        }
        if (! is_int($framesPerSecond) || $framesPerSecond < 1) {
            $errors[] = 'The immutable render request has no positive integer frame rate.';
        }
        if ($errors !== []) {
            throw new RuntimeException('Completed render recovery failed: '.implode(' ', array_values(array_unique($errors))));
        }

        $renderRoot = $this->paths->runPath($project, $run).'/renders/'.$renderId;
        $runRoot = realpath($this->paths->runPath($project, $run));
        $resolvedRoot = realpath($renderRoot);
        if ($runRoot === false
            || $resolvedRoot === false
            || ! str_starts_with($resolvedRoot, $runRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Completed render recovery failed: the render directory is missing.');
        }

        $files = [
            'final' => ['path' => 'final_path', 'hash' => 'final_sha256', 'bytes' => 'final_bytes', 'filename' => 'final.mp4'],
            'audio' => ['path' => 'audio_path', 'hash' => 'audio_sha256', 'bytes' => 'audio_bytes', 'filename' => 'timing-reference.wav'],
            'captions' => ['path' => 'captions_path', 'hash' => 'captions_sha256', 'bytes' => null, 'filename' => 'captions.srt'],
            'voice script' => ['path' => 'voice_script_path', 'hash' => 'voice_script_sha256', 'bytes' => null, 'filename' => 'voice-script.md'],
        ];
        $resolved = [];
        foreach ($files as $label => $spec) {
            $relative = $result[$spec['path']] ?? null;
            $expectedRelative = "renders/{$renderId}/{$spec['filename']}";
            if (! is_string($relative)
                || $relative !== $expectedRelative
                || ! $this->mediaAssets->isSafeRelativePath($relative)) {
                throw new RuntimeException("Completed render recovery failed: the {$label} path is invalid.");
            }

            $path = $this->paths->runPath($project, $run).'/'.$relative;
            $real = realpath($path);
            if ($real === false
                || ! File::isFile($real)
                || is_link($path)
                || ! str_starts_with($real, $resolvedRoot.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Completed render recovery failed: the {$label} output is missing or unsafe.");
            }

            $expectedHash = $result[$spec['hash']] ?? null;
            $actualHash = hash_file('sha256', $real);
            if (! is_string($expectedHash)
                || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
                || ! is_string($actualHash)
                || ! hash_equals($expectedHash, $actualHash)) {
                throw new RuntimeException("Completed render recovery failed: the {$label} output hash is invalid.");
            }

            if (is_string($spec['bytes'])) {
                $expectedBytes = $result[$spec['bytes']] ?? null;
                if (! is_int($expectedBytes) || $expectedBytes < 1 || File::size($real) !== $expectedBytes) {
                    throw new RuntimeException("Completed render recovery failed: the {$label} output size is invalid.");
                }
            }
            $resolved[$label] = $real;
        }

        $finalProbe = $this->probe->probe($resolved['final'], 'video');
        $audioProbe = $this->probe->probe($resolved['audio'], 'audio');
        $this->assertRecordedProbe($result['media_probe'] ?? null, $finalProbe, 'final video');
        $this->assertRecordedProbe($result['audio_probe'] ?? null, $audioProbe, 'timing audio');
        $expectedDuration = (float) $shotPlan['shot_plan']['total_duration_seconds'];
        $this->timeline->assertOutputDuration('completed final video', $finalProbe, $expectedDuration, $framesPerSecond);
        $this->timeline->assertOutputDuration('completed timing audio', $audioProbe, $expectedDuration, $framesPerSecond);
    }

    /**
     * @param  array<string, int|float|string|null>  $actual
     */
    private function assertRecordedProbe(mixed $recorded, array $actual, string $label): void
    {
        if (! is_array($recorded) || array_is_list($recorded)) {
            throw new RuntimeException("Completed render recovery failed: the {$label} probe is missing.");
        }

        foreach (['media_type', 'mime_type', 'codec', 'width', 'height', 'duration_seconds', 'audio_codec'] as $field) {
            if (! array_key_exists($field, $recorded) || $recorded[$field] !== ($actual[$field] ?? null)) {
                throw new RuntimeException("Completed render recovery failed: the {$label} probe field {$field} is invalid.");
            }
        }
        if (array_key_exists('stream_duration_seconds', $recorded)
            && $recorded['stream_duration_seconds'] !== ($actual['stream_duration_seconds'] ?? null)) {
            throw new RuntimeException("Completed render recovery failed: the {$label} stream duration probe is invalid.");
        }
    }

    private function timestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function stringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                return false;
            }
        }

        return count($value) === count(array_unique($value));
    }
}
