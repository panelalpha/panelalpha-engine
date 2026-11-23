<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\CopiedAppFiles;
use PHPUnit\Framework\TestCase;

class CopiedAppFilesTest extends TestCase
{
    private const FROM = ['database' => 'alice_app', 'username' => 'alice_app', 'password' => 'Src3tPass'];

    private const TO = ['database' => 'bob_app', 'username' => 'bob_app', 'password' => 'N3wPass'];

    public function test_the_password_the_login_and_the_host_become_the_copys(): void
    {
        $this->assertSame([
            'Src3tPass' => 'N3wPass',
            'alice_app' => 'bob_app',
            'alice.example.test' => 'bob.example.test',
        ], CopiedAppFiles::replacements(self::FROM, self::TO, ['alice.example.test', 'bob.example.test']));
    }

    public function test_a_value_both_projects_share_is_left_alone(): void
    {
        $same = ['database' => 'shared', 'username' => 'shared', 'password' => 'Src3tPass'];

        $this->assertSame([], CopiedAppFiles::replacements($same, $same, null));
    }

    public function test_each_value_is_matched_literally_whatever_it_holds(): void
    {
        $this->assertSame(
            ['-e', 's/a\.b\*c\[d\^e\$f\/g\\\\h/x\&y\/z\\\\w/g'],
            CopiedAppFiles::sedArguments(['a.b*c[d^e$f/g\h' => 'x&y/z\w'])
        );
    }
}
