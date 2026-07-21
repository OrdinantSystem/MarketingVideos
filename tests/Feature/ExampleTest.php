<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_the_current_creative_workspace(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirectContains('/projects/');
        $response->assertRedirectContains('/runs/');
    }
}
