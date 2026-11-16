<?php

namespace Tests\Unit\Project;

use App\Lib\Project\ProjectNameRefusal;
use App\System;
use PHPUnit\Framework\TestCase;

/**
 * A name the host still holds is refused with what holds it and the command that frees it, so an operator who deleted a
 * project but whose account or home stayed behind is not sent looking for a project that is already gone.
 */
class ProjectNameRefusalHoldersTest extends TestCase
{
    public function test_a_reserved_or_unacceptable_name_keeps_the_old_wording(): void
    {
        $this->assertSame(
            "'root' is reserved or already used by the system. Choose another name.",
            ProjectNameRefusal::unavailable('root')
        );
    }

    public function test_a_name_the_host_holds_says_what_holds_it(): void
    {
        $this->assertSame(
            "'orphan' is still held on this server by an account and a home directory. "
            .'Remove what holds it, or choose another name.',
            ProjectNameRefusal::unavailable('orphan', [System::HOLDER_ACCOUNT, System::HOLDER_HOME])
        );
    }

    public function test_one_holder_reads_as_one(): void
    {
        $this->assertStringStartsWith(
            "'orphan' is still held on this server by a container.",
            ProjectNameRefusal::unavailable('orphan', [System::HOLDER_CONTAINER])
        );
    }

    public function test_three_holders_are_listed_with_a_final_and(): void
    {
        $this->assertStringStartsWith(
            "'orphan' is still held on this server by an account, a project directory and a container.",
            ProjectNameRefusal::unavailable('orphan', [
                System::HOLDER_ACCOUNT,
                System::HOLDER_PROJECT_DIRECTORY,
                System::HOLDER_CONTAINER,
            ])
        );
    }
}
