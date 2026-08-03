<?php

namespace App\Jobs;

use App\Creative\Rendering\DeterministicRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessDeterministicRender implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $project,
        public readonly string $run,
        public readonly string $renderId,
    ) {
        $this->timeout = max(1, (int) config('creative.render.timeout_seconds', 90)) + 5;
    }

    public function handle(DeterministicRenderService $renderer): void
    {
        $renderer->process($this->project, $this->run, $this->renderId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        app(DeterministicRenderService::class)->terminalizeWorkerFailure(
            $this->project,
            $this->run,
            $this->renderId,
            $exception,
        );
    }
}
