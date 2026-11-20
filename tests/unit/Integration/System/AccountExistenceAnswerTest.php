<?php

namespace Tests\Integration\System;

use App\System;
use Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;

/**
 * `id -u` answers an exists question: 0 for an account, 1 for none. Every other answer means the host did not say, and a
 * delete that reads it as "no account" leaves the account behind while removing its home.
 */
class AccountExistenceAnswerTest extends TestCase
{
    public function test_an_id_printing_a_uid_is_an_account(): void
    {
        $system = $this->system(FakeProcess::ok("1004\n"));

        $this->assertTrue($system->isUidExists('alice'));
        $this->assertSame('users.uid', LocalRoot::action($system->asked[0]));
        $this->assertSame(['alice'], LocalRoot::values($system->asked[0]));
    }

    public function test_an_id_saying_there_is_no_such_user_is_no_account(): void
    {
        $this->assertFalse($this->system(FakeProcess::failed("id: 'alice': no such user", 1))->isUidExists('alice'));
    }

    public function test_a_refused_id_is_not_read_as_no_account(): void
    {
        $system = $this->system(FakeProcess::failed('pa-root: refused: the account database is not readable', 77));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("did not answer whether the account 'alice' exists (exit code 77)");
        $system->isUidExists('alice');
    }

    public function test_an_id_that_was_killed_is_not_read_as_no_account(): void
    {
        $system = $this->system(FakeProcess::failed('', 137));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('exit code 137');
        $system->isUidExists('alice');
    }

    private function system(Process $answer): System
    {
        return new class($answer) extends System
        {
            /** @var list<list<string>> */
            public array $asked = [];

            public function __construct(private readonly Process $answer) {}

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->asked[] = $cmd;

                return $this->answer;
            }
        };
    }
}
