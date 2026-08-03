<?php

namespace Tests\Feature;

use App\Creative\CreativeWorkspaceRepository;
use App\Creative\Learning\PerformanceRecordStore;
use App\Creative\Rendering\RenderStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CreativePerformanceTest extends TestCase
{
    private string $creativeRoot;

    /** @var array{project: string, run: string, phase: string} */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-performance-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
        ]);
        $this->target = app(CreativeWorkspaceRepository::class)->createProject(
            'Performance evidence',
            'Store observed outcomes without silently claiming causation.',
            'concepts',
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_performance_records_and_reusable_rankings_are_immutable_and_source_hashed(): void
    {
        $renderId = $this->completedRender('ranked');
        $performance = app(PerformanceRecordStore::class);

        $this->post("/projects/{$this->target['project']}/runs/{$this->target['run']}/renders/{$renderId}/performance", [
            'platform' => 'Local fixture campaign',
            'metrics' => [
                'impressions' => 1000,
                'views' => 800,
                'completions' => 400,
                'clicks' => 50,
                'conversions' => 10,
                'spend_usd' => 25.0,
                'revenue_usd' => 75.0,
            ],
            'observations' => 'The caption-led fixture retained more complete views in this manual observation.',
        ])->assertRedirect()->assertSessionHas('success');
        $recordDocument = $performance->records($this->target['project'], $this->target['run'])[0];
        $record = $recordDocument['metadata'];
        $this->post("/projects/{$this->target['project']}/learning", [
            'primary_metric' => 'completion_rate',
        ])->assertRedirect()->assertSessionHas('success');
        $learningDocument = $performance->learning($this->target['project'])[0];
        $learning = $learningDocument['metadata'];

        $this->assertSame(0.5, $record['derived_metrics']['completion_rate']);
        $this->assertSame(0.05, $record['derived_metrics']['ctr']);
        $this->assertSame(3.0, $record['derived_metrics']['roas']);
        $this->assertSame([$record['id']], $learning['source_performance_ids']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $learning['source_sha256'][$record['id']]);
        $this->assertSame($renderId, $learning['ranked_results'][0]['render_id']);
        $this->assertCount(1, $performance->records($this->target['project'], $this->target['run']));
        $this->assertCount(1, $performance->learning($this->target['project']));
    }

    public function test_performance_ingestion_rejects_a_render_whose_bytes_no_longer_match(): void
    {
        $renderId = $this->completedRender('tampered');
        $renderPath = app(RenderStore::class)->path($this->target['project'], $this->target['run'], $renderId);
        File::append($renderPath.'/final.mp4', 'unexpected mutation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer matches');
        app(PerformanceRecordStore::class)->record(
            $this->target['project'],
            $this->target['run'],
            $renderId,
            'Fixture',
            ['impressions' => 10],
            '',
        );
    }

    private function completedRender(string $suffix): string
    {
        $renderId = 'render-20260728-120000-000000--'.$suffix;
        $store = app(RenderStore::class);
        $store->create($this->target['project'], $this->target['run'], $renderId, [
            'renderer' => 'test-fixture',
            'sequence_artifact_id' => 'sequence-fixture',
        ]);
        $store->begin($this->target['project'], $this->target['run'], $renderId);
        $renderPath = $store->path($this->target['project'], $this->target['run'], $renderId);
        File::put($renderPath.'/final.mp4', 'deterministic local render bytes '.$suffix);
        $hash = hash_file('sha256', $renderPath.'/final.mp4');
        if (! is_string($hash)) {
            throw new RuntimeException('Unable to hash the render test fixture.');
        }
        $store->finish(
            $this->target['project'],
            $this->target['run'],
            $renderId,
            'completed',
            [
                'final_path' => 'renders/'.$renderId.'/final.mp4',
                'final_sha256' => $hash,
                'final_bytes' => File::size($renderPath.'/final.mp4'),
            ],
            'Completed test fixture.',
        );

        return $renderId;
    }
}
