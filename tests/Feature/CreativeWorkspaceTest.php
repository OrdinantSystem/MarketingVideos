<?php

namespace Tests\Feature;

use App\Creative\CreativeWorkspaceRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreativeWorkspaceTest extends TestCase
{
    private string $creativeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creativeRoot = storage_path('framework/testing/creative-'.Str::lower(Str::random(8)));
        config(['creative.root' => $this->creativeRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->creativeRoot);

        parent::tearDown();
    }

    public function test_project_is_created_as_a_filesystem_workspace(): void
    {
        $response = $this->post('/projects', [
            'name' => 'Quiet confidence',
            'idea' => 'A 15-second advertisement about a calmer way to begin a conversation.',
            'start_phase' => 'concepts',
        ]);

        $response->assertRedirect();

        $projects = app(CreativeWorkspaceRepository::class)->projects();
        $project = $projects[0];
        $projectPath = $this->creativeRoot.'/'.$project['id'];

        $this->assertFileExists($projectPath.'/project.md');
        $this->assertFileExists($projectPath.'/runs/'.$project['latest_run'].'/feedback/current/cycle.md');
        $this->assertDirectoryExists($projectPath.'/runs/'.$project['latest_run'].'/02-brief-script/outputs');
    }

    public function test_options_can_be_generated_selected_and_handed_forward(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Human opening', 'Make dating conversations feel human.', 'concepts');
        $base = "/projects/{$target['project']}/runs/{$target['run']}";

        $this->post("{$base}/concepts/simulate")->assertRedirect();

        $workspace = $repository->workspace($target['project'], $target['run'], 'concepts');
        $this->assertCount(3, $workspace['artifacts']);

        $artifact = $workspace['artifacts'][0];
        $this->post("{$base}/concepts/artifacts/{$artifact['id']}/select")
            ->assertRedirect("{$base}/brief-script");

        $next = $repository->workspace($target['project'], $target['run'], 'brief-script');
        $this->assertSame($artifact['id'], $next['inputs'][0]['id']);
        $this->assertTrue($repository->workspace($target['project'], $target['run'], 'concepts')['phase']['complete']);
    }

    public function test_a_new_run_preserves_the_previous_iteration(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $first = $repository->createProject('Repeatable project', 'Test several creative iterations.', 'concepts');

        $this->post("/projects/{$first['project']}/runs")->assertRedirect();

        $workspace = $repository->workspace($first['project'], $first['run'], 'concepts');
        $this->assertCount(2, $workspace['runs']);
        $this->assertFileExists($this->creativeRoot."/{$first['project']}/runs/{$first['run']}/run.md");
    }

    public function test_feedback_moves_from_the_inbox_to_immutable_history(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Feedback loop', 'Test an advertisement idea.', 'concepts');
        $base = "/projects/{$target['project']}/runs/{$target['run']}/concepts/feedback";

        $this->post($base, [
            'scope' => 'phase',
            'feedback' => 'Make the hook understandable without sound.',
        ])->assertRedirect();

        $pending = $repository->workspace($target['project'], $target['run'], 'concepts')['feedback'];
        $this->assertCount(1, $pending['current']);

        $this->post($base.'/archive', ['scope' => 'phase'])->assertRedirect();

        $archived = $repository->workspace($target['project'], $target['run'], 'concepts')['feedback'];
        $this->assertCount(0, $archived['current']);
        $this->assertSame(1, $archived['history_count']);
    }

    public function test_workspace_page_exposes_six_simple_phases(): void
    {
        $repository = app(CreativeWorkspaceRepository::class);
        $target = $repository->createProject('Six phases', 'A simple production chain.', 'concepts');

        $this->get("/projects/{$target['project']}/runs/{$target['run']}/concepts")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('creative/workspace')
                ->has('phases', 6)
                ->where('phase.key', 'concepts'));
    }
}
