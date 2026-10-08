<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\HostingUsername;
use PHPUnit\Framework\TestCase;

class HostingUsernameTest extends TestCase
{
    public function test_strips_hyphens_from_a_github_repo_url(): void
    {
        $this->assertSame(
            'saasstarterts',
            HostingUsername::fromGitRepo('https://github.com/xthezealot/saas-starter-ts')
        );
    }

    public function test_strips_dots_and_a_git_suffix(): void
    {
        $this->assertSame(
            'calcom',
            HostingUsername::fromGitRepo('https://github.com/calcom/cal.com.git')
        );
    }

    public function test_caps_at_fifteen_characters(): void
    {
        $this->assertSame(
            'verylongreponam',
            HostingUsername::fromGitRepo('https://github.com/org/very-long-repo-name-here')
        );
        $this->assertSame(15, strlen('verylongreponam'));
    }

    public function test_drops_leading_digits_so_the_name_starts_with_a_letter(): void
    {
        $this->assertSame('nextapp', HostingUsername::normalize('123-next-app'));
    }

    public function test_returns_null_when_nothing_legal_remains(): void
    {
        $this->assertNull(HostingUsername::fromGitRepo('https://github.com/org/---'));
        $this->assertNull(HostingUsername::normalize('ab'));
        $this->assertNull(HostingUsername::normalize('12'));
    }

    public function test_stem_leaves_room_for_a_four_digit_suffix(): void
    {
        $this->assertSame('saasstarter', HostingUsername::stemForSuffix('saasstarterts'));
        $this->assertSame(11, strlen('saasstarter'));
        $this->assertSame(15, strlen(HostingUsername::stemForSuffix('saasstarterts') . '1234'));
        $this->assertSame('short', HostingUsername::stemForSuffix('short'));
    }
}
