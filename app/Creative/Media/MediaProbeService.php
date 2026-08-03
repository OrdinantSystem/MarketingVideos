<?php

namespace App\Creative\Media;

use RuntimeException;
use Symfony\Component\Process\Process;

final class MediaProbeService
{
    /**
     * @return array<string, int|float|string|null>
     */
    public function probe(string $path, string $expectedMediaType): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The media probe target does not exist.');
        }

        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_int($bytes) || $bytes < 1 || ! is_string($sha256) || ! is_string($mimeType)) {
            throw new RuntimeException('Unable to read the generated media identity.');
        }

        if ($expectedMediaType === 'image') {
            $size = getimagesize($path);
            if (! is_array($size)) {
                throw new RuntimeException('The generated image cannot be decoded.');
            }

            $extension = image_type_to_extension((int) $size[2], false);
            if (! is_string($extension)) {
                throw new RuntimeException('The generated image codec cannot be identified.');
            }
            $codec = ltrim($extension, '.');

            return [
                'media_type' => 'image',
                'mime_type' => $mimeType,
                'codec' => $codec,
                'width' => (int) $size[0],
                'height' => (int) $size[1],
                'duration_seconds' => null,
                'stream_duration_seconds' => null,
                'audio_codec' => null,
                'asset_bytes' => $bytes,
                'asset_sha256' => $sha256,
            ];
        }

        if (! in_array($expectedMediaType, ['video', 'audio'], true)) {
            throw new RuntimeException('The requested media probe type is unsupported.');
        }

        $process = new Process([
            (string) config('creative.media.ffprobe_binary', 'ffprobe'),
            '-v',
            'error',
            '-show_entries',
            'format=duration,format_name:stream=codec_name,codec_type,width,height,duration,sample_rate,channels',
            '-of',
            'json',
            $path,
        ]);
        $process->setTimeout(max(1, (int) config('creative.media.timeout_seconds', 30)));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffprobe could not validate the generated media.');
        }

        $decoded = json_decode($process->getOutput(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('ffprobe returned malformed media metadata.');
        }

        $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
        $video = collect($streams)->first(fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'video');
        $audio = collect($streams)->first(fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'audio');
        $duration = $decoded['format']['duration'] ?? null;
        $duration = is_numeric($duration) ? (float) $duration : null;

        if ($expectedMediaType === 'video') {
            if (! is_array($video)
                || ! is_string($video['codec_name'] ?? null)
                || ! is_int($video['width'] ?? null)
                || ! is_int($video['height'] ?? null)
                || $duration === null
                || $duration <= 0) {
                throw new RuntimeException('The generated video is missing required stream metadata.');
            }

            return [
                'media_type' => 'video',
                'mime_type' => $mimeType,
                'codec' => $video['codec_name'],
                'width' => $video['width'],
                'height' => $video['height'],
                'duration_seconds' => $duration,
                'stream_duration_seconds' => is_numeric($video['duration'] ?? null)
                    ? (float) $video['duration']
                    : $duration,
                'audio_codec' => is_array($audio) && is_string($audio['codec_name'] ?? null)
                    ? $audio['codec_name']
                    : null,
                'asset_bytes' => $bytes,
                'asset_sha256' => $sha256,
            ];
        }

        if (! is_array($audio) || ! is_string($audio['codec_name'] ?? null) || $duration === null || $duration <= 0) {
            throw new RuntimeException('The generated audio is missing required stream metadata.');
        }

        return [
            'media_type' => 'audio',
            'mime_type' => $mimeType,
            'codec' => $audio['codec_name'],
            'width' => null,
            'height' => null,
            'duration_seconds' => $duration,
            'stream_duration_seconds' => is_numeric($audio['duration'] ?? null)
                ? (float) $audio['duration']
                : $duration,
            'audio_codec' => $audio['codec_name'],
            'asset_bytes' => $bytes,
            'asset_sha256' => $sha256,
        ];
    }
}
