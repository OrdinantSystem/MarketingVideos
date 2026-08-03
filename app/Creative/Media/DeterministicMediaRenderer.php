<?php

namespace App\Creative\Media;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

final class DeterministicMediaRenderer
{
    /** @param array<string, mixed> $request */
    public function render(array $request, string $target): void
    {
        $parameters = is_array($request['parameters'] ?? null) ? $request['parameters'] : [];
        $outcome = is_string($parameters['fixture_outcome'] ?? null)
            ? $parameters['fixture_outcome']
            : 'completed';
        if ($outcome === 'failed') {
            throw new RuntimeException('The deterministic media fixture was instructed to fail.');
        }
        if ($outcome === 'timed_out') {
            throw new MediaJobTimedOut('The deterministic media fixture exceeded its configured deadline.');
        }

        File::ensureDirectoryExists(dirname($target));
        match ($request['media_type'] ?? null) {
            'image' => $this->renderImage($target, $parameters),
            'video' => $this->renderVideo($target, $parameters),
            'audio' => $this->renderAudio($target, $parameters),
            default => throw new RuntimeException('The deterministic media fixture type is unsupported.'),
        };
    }

    /** @param array<string, mixed> $parameters */
    private function renderImage(string $target, array $parameters): void
    {
        $width = max(16, (int) config('creative.media.width', 360));
        $height = max(16, (int) config('creative.media.height', 640));
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('Unable to allocate a deterministic image fixture.');
        }

        try {
            [$red, $green, $blue] = $this->rgb($parameters['color'] ?? null);
            $background = $this->allocateColor($image, $red, $green, $blue);
            $accent = $this->allocateColor(
                $image,
                min(255, $red + 35),
                min(255, $green + 35),
                min(255, $blue + 35),
            );
            imagefill($image, 0, 0, $background);
            imagefilledrectangle($image, 0, (int) ($height * 0.68), $width, $height, $accent);
            imagefilledellipse($image, (int) ($width * 0.5), (int) ($height * 0.35), (int) ($width * 0.58), (int) ($width * 0.58), $accent);
            $label = $this->label($parameters['label'] ?? null);
            imagestring($image, 5, 16, $height - 36, $label, $this->allocateColor($image, 255, 255, 255));

            if (! imagepng($image, $target, 6)) {
                throw new RuntimeException('Unable to write the deterministic PNG fixture.');
            }
        } finally {
            imagedestroy($image);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function renderVideo(string $target, array $parameters): void
    {
        $width = max(16, (int) config('creative.media.width', 360));
        $height = max(16, (int) config('creative.media.height', 640));
        $fps = max(1, (int) config('creative.media.frames_per_second', 12));
        $duration = is_numeric($parameters['duration_seconds'] ?? null)
            ? max(0.25, min(15.0, (float) $parameters['duration_seconds']))
            : 1.0;
        [$red, $green, $blue] = $this->rgb($parameters['color'] ?? null);
        $color = sprintf('0x%02x%02x%02x', $red, $green, $blue);

        $process = new Process([
            (string) config('creative.media.ffmpeg_binary', 'ffmpeg'),
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-f',
            'lavfi',
            '-i',
            "color=c={$color}:s={$width}x{$height}:r={$fps}:d={$duration}",
            '-vf',
            'format=yuv420p,fade=t=in:st=0:d=0.15',
            '-an',
            '-c:v',
            'libx264',
            '-preset',
            'ultrafast',
            '-crf',
            '28',
            '-threads',
            '1',
            '-map_metadata',
            '-1',
            '-movflags',
            '+faststart',
            $target,
        ]);
        $process->setTimeout(max(1, (int) config('creative.media.timeout_seconds', 30)));
        $process->run();
        if (! $process->isSuccessful() || ! is_file($target)) {
            throw new RuntimeException('ffmpeg could not create the deterministic MP4 fixture.');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function renderAudio(string $target, array $parameters): void
    {
        $duration = is_numeric($parameters['duration_seconds'] ?? null)
            ? max(0.25, min(15.0, (float) $parameters['duration_seconds']))
            : 1.0;
        $frequency = is_numeric($parameters['frequency'] ?? null)
            ? max(80, min(1200, (int) $parameters['frequency']))
            : 220;
        $process = new Process([
            (string) config('creative.media.ffmpeg_binary', 'ffmpeg'),
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-f',
            'lavfi',
            '-i',
            "sine=frequency={$frequency}:sample_rate=48000:duration={$duration}",
            '-filter:a',
            'volume=0.035',
            '-c:a',
            'pcm_s16le',
            '-map_metadata',
            '-1',
            $target,
        ]);
        $process->setTimeout(max(1, (int) config('creative.media.timeout_seconds', 30)));
        $process->run();
        if (! $process->isSuccessful() || ! is_file($target)) {
            throw new RuntimeException('ffmpeg could not create the deterministic audio fixture.');
        }
    }

    /** @return array{int, int, int} */
    private function rgb(mixed $color): array
    {
        $hex = is_string($color) && preg_match('/^#[a-f0-9]{6}$/i', $color) === 1
            ? substr($color, 1)
            : '557c70';

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function label(mixed $label): string
    {
        $plain = is_string($label) ? preg_replace('/[^a-z0-9 -]+/i', '', $label) : null;

        return substr(trim(is_string($plain) ? $plain : '') ?: 'VideoAuto simulation', 0, 42);
    }

    private function allocateColor(\GdImage $image, int $red, int $green, int $blue): int
    {
        $boundedRed = min(255, max(0, $red));
        $boundedGreen = min(255, max(0, $green));
        $boundedBlue = min(255, max(0, $blue));
        $color = imagecolorallocate($image, $boundedRed, $boundedGreen, $boundedBlue);
        if ($color === false) {
            throw new RuntimeException('Unable to allocate a deterministic image color.');
        }

        return $color;
    }
}
