<?php

namespace App\Console\Commands;

use App\Creative\Providers\ElevenLabsClient;
use App\Creative\Providers\FalClient;
use App\Creative\Providers\OpenRouterClient;
use Illuminate\Console\Command;
use RuntimeException;

class CreativeProvidersCommand extends Command
{
    protected $signature = 'creative:providers {--live : Check credentials with non-generation API requests}';

    protected $description = 'Show the creative provider configuration and optionally validate credentials';

    public function handle(OpenRouterClient $openRouter, FalClient $fal, ElevenLabsClient $elevenLabs): int
    {
        $providers = [
            ['OpenRouter', $openRouter, config('services.openrouter.structure_model').' + '.config('services.openrouter.polish_model')],
            ['fal.ai', $fal, config('services.fal.seedance_model')],
            ['ElevenLabs', $elevenLabs, config('services.elevenlabs.model_id')],
        ];
        $failed = false;
        $rows = [];

        foreach ($providers as [$name, $client, $models]) {
            if (! $client->configured()) {
                $rows[] = [$name, 'missing key', $models];
                $failed = true;

                continue;
            }

            $status = 'configured';

            if ($this->option('live')) {
                try {
                    $client->check();
                    $status = 'connected';
                } catch (RuntimeException $exception) {
                    $status = $exception->getMessage();
                    $failed = true;
                }
            }

            $rows[] = [$name, $status, $models];
        }

        $this->table(['Provider', 'Status', 'Configured model(s)'], $rows);
        $this->newLine();
        $this->line($this->option('live')
            ? 'Credential checks do not generate text, speech, or video.'
            : 'Add --live to validate the configured keys without running paid generation.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
