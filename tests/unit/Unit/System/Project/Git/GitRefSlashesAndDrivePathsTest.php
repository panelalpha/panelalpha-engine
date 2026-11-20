<?php

namespace Tests\Unit\System\Project\Git;

use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\Path as GitPath;
use App\System\Project\Git\Ref as GitRef;
use PHPUnit\Framework\TestCase;

class GitRefSlashesAndDrivePathsTest extends TestCase
{
    public function test_a_name_with_an_empty_path_component_is_refused(): void
    {
        $this->assertFalse(GitRef::isValidName('feature//foo'));
        $this->assertFalse(GitRef::isValidName('/main'));
        $this->assertFalse(GitRef::isValidName('feature/'));
    }

    public function test_asserting_an_invalid_name_throws_a_422(): void
    {
        try {
            GitRef::assertName('foo bar');
            $this->fail('A name with a space was accepted.');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('Invalid git ref name.', $e->getMessage());
        }
    }

    public function test_asserting_a_valid_name_passes(): void
    {
        GitRef::assertName('release/1.2');

        $this->assertTrue(GitRef::isValidName('release/1.2'));
    }

    public function test_a_drive_letter_path_under_the_home_is_keyed_relative_to_it(): void
    {
        $this->assertSame('public_html', GitPath::key('C:\\Users\\alice\\public_html', 'C:\\Users\\alice'));
        $this->assertSame('', GitPath::key('C:/Users/alice', 'C:/Users/alice/'));
    }

    public function test_a_drive_letter_path_elsewhere_keeps_its_whole_path(): void
    {
        $this->assertSame('D:/data/site', GitPath::key('D:/data/site', 'C:/Users/alice'));
    }
}
