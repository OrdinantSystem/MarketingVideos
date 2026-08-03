<?php

namespace Tests\Feature;

use App\Creative\CreativeWorkspaceRepository;
use App\Creative\Providers\ElevenLabsClient;
use App\Creative\Providers\FalClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CreativeProviderTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/providers-'.Str::lower(Str::random(8)));
        config([
            'creative.root' => $this->creativeRoot,
            'creative.generation.driver' => 'simulation',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_brief_and_script_uses_sol_then_fable_and_saves_provenance(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Provider script', 'Make a human 15-second advertisement.', 'concepts');
        $repository->simulate($target['project'], $target['run'], 'concepts');
        $concept = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'concepts', $concept['id']);

        config([
            'creative.generation.driver' => 'openrouter',
            'services.openrouter.key' => 'test-key',
            'services.openrouter.structure_model' => 'openai/gpt-5.6-sol',
            'services.openrouter.polish_model' => 'anthropic/claude-fable-5',
        ]);

        Http::fakeSequence()
            ->push($this->openRouterResponse('structure-id', 'openai/gpt-5.6-sol', 'Structured'))
            ->push($this->openRouterResponse('polish-id', 'anthropic/claude-fable-5', 'Polished'));

        $mode = $repository->simulate($target['project'], $target['run'], 'brief-script');
        $artifacts = $repository->workspace($target['project'], $target['run'], 'brief-script')['artifacts'];

        $this->assertSame('provider', $mode);
        $this->assertCount(3, $artifacts);
        $this->assertFalse($artifacts[0]['metadata']['simulation']);
        $this->assertSame('openrouter', $artifacts[0]['metadata']['generator']);
        $this->assertSame('openai/gpt-5.6-sol', $artifacts[0]['metadata']['structure_model']);
        $this->assertSame('anthropic/claude-fable-5', $artifacts[0]['metadata']['polish_model']);
        $this->assertSame([$concept['id']], $artifacts[0]['metadata']['source_artifact_ids']);
        $this->assertStringContainsString('Polished', $artifacts[0]['body']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['model'] === 'openai/gpt-5.6-sol'
            && $request['reasoning']['effort'] === 'low'
            && $request['response_format']['type'] === 'json_schema'
            && $request['provider']['require_parameters'] === true);
        Http::assertSent(fn (Request $request): bool => $request['model'] === 'anthropic/claude-fable-5'
            && ! isset($request['reasoning']));
    }

    public function test_provider_command_can_check_all_keys_without_generation(): void
    {
        config([
            'services.openrouter.key' => 'openrouter-test',
            'services.fal.key' => 'fal-test',
            'services.elevenlabs.key' => 'eleven-test',
        ]);

        Http::fake([
            'https://openrouter.ai/*' => Http::response(['data' => []]),
            'https://api.fal.ai/*' => Http::response(['models' => []]),
            'https://api.elevenlabs.io/*' => Http::response([['model_id' => 'test']]),
        ]);

        $this->artisan('creative:providers --live')
            ->expectsOutputToContain('connected')
            ->assertSuccessful();

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_lightweight_media_adapters_use_fast_low_cost_settings(): void
    {
        config([
            'creative.generation.lightweight' => true,
            'services.fal.key' => 'fal-test',
            'services.fal.resolution' => '720p',
            'services.fal.duration' => 15,
            'services.fal.generate_audio' => true,
            'services.elevenlabs.key' => 'eleven-test',
            'services.elevenlabs.voice_id' => 'voice-test',
            'services.elevenlabs.model_id' => 'expensive-model',
        ]);

        Http::fake([
            'https://fal.run/*' => Http::response(['video' => ['url' => 'https://assets.example/preview.mp4']]),
            'https://api.elevenlabs.io/*' => Http::response('fake-mp3'),
        ]);

        $video = app(FalClient::class)->generateVideo('A simple test shot.');
        $speech = app(ElevenLabsClient::class)->synthesize('A simple test line.');

        $this->assertSame('https://assets.example/preview.mp4', $video['url']);
        $this->assertSame('fake-mp3', $speech);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'fal.run')
            && $request['resolution'] === '480p'
            && $request['duration'] === '4'
            && $request['generate_audio'] === false);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'elevenlabs')
            && $request['model_id'] === 'eleven_flash_v2_5');
    }

    public function test_invalid_provider_output_saves_no_partial_artifacts(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Failed provider', 'Keep failed output out of the artifact store.', 'concepts');
        $repository->simulate($target['project'], $target['run'], 'concepts');
        $concept = $repository->workspace($target['project'], $target['run'], 'concepts')['artifacts'][0];
        $repository->select($target['project'], $target['run'], 'concepts', $concept['id']);

        config([
            'creative.generation.driver' => 'openrouter',
            'services.openrouter.key' => 'test-key',
        ]);
        Http::fake([
            '*' => Http::response([
                'id' => 'invalid-response',
                'model' => 'test-model',
                'choices' => [['message' => ['content' => 'not-json']]],
            ]),
        ]);

        try {
            $repository->simulate($target['project'], $target['run'], 'brief-script');
            $this->fail('Invalid provider output should fail validation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('valid structured JSON', $exception->getMessage());
        }

        $this->assertCount(0, $repository->workspace($target['project'], $target['run'], 'brief-script')['artifacts']);
        Http::assertSentCount(1);
    }

    /** @return array<string, mixed> */
    private function openRouterResponse(string $id, string $model, string $label): array
    {
        $options = collect(range(1, 3))->map(fn (int $number): array => [
            'title' => "{$label} option {$number}",
            'summary' => "{$label} summary {$number}",
            'body' => "# {$label} option {$number}\n\n## Purpose\n\nA lightweight provider test.\n\n## Audience\n\nLocal test viewers.\n\n## Story\n\nA deterministic test story.\n\n## Tone and constraints\n\nClear and under 15 seconds.\n\n## 15-second script\n\n**0.0–15.0s** Test beat.\n\n## Shot intentions\n\nOne readable shot.\n\n## Call to action\n\nComplete the test.",
        ])->all();

        return [
            'id' => $id,
            'model' => $model,
            'choices' => [['message' => ['content' => json_encode(['options' => $options])]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ];
    }
}
