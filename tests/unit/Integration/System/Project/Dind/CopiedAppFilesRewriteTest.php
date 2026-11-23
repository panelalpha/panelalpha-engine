<?php

namespace Tests\Integration\System\Project\Dind;

use App\System\Project\Dind\CopiedAppFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The rewrite the account runs over a copied checkout, run here on a scratch tree. */
class CopiedAppFilesRewriteTest extends TestCase
{
    private const CONFIG = "[database]\nhost = \"database-users\"\nusername = \"alice_app\"\npassword = \"Src3tPass\"\n"
        ."dbname = \"alice_app\"\n[General]\ntrusted_hosts[] = \"alice.example.test\"\n";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pa-copied-app-files-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/config', 0o777, true);
        file_put_contents($this->dir.'/config/config.ini.php', self::CONFIG);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
    }

    public function test_a_file_holding_the_source_login_is_pointed_at_the_copys_database_and_host(): void
    {
        $this->assertSame(['config/config.ini.php'], $this->rewrite());
        $this->assertSame(
            "[database]\nhost = \"database-users\"\nusername = \"bob_app\"\npassword = \"N3wPass\"\n"
                ."dbname = \"bob_app\"\n[General]\ntrusted_hosts[] = \"bob.example.test\"\n",
            file_get_contents($this->dir.'/config/config.ini.php')
        );
    }

    /** Naming the source's database is not holding its login: a log or an export keeps its text. */
    public function test_a_file_without_the_source_password_is_left_as_it_is(): void
    {
        file_put_contents($this->dir.'/export.sql', "-- dumped from alice_app on alice.example.test\n");

        $this->rewrite();

        $this->assertSame("-- dumped from alice_app on alice.example.test\n", file_get_contents($this->dir.'/export.sql'));
    }

    public function test_a_link_git_and_node_modules_are_never_written_through(): void
    {
        $outside = $this->dir.'-outside';
        file_put_contents($outside, self::CONFIG);
        symlink($outside, $this->dir.'/linked.ini');
        foreach (['.git', 'node_modules'] as $skipped) {
            mkdir($this->dir.'/'.$skipped);
            file_put_contents($this->dir.'/'.$skipped.'/config', self::CONFIG);
        }

        try {
            $this->assertSame(['config/config.ini.php'], $this->rewrite());
            $this->assertSame(self::CONFIG, file_get_contents($outside));
            $this->assertSame(self::CONFIG, file_get_contents($this->dir.'/.git/config'));
            $this->assertSame(self::CONFIG, file_get_contents($this->dir.'/node_modules/config'));
        } finally {
            @unlink($outside);
        }
    }

    public function test_a_missing_checkout_rewrites_nothing(): void
    {
        $process = new Process(CopiedAppFiles::command($this->dir.'/absent', 'Src3tPass', ['-e', 's/a/b/g']));
        $process->mustRun();

        $this->assertSame('', $process->getOutput());
    }

    /** @return list<string> */
    private function rewrite(): array
    {
        $arguments = CopiedAppFiles::sedArguments(CopiedAppFiles::replacements(
            ['database' => 'alice_app', 'username' => 'alice_app', 'password' => 'Src3tPass'],
            ['database' => 'bob_app', 'username' => 'bob_app', 'password' => 'N3wPass'],
            ['alice.example.test', 'bob.example.test'],
        ));
        $process = new Process(CopiedAppFiles::command($this->dir, 'Src3tPass', $arguments));
        $process->mustRun();

        return array_values(array_filter(explode("\n", trim($process->getOutput()))));
    }
}
