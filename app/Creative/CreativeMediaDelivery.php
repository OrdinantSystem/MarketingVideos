<?php

namespace App\Creative;

use App\Creative\Rendering\RenderStore;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CreativeMediaDelivery
{
    /** @var array<string, list<string>> */
    private const ALLOWED_MIME_TYPES = [
        'image' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime'],
        'audio' => ['audio/wav', 'audio/x-wav', 'audio/mpeg', 'audio/mp4', 'audio/ogg'],
    ];

    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly FilesystemCreativeStore $store,
        private readonly CreativeArtifactEligibilityVerifier $eligibility,
        private readonly MediaAssetContract $mediaAssets,
        private readonly RenderStore $renders,
    ) {}

    /**
     * @return array{path: string, mime_type: string, filename: string, sha256: string}
     */
    public function artifact(string $project, string $run, string $phase, string $artifactId): array
    {
        if (! in_array($phase, ['images', 'scenes'], true)) {
            throw new RuntimeException('Only ready image and scene artifacts have streamable binaries.');
        }

        $matches = array_values(array_filter(
            $this->store->artifacts($project, $run, $phase),
            static fn (array $artifact): bool => ($artifact['id'] ?? null) === $artifactId,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('The requested media artifact does not resolve uniquely.');
        }

        $artifact = $matches[0];
        $metadata = is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [];
        if (($metadata['asset_status'] ?? null) !== MediaAssetContract::STATUS_READY) {
            throw new RuntimeException('The requested artifact does not have a ready binary.');
        }

        $eligibility = $this->eligibility->verify($project, $run, $phase, $artifact);
        if (! $eligibility['eligible']) {
            throw new RuntimeException('The requested artifact binary failed its integrity contract.');
        }

        $assetPath = is_string($metadata['asset_path'] ?? null) ? $metadata['asset_path'] : '';
        if (! $this->mediaAssets->isSafeRelativePath($assetPath)) {
            throw new RuntimeException('The requested artifact has an unsafe media path.');
        }

        $runRoot = $this->resolvedDirectory($this->paths->runPath($project, $run), 'creative run');
        $outputs = $this->paths->outputsPath($project, $run, $phase);
        $candidates = array_unique([
            $outputs.'/'.$assetPath,
            dirname($outputs).'/'.$assetPath,
        ]);
        $resolved = [];
        foreach ($candidates as $candidate) {
            if (! File::isFile($candidate) || is_link($candidate)) {
                continue;
            }

            $real = realpath($candidate);
            if ($real !== false && $this->isWithin($real, $runRoot)) {
                $resolved[] = $real;
            }
        }
        $resolved = array_values(array_unique($resolved));
        if (count($resolved) !== 1) {
            throw new RuntimeException('The requested artifact binary is missing or ambiguous.');
        }

        $probe = is_array($metadata['media_probe'] ?? null) ? $metadata['media_probe'] : [];
        $mediaType = $metadata['artifact_type'] === 'image_sidecar' ? 'image' : 'video';
        $mimeType = $this->verifiedMimeType($resolved[0], $probe['mime_type'] ?? null, $mediaType);
        $sha256 = is_string($metadata['asset_sha256'] ?? null) ? $metadata['asset_sha256'] : '';

        return [
            'path' => $resolved[0],
            'mime_type' => $mimeType,
            'filename' => basename($resolved[0]),
            'sha256' => $sha256,
        ];
    }

    /**
     * @return array{path: string, mime_type: string, filename: string, sha256: string}
     */
    public function renderAsset(string $project, string $run, string $renderId, string $asset): array
    {
        if (! in_array($asset, ['final', 'audio'], true)) {
            throw new RuntimeException('The requested render asset is unsupported.');
        }

        $request = $this->renders->request($project, $run, $renderId);
        $state = $this->renders->state($project, $run, $renderId);
        $result = $this->renders->result($project, $run, $renderId);
        if (($request['schema_version'] ?? null) !== 1
            || ($request['type'] ?? null) !== 'render_request'
            || ($request['render_id'] ?? null) !== $renderId
            || ($request['project_id'] ?? null) !== $project
            || ($request['run_id'] ?? null) !== $run
            || ($state['schema_version'] ?? null) !== 1
            || ($state['type'] ?? null) !== 'render_state'
            || ($state['render_id'] ?? null) !== $renderId
            || ($state['status'] ?? null) !== 'completed'
            || ! is_array($result)
            || ($result['schema_version'] ?? null) !== 1
            || ($result['type'] ?? null) !== 'render_result'
            || ($result['status'] ?? null) !== 'completed'
            || ($result['render_id'] ?? null) !== $renderId) {
            throw new RuntimeException('The requested render has no completed immutable delivery.');
        }

        $prefix = $asset === 'final' ? 'final' : 'audio';
        $pathKey = $prefix.'_path';
        $hashKey = $prefix.'_sha256';
        $bytesKey = $prefix.'_bytes';
        $probeKey = $asset === 'final' ? 'media_probe' : 'audio_probe';
        $relative = is_string($result[$pathKey] ?? null) ? $result[$pathKey] : '';
        $expectedPrefix = "renders/{$renderId}/";
        if (! $this->mediaAssets->isSafeRelativePath($relative)
            || ! str_starts_with($relative, $expectedPrefix)) {
            throw new RuntimeException('The completed render declares an unsafe delivery path.');
        }

        $runRoot = $this->resolvedDirectory($this->paths->runPath($project, $run), 'creative run');
        $renderRoot = $this->resolvedDirectory($this->renders->path($project, $run, $renderId), 'render');
        if (! $this->isWithin($renderRoot, $runRoot)) {
            throw new RuntimeException('The completed render directory escapes its creative run.');
        }

        $candidate = $runRoot.'/'.$relative;
        if (! File::isFile($candidate) || is_link($candidate)) {
            throw new RuntimeException('The completed render delivery is missing or unsafe.');
        }
        $real = realpath($candidate);
        if ($real === false || ! $this->isWithin($real, $renderRoot)) {
            throw new RuntimeException('The completed render delivery escapes its immutable render directory.');
        }

        $expectedHash = is_string($result[$hashKey] ?? null) ? $result[$hashKey] : '';
        $actualHash = hash_file('sha256', $real);
        if ($expectedHash === ''
            || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
            || ! is_string($actualHash)
            || ! hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('The completed render delivery no longer matches its SHA-256.');
        }

        $expectedBytes = $result[$bytesKey] ?? null;
        if (! is_int($expectedBytes) || $expectedBytes < 1 || File::size($real) !== $expectedBytes) {
            throw new RuntimeException('The completed render delivery no longer matches its byte count.');
        }

        $probe = is_array($result[$probeKey] ?? null) ? $result[$probeKey] : [];
        $mediaType = $asset === 'final' ? 'video' : 'audio';

        return [
            'path' => $real,
            'mime_type' => $this->verifiedMimeType($real, $probe['mime_type'] ?? null, $mediaType),
            'filename' => basename($real),
            'sha256' => $expectedHash,
        ];
    }

    public function artifactUrl(string $project, string $run, string $phase, string $artifactId): ?string
    {
        try {
            $this->artifact($project, $run, $phase, $artifactId);

            return route('creative.artifacts.media', compact('project', 'run', 'phase') + [
                'artifact' => $artifactId,
            ], false);
        } catch (Throwable) {
            return null;
        }
    }

    public function renderAssetUrl(
        string $project,
        string $run,
        string $renderId,
        string $asset,
    ): ?string {
        try {
            $this->renderAsset($project, $run, $renderId, $asset);

            return route('creative.renders.media', compact('project', 'run') + [
                'render' => $renderId,
                'asset' => $asset,
            ], false);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolvedDirectory(string $path, string $label): string
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("The {$label} directory cannot be resolved.");
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function isWithin(string $path, string $directory): bool
    {
        return str_starts_with($path, $directory.DIRECTORY_SEPARATOR);
    }

    private function validatedMimeType(mixed $mimeType, string $mediaType): string
    {
        if (! is_string($mimeType)
            || ! in_array($mimeType, self::ALLOWED_MIME_TYPES[$mediaType] ?? [], true)) {
            throw new RuntimeException('The media MIME type is missing or unsupported.');
        }

        return $mimeType;
    }

    private function verifiedMimeType(string $path, mixed $declaredMimeType, string $mediaType): string
    {
        $declared = $this->validatedMimeType($declaredMimeType, $mediaType);
        $actual = $this->validatedMimeType(File::mimeType($path), $mediaType);
        $equivalentWaveTypes = ['audio/wav', 'audio/x-wav'];

        if ($declared !== $actual
            && ! ($mediaType === 'audio'
                && in_array($declared, $equivalentWaveTypes, true)
                && in_array($actual, $equivalentWaveTypes, true))) {
            throw new RuntimeException('The verified media MIME type does not match its immutable metadata.');
        }

        return $actual;
    }
}
