<?php

namespace Tests\Unit\System;

use App\System;
use PHPUnit\Framework\TestCase;

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
}
