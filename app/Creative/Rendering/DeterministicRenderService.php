<?php

namespace App\Creative\Rendering;

use App\Creative\MarkdownDocument;
use App\Creative\Media\DeterministicMediaRenderer;
use App\Creative\Media\MediaProbeService;
use App\Jobs\ProcessDeterministicRender;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class DeterministicRenderService
{
    public function __construct(
        private readonly RenderInputResolver $inputs,
        private readonly RenderStore $renders,
        private readonly DeterministicMediaRenderer $mediaRenderer,
        private readonly MediaProbeService $probe,
        private readonly RenderTimelineContract $timeline,
    ) {}

    /** @return array{render_id: string, state: array<string, mixed>} */
    public function submit(string $project, string $run): array
    {
        $inputs = $this->inputs->resolve($project, $run);
        $renderId = $this->newRenderId();
        $sequence = $inputs['sequence'];
        $sequenceId = (string) ($sequence['id'] ?? '');
        $sceneIds = array_map(
            fn (array $scene): string => (string) ($scene['artifact']['id'] ?? ''),
            $inputs['scenes'],
        );
        $assetHashes = [];
        $selectionIds = [];
        foreach ($inputs['scenes'] as $scene) {
            $artifact = $scene['artifact'];
            $artifactId = (string) ($artifact['id'] ?? '');
            $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
            $assetHashes[$artifactId] = $metadata['asset_sha256'] ?? null;
            $selectionIds[] = (string) ($scene['selection']['id'] ?? '');
        }

        $this->renders->create($project, $run, $renderId, [
            'renderer' => 'deterministic-ffmpeg-v1',
            'sequence_artifact_id' => $sequenceId,
            'sequence_selection_id' => $inputs['sequence_selection']['id'] ?? null,
            'scene_artifact_ids' => $sceneIds,
            'scene_selection_ids' => $selectionIds,
            'scene_asset_sha256' => $assetHashes,
            'shot_plan' => $inputs['shot_plan'],
            'settings' => [
                'width' => (int) config('creative.media.width', 360),
                'height' => (int) config('creative.media.height', 640),
                'frames_per_second' => (int) config('creative.media.frames_per_second', 12),
                'captions' => 'embedded_mov_text_and_srt',
                'audio' => 'deterministic_timing_reference',
            ],
            'cost_usd' => 0.0,
        ]);

        if (config('creative.render.execution', 'sync') === 'queue') {
            ProcessDeterministicRender::dispatch($project, $run, $renderId)
                ->onQueue((string) config('creative.render.queue', 'creative-render'));
            $state = $this->renders->state($project, $run, $renderId);
        } else {
            $state = $this->process($project, $run, $renderId);
        }

        return ['render_id' => $renderId, 'state' => $state];
    }

    /** @return array<string, mixed> */
    public function process(string $project, string $run, string $renderId): array
    {
        $claim = $this->renders->begin($project, $run, $renderId);
        $state = $claim['state'];
        if (! $claim['claimed']) {
            return $state;
        }

        $renderRoot = $this->renders->path($project, $run, $renderId);
        $stagingRoot = $renderRoot.'/staging';

        try {
            $request = $this->renders->request($project, $run, $renderId);
            $inputs = $this->inputs->resolve($project, $run);
            $this->assertInputsUnchanged($request, $inputs);
            $settings = is_array($request['settings'] ?? null) ? $request['settings'] : [];
            $framesPerSecond = $settings['frames_per_second'] ?? null;
            if (! is_int($framesPerSecond) || $framesPerSecond < 1) {
                throw new RuntimeException('The immutable render request has no valid frame rate.');
            }
            $sceneProbes = [];
            foreach ($inputs['scenes'] as $scene) {
                $sceneProbes[] = $this->probe->probe($scene['asset'], 'video');
            }
            $timeline = $this->timeline->validateSceneTimeline(
                $inputs['shot_plan'],
                $sceneProbes,
                $framesPerSecond,
            );
            if ($this->cancelRequested($project, $run, $renderId)) {
                return $this->renders->finish(
                    $project,
                    $run,
                    $renderId,
                    'cancelled',
                    [],
                    'Cancellation was honored before deterministic assembly began.',
                );
            }

            File::ensureDirectoryExists($stagingRoot);
            $captions = $this->captions($inputs['shot_plan']);
            $stagedCaptions = $stagingRoot.'/captions.srt';
            $stagedVoiceScript = $stagingRoot.'/voice-script.md';
            $this->writeImmutable($stagedCaptions, $captions);
            $this->writeVoiceScript($stagedVoiceScript, $renderId, $inputs['shot_plan']);

            $concatPath = $stagingRoot.'/concat.txt';
            $concat = collect($inputs['scenes'])->map(
                fn (array $scene): string => "file '".$this->concatEscape($scene['asset'])."'",
            )->implode("\n")."\n";
            if (file_put_contents($concatPath, $concat, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the local render concat manifest.');
            }

            $silentPath = $stagingRoot.'/silent.mp4';
            $this->runProcess([
                (string) config('creative.media.ffmpeg_binary', 'ffmpeg'),
                '-hide_banner',
                '-loglevel',
                'error',
                '-y',
                '-f',
                'concat',
                '-safe',
                '0',
                '-i',
                $concatPath,
                '-an',
                '-vf',
                sprintf(
                    'fps=%d,tpad=stop_mode=clone:stop_duration=%.6F,trim=duration=%.6F,setpts=PTS-STARTPTS,format=yuv420p',
                    $framesPerSecond,
                    $timeline['tolerance_seconds'],
                    $timeline['expected_duration_seconds'],
                ),
                '-c:v',
                'libx264',
                '-preset',
                'ultrafast',
                '-crf',
                '26',
                '-threads',
                '1',
                '-map_metadata',
                '-1',
                $silentPath,
            ], 'The local scene concat failed.');

            $duration = $timeline['expected_duration_seconds'];
            if ($duration <= 0) {
                throw new RuntimeException('The selected shot plan has no positive total duration.');
            }
            $silentProbe = $this->probe->probe($silentPath, 'video');
            $silentDuration = $this->timeline->assertOutputDuration(
                'normalized silent video',
                $silentProbe,
                $duration,
                $framesPerSecond,
            );
            $stagedAudio = $stagingRoot.'/timing-reference.wav';
            $this->mediaRenderer->render([
                'media_type' => 'audio',
                'parameters' => [
                    'duration_seconds' => $duration,
                    'frequency' => 220,
                ],
            ], $stagedAudio);
            $audioProbe = $this->probe->probe($stagedAudio, 'audio');
            $audioDuration = $this->timeline->assertOutputDuration(
                'timing-reference audio',
                $audioProbe,
                $duration,
                $framesPerSecond,
            );

            $stagedFinal = $stagingRoot.'/final.mp4';
            $this->runProcess([
                (string) config('creative.media.ffmpeg_binary', 'ffmpeg'),
                '-hide_banner',
                '-loglevel',
                'error',
                '-y',
                '-i',
                $silentPath,
                '-i',
                $stagedAudio,
                '-i',
                $stagedCaptions,
                '-map',
                '0:v:0',
                '-map',
                '1:a:0',
                '-map',
                '2:0',
                '-c:v',
                'copy',
                '-c:a',
                'aac',
                '-b:a',
                '96k',
                '-c:s',
                'mov_text',
                '-metadata:s:s:0',
                'language=eng',
                '-map_metadata',
                '-1',
                '-movflags',
                '+faststart',
                '-t',
                sprintf('%.6F', $duration),
                $stagedFinal,
            ], 'The local audio and caption mux failed.');

            $finalProbe = $this->probe->probe($stagedFinal, 'video');
            $finalDuration = $this->timeline->assertOutputDuration(
                'final aggregate video',
                $finalProbe,
                $duration,
                $framesPerSecond,
            );
            $timeline['silent_video_duration_seconds'] = $silentDuration;
            $timeline['audio_duration_seconds'] = $audioDuration;
            $timeline['final_video_duration_seconds'] = $finalDuration;
            $finalPath = $renderRoot.'/final.mp4';
            $audioPath = $renderRoot.'/timing-reference.wav';
            $captionsPath = $renderRoot.'/captions.srt';
            $voiceScriptPath = $renderRoot.'/voice-script.md';
            $captionsHash = hash_file('sha256', $stagedCaptions);
            $voiceScriptHash = hash_file('sha256', $stagedVoiceScript);
            $finalHash = $finalProbe['asset_sha256'] ?? null;
            $audioHash = $audioProbe['asset_sha256'] ?? null;
            if (! is_string($captionsHash)
                || ! is_string($voiceScriptHash)
                || ! is_string($finalHash)
                || ! is_string($audioHash)) {
                throw new RuntimeException('The staged render outputs could not be hashed.');
            }
            $promotions = [
                ['source' => $stagedFinal, 'target' => $finalPath, 'sha256' => $finalHash],
                ['source' => $stagedAudio, 'target' => $audioPath, 'sha256' => $audioHash],
                ['source' => $stagedCaptions, 'target' => $captionsPath, 'sha256' => $captionsHash],
                ['source' => $stagedVoiceScript, 'target' => $voiceScriptPath, 'sha256' => $voiceScriptHash],
            ];
            $state = $this->renders->commitCompletion(
                $project,
                $run,
                $renderId,
                function () use ($promotions, $request, $renderId, $finalProbe, $audioProbe, $captionsHash, $voiceScriptHash, $finalHash, $audioHash, $timeline): array {
                    foreach ($promotions as $promotion) {
                        if (! is_file($promotion['source']) || is_link($promotion['source'])) {
                            throw new RuntimeException('A staged render output is missing or unsafe.');
                        }
                        if (File::exists($promotion['target']) || is_link($promotion['target'])) {
                            throw new RuntimeException('A deterministic render output already exists or could not be promoted.');
                        }
                    }

                    foreach ($promotions as $promotion) {
                        if (! link($promotion['source'], $promotion['target'])) {
                            throw new RuntimeException('A deterministic render output could not be promoted as one delivery.');
                        }
                        $targetHash = hash_file('sha256', $promotion['target']);
                        if (! is_string($targetHash) || ! hash_equals($promotion['sha256'], $targetHash)) {
                            throw new RuntimeException('A promoted render output does not match its staged identity.');
                        }
                    }

                    return [
                        'renderer' => 'deterministic-ffmpeg-v1',
                        'sequence_artifact_id' => $request['sequence_artifact_id'],
                        'sequence_selection_id' => $request['sequence_selection_id'] ?? null,
                        'scene_artifact_ids' => $request['scene_artifact_ids'],
                        'scene_selection_ids' => $request['scene_selection_ids'],
                        'scene_asset_sha256' => $request['scene_asset_sha256'],
                        'final_path' => 'renders/'.$renderId.'/final.mp4',
                        'final_sha256' => $finalHash,
                        'final_bytes' => $finalProbe['asset_bytes'],
                        'media_probe' => $this->withoutIdentity($finalProbe),
                        'captions_path' => 'renders/'.$renderId.'/captions.srt',
                        'captions_sha256' => $captionsHash,
                        'voice_script_path' => 'renders/'.$renderId.'/voice-script.md',
                        'voice_script_sha256' => $voiceScriptHash,
                        'audio_path' => 'renders/'.$renderId.'/timing-reference.wav',
                        'audio_sha256' => $audioHash,
                        'audio_bytes' => $audioProbe['asset_bytes'],
                        'audio_probe' => $this->withoutIdentity($audioProbe),
                        'audio_semantics' => 'timing_reference_tone_not_voice_synthesis',
                        'timeline' => $timeline,
                        'cost_usd' => 0.0,
                    ];
                },
                'Selected local scene binaries were assembled with an embedded caption track and an explicitly synthetic timing-reference tone.',
                [],
                static function () use ($promotions): void {
                    foreach (array_reverse($promotions) as $promotion) {
                        $sourceInode = is_file($promotion['source']) ? fileinode($promotion['source']) : false;
                        $targetInode = is_file($promotion['target']) ? fileinode($promotion['target']) : false;
                        if (! is_int($sourceInode) || $sourceInode !== $targetInode) {
                            continue;
                        }

                        File::delete($promotion['target']);
                    }
                },
            );
            if (($state['status'] ?? null) === 'completed') {
                File::deleteDirectory($stagingRoot);
            }

            return $state;
        } catch (ProcessTimedOutException $exception) {
            return $this->renders->finish(
                $project,
                $run,
                $renderId,
                'timed_out',
                ['error' => Str::limit($exception->getMessage(), 500)],
                'The deterministic local render exceeded its configured timeout.',
            );
        } catch (Throwable $exception) {
            return $this->renders->finish(
                $project,
                $run,
                $renderId,
                'failed',
                ['error' => Str::limit($exception->getMessage(), 500)],
                'The deterministic local render failed before a complete manifest could be committed.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function cancel(string $project, string $run, string $renderId): array
    {
        return $this->renders->cancel($project, $run, $renderId);
    }

    /** @return array<string, mixed> */
    public function terminalizeWorkerFailure(
        string $project,
        string $run,
        string $renderId,
        Throwable $exception,
    ): array {
        $state = $this->renders->state($project, $run, $renderId);
        if (in_array($state['status'] ?? null, ['completed', 'failed', 'cancelled', 'timed_out'], true)) {
            return $state;
        }

        $failure = Str::lower($exception::class.' '.$exception->getMessage());
        $status = str_contains($failure, 'timeout') || str_contains($failure, 'timed out')
            ? 'timed_out'
            : 'failed';

        return $this->renders->finish(
            $project,
            $run,
            $renderId,
            $status,
            [
                'error' => Str::limit($exception->getMessage(), 500),
                'worker_failure' => true,
            ],
            'The queue worker terminalized an interrupted deterministic render.',
        );
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $inputs
     */
    private function assertInputsUnchanged(array $request, array $inputs): void
    {
        $sequenceId = (string) ($inputs['sequence']['id'] ?? '');
        $sequenceSelectionId = (string) ($inputs['sequence_selection']['id'] ?? '');
        $sceneIds = array_map(
            fn (array $scene): string => (string) ($scene['artifact']['id'] ?? ''),
            $inputs['scenes'],
        );
        $sceneSelectionIds = array_map(
            fn (array $scene): string => (string) ($scene['selection']['id'] ?? ''),
            $inputs['scenes'],
        );
        $sceneHashes = [];
        foreach ($inputs['scenes'] as $scene) {
            $artifact = $scene['artifact'];
            $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
            $sceneHashes[(string) ($artifact['id'] ?? '')] = $metadata['asset_sha256'] ?? null;
        }

        if (($request['sequence_artifact_id'] ?? null) !== $sequenceId
            || ($request['sequence_selection_id'] ?? null) !== $sequenceSelectionId
            || ($request['scene_artifact_ids'] ?? null) !== $sceneIds
            || ($request['scene_selection_ids'] ?? null) !== $sceneSelectionIds
            || ($request['scene_asset_sha256'] ?? null) !== $sceneHashes) {
            throw new RuntimeException('Effective selections or binary identities changed after the render request was reserved.');
        }
    }

    /** @param array<string, mixed> $shotPlan */
    private function captions(array $shotPlan): string
    {
        $blocks = [];
        $shots = is_array($shotPlan['shots'] ?? null) ? $shotPlan['shots'] : [];
        foreach ($shots as $index => $shot) {
            if (! is_array($shot)) {
                continue;
            }

            $purpose = is_string($shot['purpose'] ?? null) ? $shot['purpose'] : 'Creative beat';
            $blocks[] = ($index + 1)."\n"
                .$this->srtTime((float) ($shot['start_seconds'] ?? 0)).' --> '.$this->srtTime((float) ($shot['end_seconds'] ?? 0))."\n"
                .$this->plainText($purpose)."\n";
        }

        if ($blocks === []) {
            throw new RuntimeException('The selected shot plan produced no captions.');
        }

        return implode("\n", $blocks)."\n";
    }

    /** @param array<string, mixed> $shotPlan */
    private function writeVoiceScript(string $path, string $renderId, array $shotPlan): void
    {
        $lines = [];
        $shots = is_array($shotPlan['shots'] ?? null) ? $shotPlan['shots'] : [];
        foreach ($shots as $shot) {
            if (! is_array($shot)) {
                continue;
            }
            $lines[] = '- '.(string) ($shot['start_seconds'] ?? 0).'–'.(string) ($shot['end_seconds'] ?? 0).'s · **'
                .$this->plainText((string) ($shot['purpose'] ?? 'Beat')).'** · '
                .$this->plainText((string) ($shot['visual'] ?? ''));
        }

        MarkdownDocument::write($path, [
            'schema_version' => 1,
            'id' => $renderId.'--voice-script',
            'type' => 'voice_script',
            'render_id' => $renderId,
            'status' => 'simulation_timing_only',
            'voice_generated' => false,
            'created_at' => now('UTC')->format('Y-m-d\TH:i:s.uP'),
        ], "# Voice timing script\n\n".implode("\n", $lines)."\n\n## Simulation boundary\n\nNo speech was synthesized. The WAV is a quiet timing-reference tone only.", false);
    }

    /** @param list<string> $command */
    private function runProcess(array $command, string $error): void
    {
        $process = new Process($command);
        $process->setTimeout(max(1, (int) config('creative.render.timeout_seconds', 90)));
        $process->mustRun();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($error);
        }
    }

    private function writeImmutable(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        $temporary = tempnam(dirname($path), '.videoauto-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to reserve an immutable render text file.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! link($temporary, $path)) {
                throw new RuntimeException('Unable to commit an immutable render text file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @param  array<string, int|float|string|null>  $probe
     * @return array<string, int|float|string|null>
     */
    private function withoutIdentity(array $probe): array
    {
        unset($probe['asset_sha256'], $probe['asset_bytes']);

        return $probe;
    }

    private function cancelRequested(string $project, string $run, string $renderId): bool
    {
        return ($this->renders->state($project, $run, $renderId)['cancel_requested'] ?? false) === true;
    }

    private function srtTime(float $seconds): string
    {
        $milliseconds = max(0, (int) round($seconds * 1000));
        $hours = intdiv($milliseconds, 3_600_000);
        $milliseconds %= 3_600_000;
        $minutes = intdiv($milliseconds, 60_000);
        $milliseconds %= 60_000;
        $wholeSeconds = intdiv($milliseconds, 1000);
        $milliseconds %= 1000;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $wholeSeconds, $milliseconds);
    }

    private function plainText(string $value): string
    {
        $plain = preg_replace('/\s+/', ' ', strip_tags($value));

        return trim(is_string($plain) ? $plain : '');
    }

    private function concatEscape(string $path): string
    {
        return str_replace("'", "'\\''", $path);
    }

    private function newRenderId(): string
    {
        return 'render-'.now('UTC')->format('Ymd-His-u').'--'.Str::lower(Str::random(8));
    }
}
