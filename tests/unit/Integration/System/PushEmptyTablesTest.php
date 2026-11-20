<?php

namespace Tests\Integration\System;

use App\System;
use App\System\Project\PushAttempt;
use App\System\Projects;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class PushEmptyTablesTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_an_empty_selection_is_refused_before_the_target_changes(): void
    {
        $from = $this->makeUser('pushempty-live', ['mysql_prefix' => 'alice_', 'deploy_strategy' => 'git']);
        $to = $this->makeUser('pushempty-stg', ['mysql_prefix' => 'bob_']);
        $to->staging = $from->id;
        $to->save();
        $before = $to->refresh()->getDetails();

        try {
            (new Projects(new System))->pushToApp($from->username, $to->username, true, true, []);
            $this->fail('An empty table selection was pushed.');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('Selecting tables needs at least one table.', $e->getMessage());
        }

        $this->assertSame($before, $to->refresh()->getDetails());
    }

    public function test_an_empty_selection_never_falls_back_to_whole_databases(): void
    {
        $attempt = (new \ReflectionClass(PushAttempt::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(PushAttempt::class, 'tables'))->setValue($attempt, []);

        $this->assertTrue($attempt->replacesTables());
    }
}
