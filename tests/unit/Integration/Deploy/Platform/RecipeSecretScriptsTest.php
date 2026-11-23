<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * Recipe scripts that hand an app the login or the secrets the engine reports, run for real under a scratch HOME.
 */
class RecipeSecretScriptsTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/pa-recipe-secrets-'.bin2hex(random_bytes(6));
        mkdir($this->home, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->home));
    }

    public function test_timetagger_signs_the_engines_login_in_alone(): void
    {
        $this->assertSame('admin:$2b$12$enginehash', $this->timetaggerCredentials(''));
    }

    public function test_timetagger_keeps_a_projects_own_pairs_after_the_engines_login(): void
    {
        $this->assertSame(
            'admin:$2b$12$enginehash,alice:$2a$08$alicehash',
            $this->timetaggerCredentials('alice:$2a$08$alicehash')
        );
    }

    public function test_abc_user_feedback_generates_one_database_password_for_both_files(): void
    {
        $this->runAbcHook();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $this->abcDbPassword());
        $this->assertSame($this->abcDbPassword(), $this->abcApiPassword());
    }

    public function test_abc_user_feedback_keeps_the_database_password_when_api_env_is_gone(): void
    {
        $this->runAbcHook();
        $password = $this->abcDbPassword();
        unlink($this->abcStore().'/api.env');

        $this->runAbcHook();

        $this->assertSame($password, $this->abcDbPassword());
        $this->assertSame($password, $this->abcApiPassword());
    }

    public function test_abc_user_feedback_keeps_the_database_password_when_db_env_is_gone(): void
    {
        $this->runAbcHook();
        $password = $this->abcDbPassword();
        $api = (string) file_get_contents($this->abcStore().'/api.env');
        unlink($this->abcStore().'/db.env');

        $this->runAbcHook();

        $this->assertSame($password, $this->abcDbPassword());
        $this->assertSame($api, file_get_contents($this->abcStore().'/api.env'));
    }

    private function timetaggerCredentials(string $own): string
    {
        $bin = $this->home.'/bin';
        mkdir($bin);
        file_put_contents($bin.'/python', "#!/bin/sh\n"
            ."if [ \"\$1\" = \"-c\" ]; then echo '\$2b\$12\$enginehash'; exit 0; fi\n"
            ."printf '%s' \"\$TIMETAGGER_CREDENTIALS\" > \"\$OUT\"\n");
        chmod($bin.'/python', 0755);
        $out = $this->home.'/credentials';
        $script = SourceRecipes::defaultDirectory().'/github.com/almarklein/timetagger/files/timetagger-start.sh';

        exec('env -i PATH='.escapeshellarg($bin.':/usr/bin:/bin').' OUT='.escapeshellarg($out)
            .' TIMETAGGER_USERNAME=admin TIMETAGGER_PASSWORD=pw TIMETAGGER_CREDENTIALS='.escapeshellarg($own)
            .' sh '.escapeshellarg($script).' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));

        return (string) file_get_contents($out);
    }

    private function runAbcHook(): void
    {
        @mkdir($this->home.'/project/panelalpha/abc-user-feedback', 0777, true);
        $hook = SourceRecipes::defaultDirectory().'/github.com/line/abc-user-feedback/hooks/prepare.sh';

        exec('HOME='.escapeshellarg($this->home).' bash '.escapeshellarg($hook).' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    private function abcStore(): string
    {
        return $this->home.'/.panelalpha/abc-user-feedback';
    }

    private function abcDbPassword(): string
    {
        preg_match('/^MYSQL_PASSWORD=(.*)$/m', (string) file_get_contents($this->abcStore().'/db.env'), $m);

        return $m[1] ?? '';
    }

    private function abcApiPassword(): string
    {
        preg_match('#^MYSQL_PRIMARY_URL=mysql://userfeedback:([^@]*)@#m', (string) file_get_contents($this->abcStore().'/api.env'), $m);

        return $m[1] ?? '';
    }
}
