<?php

namespace Tests\Unit\System\Project\Git;

use App\System\Project\Git\Ref as GitRef;
use PHPUnit\Framework\TestCase;

class GitRefTest extends TestCase
{
    public function test_accepts_normal_branch_names(): void
    {
        $this->assertTrue(GitRef::isValidName('main'));
        $this->assertTrue(GitRef::isValidName('feature/foo'));
        $this->assertTrue(GitRef::isValidName('v1.2.3'));
    }

    public function test_rejects_option_injection_and_unsafe_names(): void
    {
        $this->assertFalse(GitRef::isValidName(''));
        $this->assertFalse(GitRef::isValidName('-a'));
        $this->assertFalse(GitRef::isValidName('--output=/tmp/x'));
        $this->assertFalse(GitRef::isValidName('foo..bar'));
        $this->assertFalse(GitRef::isValidName('foo@{bar'));
        $this->assertFalse(GitRef::isValidName('foo:bar'));
        $this->assertFalse(GitRef::isValidName('.hidden'));
        $this->assertFalse(GitRef::isValidName('foo.lock'));
    }
}
