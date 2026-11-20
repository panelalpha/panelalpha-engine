<?php

namespace Tests\Integration\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** `acme:challenge:prune --ttl-hours` is taken as given: a TTL below one hour is refused, not replaced by the default. */
class PruneHttpAcmeChallengesCommandTest extends TestCase
{
    public function test_a_ttl_of_zero_hours_is_refused_and_nothing_is_pruned(): void
    {
        $exit = Artisan::call('acme:challenge:prune', ['--ttl-hours' => '0']);

        $output = Artisan::output();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('TTL hours must be >= 1', $output);
        $this->assertStringNotContainsString('Pruning', $output);
    }
}
