<?php

namespace App\Console\Commands;

use App\Creative\Providers\ElevenLabsClient;
use App\Creative\Providers\FalClient;
use App\Creative\Providers\OpenRouterClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CreativeSmokeCommand extends Command
{
    protected $signature = 'creative:smoke
        {provider : openrouter, fal, or elevenlabs}
        {--spend : Confirm that a small paid generation may run}';

    protected $description = 'Run one explicit lightweight generation against a creative provider';

    public function handle(OpenRouterClient $openRouter, FalClient $fal, ElevenLabsClient $elevenLabs): int
    {
        $provider = strtolower((string) $this->argument('provider'));

        if (! in_array($provider, ['openrouter', 'fal', 'elevenlabs'], true)) {
            $this->error('Provider must be openrouter, fal, or elevenlabs.');

            return self::INVALID;
        }

        if (! $this->option('spend')) {
            $this->warn('No request was sent. Re-run with --spend to explicitly allow one small paid generation.');

            return self::FAILURE;
        }

        try {
            return match ($provider) {
                'openrouter' => $this->openRouterSmoke($openRouter),
                'fal' => $this->falSmoke($fal),
                'elevenlabs' => $this->elevenLabsSmoke($elevenLabs),
            };
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function openRouterSmoke(OpenRouterClient $client): int
    {
        $result = $client->chat([
            ['role' => 'system', 'content' => 'Reply with exactly: VideoAuto ready'],
            ['role' => 'user', 'content' => 'Connection test.'],
        ], (string) config('services.openrouter.structure_model'));

        $this->info('OpenRouter replied: '.$result['content']);

        return self::SUCCESS;
    }

    private function falSmoke(FalClient $client): int
    {
        $result = $client->generateVideo('A simple warm studio light slowly brightens over a plain cream background. No people, text, logos, or audio.');
        $path = $this->smokePath('seedance-preview.mp4');

        try {
            $video = Http::timeout(120)->get($result['url'])->throw()->body();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Seedance generated a video, but downloading the returned file failed.', previous: $exception);
        }

        File::put($path, $video);
        $this->info('Seedance preview saved to '.$path);

        return self::SUCCESS;
    }

    private function elevenLabsSmoke(ElevenLabsClient $client): int
    {
        $path = $this->smokePath('voice-preview.mp3');
        File::put($path, $client->synthesize('VideoAuto is ready.'));
        $this->info('ElevenLabs preview saved to '.$path);

        return self::SUCCESS;
    }

    private function smokePath(string $filename): string
    {
        $directory = storage_path('app/private/creative-smoke');
        File::ensureDirectoryExists($directory);

        return $directory.'/'.$filename;
    }
}
