<?php

namespace App\Creative\Rendering;

use App\Creative\MediaAssetContract;
use RuntimeException;

final class RenderTimelineContract
{
    private const FLOAT_EPSILON = 0.000001;

    public function tolerance(int $framesPerSecond): float
    {
        return 1 / max(1, $framesPerSecond);
    }

    /**
     * @param  array<string, mixed>  $shotPlan
     * @param  list<array<string, int|float|string|null>>  $sceneProbes
     * @return array{
     *     expected_duration_seconds: float,
     *     tolerance_seconds: float,
     *     scene_boundaries: list<array{shot_id: string, expected_end_seconds: float, actual_end_seconds: float, drift_seconds: float}>
     * }
     */
    public function validateSceneTimeline(array $shotPlan, array $sceneProbes, int $framesPerSecond): array
    {
        $shots = $shotPlan['shots'] ?? null;
        $total = $shotPlan['total_duration_seconds'] ?? null;
        if (! is_array($shots)
            || ! array_is_list($shots)
            || $shots === []
            || ! is_numeric($total)
            || ! is_finite((float) $total)
            || (float) $total <= 0
            || count($shots) !== count($sceneProbes)) {
            throw new RuntimeException('The render timeline does not match one probe to every planned shot.');
        }

        $tolerance = $this->tolerance($framesPerSecond);
        $actualEnd = 0.0;
        $boundaries = [];
        foreach ($shots as $index => $shot) {
            if (! is_array($shot)
                || ! is_numeric($shot['start_seconds'] ?? null)
                || ! is_numeric($shot['end_seconds'] ?? null)) {
                throw new RuntimeException('The render timeline contains an invalid shot timing snapshot.');
            }

            $start = (float) $shot['start_seconds'];
            $end = (float) $shot['end_seconds'];
            $expectedDuration = $end - $start;
            $actualDuration = $this->duration($sceneProbes[$index]);
            if ($expectedDuration <= 0
                || abs($actualDuration - $expectedDuration) > MediaAssetContract::VIDEO_DURATION_TOLERANCE_SECONDS + self::FLOAT_EPSILON) {
                throw new RuntimeException('A rendered scene duration exceeds the permitted per-clip timing tolerance.');
            }

            $actualEnd += $actualDuration;
            $drift = $actualEnd - $end;
            $shotId = is_string($shot['id'] ?? null) ? $shot['id'] : 'shot-'.($index + 1);
            $boundaries[] = [
                'shot_id' => $shotId,
                'expected_end_seconds' => $end,
                'actual_end_seconds' => $actualEnd,
                'drift_seconds' => $drift,
            ];
            if (abs($drift) > $tolerance + self::FLOAT_EPSILON) {
                throw new RuntimeException("Cumulative scene timeline drift exceeds one output frame at {$shotId}.");
            }
        }

        if (abs($actualEnd - (float) $total) > $tolerance + self::FLOAT_EPSILON) {
            throw new RuntimeException('The aggregate scene duration exceeds one output frame from the selected shot plan.');
        }

        return [
            'expected_duration_seconds' => (float) $total,
            'tolerance_seconds' => $tolerance,
            'scene_boundaries' => $boundaries,
        ];
    }

    /** @param array<string, int|float|string|null> $probe */
    public function assertOutputDuration(string $label, array $probe, float $expected, int $framesPerSecond): float
    {
        $actual = $this->duration($probe);
        if (abs($actual - $expected) > $this->tolerance($framesPerSecond) + self::FLOAT_EPSILON) {
            throw new RuntimeException("The {$label} duration does not match the selected shot-plan timeline within one output frame.");
        }

        return $actual;
    }

    /** @param array<string, int|float|string|null> $probe */
    private function duration(array $probe): float
    {
        $duration = $probe['stream_duration_seconds'] ?? $probe['duration_seconds'] ?? null;
        if (! is_numeric($duration) || ! is_finite((float) $duration) || (float) $duration <= 0) {
            throw new RuntimeException('A media probe has no positive finite stream duration.');
        }

        return (float) $duration;
    }
}
