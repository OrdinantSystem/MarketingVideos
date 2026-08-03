<?php

namespace Tests\Unit;

use App\Creative\Rendering\RenderTimelineContract;
use RuntimeException;
use Tests\TestCase;

class RenderTimelineContractTest extends TestCase
{
    public function test_small_per_clip_drift_cannot_accumulate_past_one_output_frame(): void
    {
        $shots = [];
        $probes = [];
        for ($index = 0; $index < 5; $index++) {
            $shots[] = [
                'id' => sprintf('shot-%02d', $index + 1),
                'start_seconds' => (float) $index,
                'end_seconds' => (float) ($index + 1),
            ];
            $probes[] = [
                'duration_seconds' => 1.04,
                'stream_duration_seconds' => 1.04,
            ];
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cumulative scene timeline drift exceeds one output frame at shot-03.');
        (new RenderTimelineContract)->validateSceneTimeline([
            'total_duration_seconds' => 5.0,
            'shots' => $shots,
        ], $probes, 12);
    }

    public function test_aggregate_outputs_must_match_the_plan_within_one_frame(): void
    {
        $timeline = new RenderTimelineContract;

        $this->assertSame(5.04, $timeline->assertOutputDuration(
            'fixture',
            ['stream_duration_seconds' => 5.04],
            5.0,
            12,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('within one output frame');
        $timeline->assertOutputDuration(
            'fixture',
            ['stream_duration_seconds' => 5.1],
            5.0,
            12,
        );
    }
}
