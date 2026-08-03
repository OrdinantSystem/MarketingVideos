<?php

namespace App\Creative\Rendering;

use App\Creative\CreativePathResolver;
use App\Creative\CreativeWorkspaceRepository;
use App\Creative\MediaAssetContract;
use RuntimeException;

final class RenderInputResolver
{
    public function __construct(
        private readonly CreativeWorkspaceRepository $workspace,
        private readonly CreativePathResolver $paths,
        private readonly MediaAssetContract $mediaAssets,
    ) {}

    /**
     * @return array{
     *     sequence: array<string, mixed>,
     *     sequence_selection: array<string, mixed>,
     *     shot_plan: array<string, mixed>,
     *     scenes: list<array{artifact: array<string, mixed>, selection: array<string, mixed>, asset: string}>
     * }
     */
    public function resolve(string $project, string $run): array
    {
        $sequenceWorkspace = $this->workspace->workspace($project, $run, 'sequence');
        if (($sequenceWorkspace['phase']['complete'] ?? false) !== true) {
            throw new RuntimeException('A valid selected sequence is required before rendering.');
        }

        $sequences = array_values(array_filter(
            is_array($sequenceWorkspace['artifacts'] ?? null) ? $sequenceWorkspace['artifacts'] : [],
            fn (mixed $artifact): bool => is_array($artifact) && ($artifact['selected'] ?? false) === true,
        ));
        if (count($sequences) !== 1) {
            throw new RuntimeException('Rendering requires exactly one effective sequence selection.');
        }

        $sequence = $sequences[0];
        $sequenceMetadata = is_array($sequence['metadata'] ?? null) ? $sequence['metadata'] : [];
        $shotPlan = $sequenceMetadata['shot_plan'] ?? null;
        $expectedSceneIds = $sequenceMetadata['scene_artifact_ids'] ?? null;
        if (! is_array($shotPlan)
            || ! is_array($shotPlan['shots'] ?? null)
            || ! is_array($expectedSceneIds)
            || ! array_is_list($expectedSceneIds)) {
            throw new RuntimeException('The selected sequence has no complete render manifest input.');
        }

        $sceneWorkspace = $this->workspace->workspace($project, $run, 'scenes');
        if (($sceneWorkspace['phase']['complete'] ?? false) !== true) {
            throw new RuntimeException('Every script-defined scene must have a valid effective selection before rendering.');
        }

        $selectedScenes = array_values(array_filter(
            is_array($sceneWorkspace['artifacts'] ?? null) ? $sceneWorkspace['artifacts'] : [],
            fn (mixed $artifact): bool => is_array($artifact) && ($artifact['selected'] ?? false) === true,
        ));
        usort($selectedScenes, static function (array $left, array $right): int {
            $leftShot = is_array($left['metadata']['shot'] ?? null) ? $left['metadata']['shot'] : [];
            $rightShot = is_array($right['metadata']['shot'] ?? null) ? $right['metadata']['shot'] : [];

            return ((int) ($leftShot['order'] ?? 0)) <=> ((int) ($rightShot['order'] ?? 0));
        });
        $actualSceneIds = array_map(fn (array $artifact): string => (string) ($artifact['id'] ?? ''), $selectedScenes);
        if ($actualSceneIds !== $expectedSceneIds) {
            throw new RuntimeException('The selected sequence is stale because its scene identities no longer match the effective scene selections.');
        }

        $runRoot = realpath($this->paths->runPath($project, $run));
        if ($runRoot === false) {
            throw new RuntimeException('The creative run root cannot be resolved for rendering.');
        }

        $resolvedScenes = [];
        foreach ($selectedScenes as $scene) {
            $metadata = is_array($scene['metadata'] ?? null) ? $scene['metadata'] : [];
            $errors = $this->mediaAssets->validate($metadata);
            if ($errors !== [] || ($metadata['asset_status'] ?? null) !== MediaAssetContract::STATUS_READY) {
                throw new RuntimeException('A selected scene is not render-ready: '.implode(' ', $errors));
            }

            $assetPath = is_string($metadata['asset_path'] ?? null) ? $metadata['asset_path'] : '';
            if (! $this->mediaAssets->isSafeRelativePath($assetPath)) {
                throw new RuntimeException('A selected scene has an unsafe asset path.');
            }
            $asset = dirname($this->paths->outputsPath($project, $run, 'scenes')).'/'.$assetPath;
            $realAsset = realpath($asset);
            if ($realAsset === false || ! str_starts_with($realAsset, $runRoot.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('A selected scene binary is missing or outside the creative run.');
            }

            $hash = hash_file('sha256', $realAsset);
            if (! is_string($hash)
                || ! is_string($metadata['asset_sha256'] ?? null)
                || ! hash_equals($metadata['asset_sha256'], $hash)) {
                throw new RuntimeException('A selected scene binary no longer matches its sidecar hash.');
            }

            $selection = is_array($scene['selection'] ?? null) ? $scene['selection'] : [];
            $resolvedScenes[] = [
                'artifact' => $scene,
                'selection' => $selection,
                'asset' => $realAsset,
            ];
        }

        return [
            'sequence' => $sequence,
            'sequence_selection' => is_array($sequence['selection'] ?? null) ? $sequence['selection'] : [],
            'shot_plan' => $shotPlan,
            'scenes' => $resolvedScenes,
        ];
    }
}
