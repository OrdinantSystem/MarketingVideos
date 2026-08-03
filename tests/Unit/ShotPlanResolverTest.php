<?php

namespace Tests\Unit;

use App\Creative\ShotPlanResolver;
use PHPUnit\Framework\TestCase;

class ShotPlanResolverTest extends TestCase
{
    public function test_it_accepts_and_canonicalizes_a_structured_shot_plan(): void
    {
        $resolution = (new ShotPlanResolver)->resolve([
            'metadata' => ['shot_plan' => $this->validPlan()],
            'body' => 'This body is not used when structured metadata exists.',
        ]);

        $this->assertTrue($resolution['valid']);
        $this->assertSame('metadata', $resolution['origin']);
        $this->assertSame([], $resolution['warnings']);
        $this->assertSame(['shot-01', 'shot-02'], array_column($resolution['shot_plan']['shots'], 'id'));
        $this->assertSame(5.0, $resolution['shot_plan']['total_duration_seconds']);
    }

    public function test_it_derives_a_legacy_plan_from_labeled_and_unlabeled_timed_markdown(): void
    {
        $resolution = (new ShotPlanResolver)->resolve([
            'metadata' => ['schema_version' => 1],
            'body' => <<<'MARKDOWN'
# Script

## 15-second script

**0.0–2.0s — Hook**  
Profiles move past quickly.

**2.0-5.0s** One expressive voice answer plays.

## Shot intentions

Keep it readable.
MARKDOWN,
        ]);

        $this->assertTrue($resolution['valid']);
        $this->assertSame('legacy_markdown', $resolution['origin']);
        $this->assertCount(1, $resolution['warnings']);
        $this->assertStringContainsString('legacy timed Markdown', $resolution['warnings'][0]);
        $this->assertSame('Hook', $resolution['shot_plan']['shots'][0]['purpose']);
        $this->assertSame('Beat 2', $resolution['shot_plan']['shots'][1]['purpose']);
        $this->assertSame('One expressive voice answer plays.', $resolution['shot_plan']['shots'][1]['visual']);
    }

    public function test_invalid_structured_metadata_is_not_silently_replaced_by_markdown(): void
    {
        $plan = $this->validPlan();
        $plan['shots'][1]['start_seconds'] = 3.0;

        $resolution = (new ShotPlanResolver)->resolve([
            'metadata' => ['shot_plan' => $plan],
            'body' => "## 15-second script\n\n**0.0–5.0s — Valid fallback** A valid body.",
        ]);

        $this->assertFalse($resolution['valid']);
        $this->assertSame('metadata', $resolution['origin']);
        $this->assertContains('Shot 2 must start when the previous shot ends.', $resolution['errors']);
    }

    public function test_it_rejects_unparseable_legacy_scripts_instead_of_inventing_shots(): void
    {
        $resolution = (new ShotPlanResolver)->resolve([
            'metadata' => ['schema_version' => 1],
            'body' => "# Script\n\n## Story\n\nThere are no timed entries.",
        ]);

        $this->assertFalse($resolution['valid']);
        $this->assertNull($resolution['shot_plan']);
        $this->assertStringContainsString('no parseable', $resolution['errors'][0]);
    }

    public function test_it_rejects_non_contiguous_and_overlong_plans(): void
    {
        $plan = $this->validPlan();
        $plan['total_duration_seconds'] = 16.0;
        $plan['shots'][1]['start_seconds'] = 3.0;
        $plan['shots'][1]['end_seconds'] = 16.0;

        $resolution = (new ShotPlanResolver)->validate($plan);

        $this->assertFalse($resolution['valid']);
        $this->assertContains(
            'shot_plan.total_duration_seconds must be greater than 0 and no more than 15.',
            $resolution['errors'],
        );
        $this->assertContains('Shot 2 must start when the previous shot ends.', $resolution['errors']);
        $this->assertContains('Shot 2 end_seconds must be greater than 0 and no more than 15.', $resolution['errors']);
    }

    /** @return array<string, mixed> */
    private function validPlan(): array
    {
        return [
            'version' => 1,
            'total_duration_seconds' => 5.0,
            'shots' => [
                [
                    'id' => 'shot-01',
                    'order' => 1,
                    'start_seconds' => 0.0,
                    'end_seconds' => 2.0,
                    'purpose' => 'Hook',
                    'visual' => 'Profiles move past quickly.',
                ],
                [
                    'id' => 'shot-02',
                    'order' => 2,
                    'start_seconds' => 2.0,
                    'end_seconds' => 5.0,
                    'purpose' => 'Product',
                    'visual' => 'One expressive voice answer plays.',
                ],
            ],
        ];
    }
}
