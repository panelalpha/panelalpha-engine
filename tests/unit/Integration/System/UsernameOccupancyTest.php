<?php

namespace Tests\Integration\System;

use App\System;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;

class UsernameOccupancyTest extends TestCase
{
    public function test_rejects_reserved_and_illegal_names_without_touching_the_host(): void
    {
        $system = new System();

        $this->assertFalse($system->isUsernameAvailable('root'));
        $this->assertFalse($system->isUsernameAvailable('www-data'));
        $this->assertFalse($system->isUsernameAvailable('Alice'));
        $this->assertFalse($system->isUsernameAvailable('1alice'));
        $this->assertFalse($system->isUsernameAvailable(''));
        $this->assertFalse($system->isUsernameAvailable('a' . str_repeat('x', 32)));
    }

    public function test_rejects_a_legal_name_when_the_uid_already_exists(): void
    {
        $system = new class extends System {
            public function isUidExists(string $username): bool
            {
                return true;
            }
        };

        $this->assertFalse($system->isUsernameAvailable('alice'));
    }

    public function test_accepts_a_legal_name_when_uid_and_dirs_are_free(): void
    {
        $system = new class extends System {
            public function isUidExists(string $username): bool
            {
                return false;
            }

            public function isContainerNameTaken(string $name): bool
            {
                return false;
            }
        };

        $this->assertTrue($system->isUsernameAvailable('alice'));
        $this->assertTrue($system->isUsernameAvailable('alice-01'));
    }

    /**
     * A leftover container named like the account let the create through and
     * failed it in `preparing` on Compose's name conflict.
     */
    public function test_rejects_a_legal_name_a_host_container_already_has(): void
    {
        $system = new class extends System {
            /** @var list<list<string>> */
            public array $ran = [];

            public function isUidExists(string $username): bool
            {
                return false;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = $cmd;

                return UsernameOccupancyTest::finished("alice\n");
            }
        };

        $this->assertFalse($system->isUsernameAvailable('alice'));
        $this->assertSame('docker.containerNamed', LocalRoot::action($system->ran[0]));
        $this->assertSame(['alice'], LocalRoot::values($system->ran[0]));
    }

    public function test_a_name_no_container_has_is_not_taken(): void
    {
        $system = new class extends System {
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return UsernameOccupancyTest::finished('');
            }
        };

        $this->assertFalse($system->isContainerNameTaken('alice'));
    }

    /** A host the broker cannot enter answered nothing, so the name is not known to be free. */
    public function test_a_host_namespace_refusal_is_not_a_free_name(): void
    {
        $system = new class extends System {
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::failed('pa-root: cannot enter the host\'s namespaces: Operation not permitted');
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('pa-root: cannot enter the host\'s namespaces: Operation not permitted');
        $system->isUsernameAvailable('alice');
    }

    /** `id -u` saying there is no such user is an answer, not a broken hop. */
    public function test_id_saying_the_user_does_not_exist_is_a_free_name(): void
    {
        $system = new class extends System {
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::failed("id: 'alice': no such user");
            }
        };

        $this->assertFalse($system->isUidExists('alice'));
        $this->assertTrue($system->isUsernameAvailable('alice'));
    }

    /** A process that already ran and printed $stdout, without running anything. */
    public static function finished(string $stdout): Process
    {
        return new class ($stdout) extends Process {
            public function __construct(private string $stdout)
            {
                parent::__construct(['true']);
            }

            public function isSuccessful(): bool
            {
                return true;
            }

            public function getOutput(): string
            {
                return $this->stdout;
            }
        };
    }
}
