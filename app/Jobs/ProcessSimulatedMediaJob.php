<?php

namespace App\Jobs;

use App\Creative\Media\SimulatedMediaJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessSimulatedMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $project,
        public readonly string $run,
        public readonly string $phase,
        public readonly string $mediaJobId,
    ) {
        $this->timeout = max(1, (int) config('creative.media.timeout_seconds', 30)) + 5;
    }

    public function handle(SimulatedMediaJobService $media): void
    {
        $media->process($this->project, $this->run, $this->phase, $this->mediaJobId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        app(SimulatedMediaJobService::class)->terminalizeWorkerFailure(
            $this->project,
            $this->run,
            $this->phase,
            $this->mediaJobId,
            $exception,
        );
    }
}
