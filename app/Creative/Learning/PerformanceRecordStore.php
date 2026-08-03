<?php

namespace App\Creative\Learning;

use App\Creative\CreativeLockManager;
use App\Creative\CreativePathResolver;
use App\Creative\FilesystemCreativeStore;
use App\Creative\MarkdownDocument;
use App\Creative\Rendering\RenderStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PerformanceRecordStore
{
    /** @var list<string> */
    private const COUNT_METRICS = ['impressions', 'views', 'completions', 'clicks', 'conversions'];

    /** @var list<string> */
    private const MONEY_METRICS = ['spend_usd', 'revenue_usd'];

    /** @var list<string> */
    private const DERIVED_METRICS = ['view_rate', 'completion_rate', 'ctr', 'conversion_rate', 'cpm_usd', 'cpc_usd', 'roas'];

    public function __construct(
        private readonly CreativePathResolver $paths,
        private readonly CreativeLockManager $locks,
        private readonly FilesystemCreativeStore $workspace,
        private readonly RenderStore $renders,
    ) {}

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public function record(
        string $project,
        string $run,
        string $renderId,
        string $platform,
        array $metrics,
        string $observations,
        ?string $observedAt = null,
    ): array {
        $normalized = $this->normalizeMetrics($metrics);
        $manifest = $this->renders->result($project, $run, $renderId);
        if (! is_array($manifest) || ($manifest['status'] ?? null) !== 'completed') {
            throw new RuntimeException('Performance may only reference a completed deterministic render manifest.');
        }

        $finalPath = $manifest['final_path'] ?? null;
        $finalHash = $manifest['final_sha256'] ?? null;
        if (! is_string($finalPath)
            || ! is_string($finalHash)
            || preg_match('/^[a-f0-9]{64}$/', $finalHash) !== 1) {
            throw new RuntimeException('The referenced render manifest has no valid final identity.');
        }
        $runRoot = realpath($this->paths->runPath($project, $run));
        $asset = $this->paths->runPath($project, $run).'/'.ltrim($finalPath, '/');
        $realAsset = realpath($asset);
        if ($runRoot === false
            || $realAsset === false
            || ! str_starts_with($realAsset, $runRoot.DIRECTORY_SEPARATOR)
            || ! hash_equals($finalHash, (string) hash_file('sha256', $realAsset))) {
            throw new RuntimeException('The referenced final render is missing or no longer matches its manifest.');
        }

        $platform = trim($platform);
        if ($platform === '' || mb_strlen($platform) > 80) {
            throw new RuntimeException('Performance platform must be a non-empty label of at most 80 characters.');
        }
        $observations = trim($observations);
        if (mb_strlen($observations) > 5000) {
            throw new RuntimeException('Performance observations may not exceed 5000 characters.');
        }

        $recordId = 'performance-'.now('UTC')->format('Ymd-His-u').'--'.Str::lower(Str::random(8));
        $createdAt = $this->timestamp();
        $metadata = [
            'schema_version' => 1,
            'id' => $recordId,
            'type' => 'performance_record',
            'project_id' => $project,
            'run_id' => $run,
            'render_id' => $renderId,
            'render_sha256' => $finalHash,
            'platform' => $platform,
            'metrics' => $normalized,
            'derived_metrics' => $this->derivedMetrics($normalized),
            'observed_at' => $observedAt ?? $createdAt,
            'created_at' => $createdAt,
        ];
        $this->locks->exclusive("performance:{$project}:{$run}", function () use ($project, $run, $recordId, $metadata, $observations): void {
            MarkdownDocument::write(
                $this->recordsPath($project, $run).'/'.$recordId.'.md',
                $metadata,
                "# Performance observation\n\n## Notes\n\n".($observations !== '' ? $observations : 'No qualitative observation was recorded.')."\n\n## Interpretation boundary\n\nThese are observed metrics, not proof that a creative choice caused the outcome.",
                false,
            );
        });

        return $metadata;
    }

    /** @return list<array<string, mixed>> */
    public function records(string $project, string $run): array
    {
        return $this->documents($this->recordsPath($project, $run));
    }

    /** @return array<string, mixed> */
    public function summarize(string $project, string $primaryMetric = 'completion_rate'): array
    {
        if (! in_array($primaryMetric, self::DERIVED_METRICS, true)) {
            throw new RuntimeException('The learning summary primary metric is unsupported.');
        }

        $records = [];
        foreach ($this->workspace->runs($project) as $run) {
            $runId = is_string($run['id'] ?? null) ? $run['id'] : '';
            if ($runId !== '') {
                $records = array_merge($records, $this->records($project, $runId));
            }
        }
        if ($records === []) {
            throw new RuntimeException('There are no performance records to summarize.');
        }

        $ranked = array_values(array_filter(array_map(function (array $record) use ($primaryMetric): ?array {
            $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
            $derived = is_array($metadata['derived_metrics'] ?? null) ? $metadata['derived_metrics'] : [];
            $value = $derived[$primaryMetric] ?? null;
            if (! is_numeric($value)) {
                return null;
            }

            return [
                'performance_id' => $metadata['id'] ?? null,
                'run_id' => $metadata['run_id'] ?? null,
                'render_id' => $metadata['render_id'] ?? null,
                'platform' => $metadata['platform'] ?? null,
                'value' => (float) $value,
            ];
        }, $records)));
        usort($ranked, static fn (array $left, array $right): int => $right['value'] <=> $left['value']);
        if ($ranked === []) {
            throw new RuntimeException("No performance record can calculate {$primaryMetric}.");
        }

        $sourceHashes = [];
        foreach ($records as $record) {
            $path = $record['_path'] ?? null;
            $id = $record['metadata']['id'] ?? null;
            if (is_string($path) && is_string($id)) {
                $hash = hash_file('sha256', $path);
                if (is_string($hash)) {
                    $sourceHashes[$id] = $hash;
                }
            }
        }
        $learningId = 'learning-'.now('UTC')->format('Ymd-His-u').'--'.Str::lower(Str::random(8));
        $rows = collect($ranked)->map(fn (array $item, int $index): string => sprintf(
            '| %d | `%s` | %s | %s | %.4f |',
            $index + 1,
            (string) $item['render_id'],
            (string) $item['run_id'],
            (string) $item['platform'],
            $item['value'],
        ))->implode("\n");
        $metadata = [
            'schema_version' => 1,
            'id' => $learningId,
            'type' => 'performance_learning',
            'project_id' => $project,
            'primary_metric' => $primaryMetric,
            'source_performance_ids' => array_keys($sourceHashes),
            'source_sha256' => $sourceHashes,
            'ranked_results' => $ranked,
            'created_at' => $this->timestamp(),
        ];
        MarkdownDocument::write(
            $this->learningPath($project).'/'.$learningId.'.md',
            $metadata,
            "# Reusable performance evidence\n\n## Ranking by {$primaryMetric}\n\n| Rank | Render | Run | Platform | Value |\n| ---: | --- | --- | --- | ---: |\n{$rows}\n\n## Interpretation boundary\n\nThis deterministic ranking preserves observed evidence and source hashes. It does not infer causality or silently rewrite creative guidance.",
            false,
        );

        return $metadata;
    }

    /** @return list<array<string, mixed>> */
    public function learning(string $project): array
    {
        return $this->documents($this->learningPath($project));
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, int|float>
     */
    private function normalizeMetrics(array $metrics): array
    {
        $normalized = [];
        foreach (self::COUNT_METRICS as $metric) {
            if (! array_key_exists($metric, $metrics)) {
                continue;
            }
            if (! is_int($metrics[$metric]) || $metrics[$metric] < 0) {
                throw new RuntimeException("Performance metric {$metric} must be a non-negative integer.");
            }
            $normalized[$metric] = $metrics[$metric];
        }
        foreach (self::MONEY_METRICS as $metric) {
            if (! array_key_exists($metric, $metrics)) {
                continue;
            }
            if (! is_numeric($metrics[$metric]) || (float) $metrics[$metric] < 0) {
                throw new RuntimeException("Performance metric {$metric} must be non-negative numeric data.");
            }
            $normalized[$metric] = (float) $metrics[$metric];
        }
        $unknown = array_diff(array_keys($metrics), array_merge(self::COUNT_METRICS, self::MONEY_METRICS));
        if ($unknown !== []) {
            throw new RuntimeException('Unknown performance metrics: '.implode(', ', $unknown).'.');
        }
        if ($normalized === []) {
            throw new RuntimeException('At least one performance metric is required.');
        }
        foreach ([['views', 'impressions'], ['completions', 'views'], ['clicks', 'impressions'], ['conversions', 'clicks']] as [$part, $whole]) {
            if (isset($normalized[$part], $normalized[$whole]) && $normalized[$part] > $normalized[$whole]) {
                throw new RuntimeException("Performance metric {$part} cannot exceed {$whole}.");
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, int|float>  $metrics
     * @return array<string, float|null>
     */
    private function derivedMetrics(array $metrics): array
    {
        return [
            'view_rate' => $this->ratio($metrics['views'] ?? null, $metrics['impressions'] ?? null),
            'completion_rate' => $this->ratio($metrics['completions'] ?? null, $metrics['views'] ?? null),
            'ctr' => $this->ratio($metrics['clicks'] ?? null, $metrics['impressions'] ?? null),
            'conversion_rate' => $this->ratio($metrics['conversions'] ?? null, $metrics['clicks'] ?? null),
            'cpm_usd' => $this->ratio($metrics['spend_usd'] ?? null, $metrics['impressions'] ?? null, 1000),
            'cpc_usd' => $this->ratio($metrics['spend_usd'] ?? null, $metrics['clicks'] ?? null),
            'roas' => $this->ratio($metrics['revenue_usd'] ?? null, $metrics['spend_usd'] ?? null),
        ];
    }

    private function ratio(int|float|null $part, int|float|null $whole, int $scale = 1): ?float
    {
        if ($part === null || $whole === null || $whole <= 0) {
            return null;
        }

        return ($part / $whole) * $scale;
    }

    /** @return list<array<string, mixed>> */
    private function documents(string $directory): array
    {
        if (! File::isDirectory($directory)) {
            return [];
        }

        return array_values(collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(function (\SplFileInfo $file): array {
                try {
                    $document = MarkdownDocument::read($file->getPathname());

                    return $document + ['_path' => $file->getPathname()];
                } catch (Throwable $exception) {
                    return [
                        'metadata' => [],
                        'body' => '',
                        'sections' => [],
                        '_path' => $file->getPathname(),
                        'parse_error' => $exception->getMessage(),
                    ];
                }
            })->sortByDesc(fn (array $record): string => (string) ($record['metadata']['created_at'] ?? ''))->values()->all());
    }

    private function recordsPath(string $project, string $run): string
    {
        return $this->paths->runPath($project, $run).'/performance/records';
    }

    private function learningPath(string $project): string
    {
        return $this->paths->projectPath($project).'/learning';
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.uP');
    }
}
