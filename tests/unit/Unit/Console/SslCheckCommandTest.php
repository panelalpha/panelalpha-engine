<?php

namespace Tests\Unit\Console;

use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class SslCheckCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_name_no_project_has_is_refused_as_typed_in_lower_case(): void
    {
        $this->artisan('ssl:check', ['domain' => '  Nowhere.Example.COM '])
            ->expectsOutputToContain('No project domain called nowhere.example.com on this engine.')
            ->assertExitCode(1);
    }
}
