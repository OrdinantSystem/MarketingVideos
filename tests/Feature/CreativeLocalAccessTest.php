<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCreativeLocalAccess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreativeLocalAccessTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-access-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->creativeRoot);
        config([
            'creative.root' => $this->creativeRoot,
            'creative.access.mode' => 'local',
            'creative.access.port' => 8000,
            'creative.generation.driver' => 'simulation',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_ipv4_loopback_can_open_the_creative_workspace(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/projects/new')
            ->assertOk();
    }

    public function test_ipv6_loopback_can_open_the_creative_workspace(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '::1'])
            ->get('/projects/new')
            ->assertOk();
    }

    public function test_localhost_and_numeric_loopback_hosts_with_the_configured_port_are_allowed(): void
    {
        foreach ([
            ['remote' => '127.0.0.1', 'host' => 'localhost:8000'],
            ['remote' => '127.0.0.1', 'host' => '127.0.0.1:8000'],
            ['remote' => '::1', 'host' => '[::1]:8000'],
        ] as $case) {
            $this->withServerVariables([
                'REMOTE_ADDR' => $case['remote'],
            ])
                ->get('http://'.$case['host'].'/projects/new')
                ->assertOk();
        }
    }

    public function test_loopback_peer_with_a_non_local_host_is_rejected_even_when_forwarded_host_is_local(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-Host', 'localhost:8000')
            ->get('http://attacker.example/projects/new')
            ->assertForbidden();
    }

    public function test_explicit_host_port_must_match_the_configured_local_port(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('http://localhost:9000/projects/new')
            ->assertForbidden();
    }

    public function test_cross_origin_write_is_rejected_without_mutating_the_workspace(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Origin', 'https://attacker.example')
            ->post('http://localhost:8000/projects', $this->projectPayload('Cross-origin project'))
            ->assertForbidden();

        $this->assertSame([], File::directories($this->creativeRoot));
    }

    public function test_cross_origin_referer_is_rejected_when_origin_is_absent(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Referer', 'http://attacker.example/form')
            ->post('http://localhost:8000/projects', $this->projectPayload('Cross-origin referer project'))
            ->assertForbidden();

        $this->assertSame([], File::directories($this->creativeRoot));
    }

    public function test_same_origin_browser_write_is_allowed(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'Origin' => 'http://localhost:8000',
                'Referer' => 'http://localhost:8000/projects/new',
            ])->post('http://localhost:8000/projects', $this->projectPayload('Same-origin project'))
            ->assertRedirect();

        $this->assertCount(1, File::directories($this->creativeRoot));
    }

    public function test_same_host_non_browser_write_without_origin_or_referer_is_allowed(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->post('http://127.0.0.1:8000/projects', $this->projectPayload('Local tool project'))
            ->assertRedirect();

        $this->assertCount(1, File::directories($this->creativeRoot));
    }

    public function test_remote_address_cannot_open_the_creative_home_route(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Forwarded-For', '127.0.0.1')
            ->get('/')
            ->assertForbidden();
    }

    public function test_remote_address_cannot_mutate_the_creative_workspace(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/projects', [
                'name' => 'Remote project',
                'idea' => 'This request must never reach the creative controller.',
                'start_phase' => 'concepts',
            ])
            ->assertForbidden();

        $this->assertSame([], File::directories($this->creativeRoot));
    }

    public function test_unsupported_access_mode_fails_closed(): void
    {
        config(['creative.access.mode' => 'authenticated']);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/projects/new')
            ->assertStatus(503);
    }

    public function test_dashboard_and_settings_keep_their_authentication_behavior(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/dashboard')
            ->assertRedirect('/login');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/settings/profile')
            ->assertRedirect('/login');
    }

    public function test_every_named_creative_route_uses_the_local_access_middleware(): void
    {
        $creativeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (\Illuminate\Routing\Route $route): bool => $route->getName() === 'home'
                || str_starts_with((string) $route->getName(), 'creative.'));

        $this->assertNotEmpty($creativeRoutes);
        foreach ($creativeRoutes as $route) {
            $this->assertContains(EnsureCreativeLocalAccess::class, $route->middleware());
        }
    }

    /** @return array{name: string, idea: string, start_phase: string} */
    private function projectPayload(string $name): array
    {
        return [
            'name' => $name,
            'idea' => 'This request exercises the local access boundary.',
            'start_phase' => 'concepts',
        ];
    }
}
