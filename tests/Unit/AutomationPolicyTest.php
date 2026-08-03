<?php

namespace Tests\Unit;

use App\Creative\Automation\AutomationPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AutomationPolicyTest extends TestCase
{
    public function test_automatic_policy_is_simulation_only(): void
    {
        $policy = (new AutomationPolicy)->make('concepts');

        $this->assertSame('simulation', $policy['driver']);
        $this->assertSame(0.0, $policy['max_cost_usd']);
        $this->assertGreaterThan(0, $policy['max_simulation_units']);
    }

    public function test_external_driver_cannot_be_selected_for_automatic_mode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('simulation-only');

        (new AutomationPolicy)->make('concepts', ['driver' => 'openrouter']);
    }
}
