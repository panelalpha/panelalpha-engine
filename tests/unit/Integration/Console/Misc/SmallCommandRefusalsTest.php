<?php

namespace Tests\Integration\Console\Misc;

use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** domain:php-version, domain:php-directives:set and project:overlay:show answer an empty name and a project without its own overlay. */
class SmallCommandRefusalsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeMainDomain($this->makeUser('shop'), 'shop.example.test');
    }

    public function test_an_empty_domain_name_is_refused(): void
    {
        $this->artisan('domain:php-version', ['domain' => ' '])
            ->expectsOutput('Domain name is required.')
            ->assertExitCode(1);
        $this->artisan('domain:php-directives:set', ['domain' => ' ', '--clear' => true])
            ->expectsOutput('Domain name is required.')
            ->assertExitCode(1);
    }

    public function test_overlay_show_prints_a_project_without_an_overlay(): void
    {
        $this->assertSame(0, Artisan::call('project:overlay:show', ['--project' => 'shop', '--json' => true]));

        $state = json_decode(Artisan::output(), true);
        $this->assertNull($state['html']);
        $this->assertFalse($state['active']);
    }
}
