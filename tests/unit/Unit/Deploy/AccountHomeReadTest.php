<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * Detection and inspection read a checkout as www-data, which can open every
 * world-readable file on the host. A link the tenant planted must not make
 * them read another account's file, whatever opens it or when it was planted.
 */
class AccountHomeReadTest extends TestCase
{
    private string $root;

    private string $homes;

    private string $alice;

    private string $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-home-read-'.bin2hex(random_bytes(4));
        $this->homes = $this->root.'/home';
        $this->alice = $this->homes.'/alice/project';
        $this->bob = $this->homes.'/bob/project';
        mkdir($this->alice, 0777, true);
        mkdir($this->bob, 0777, true);
        file_put_contents($this->bob.'/.env', "BOB_DB_PASSWORD=bob-secret\n");
        file_put_contents($this->bob.'/package.json', '{"dependencies":{"bob-only":"1"}}');
        AccountHomeRead::useHomesDir($this->homes);
    }

    protected function tearDown(): void
    {
        AccountHomeRead::useHomesDir(null);
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_file_in_its_own_home_is_read(): void
    {
        file_put_contents($this->alice.'/.env', "A=1\n");

        $this->assertSame("A=1\n", AccountHomeRead::contents($this->alice.'/.env'));
        $this->assertSame('A=', AccountHomeRead::contents($this->alice.'/.env', 2));
    }

    public function test_a_link_that_stays_in_the_same_home_is_read(): void
    {
        mkdir($this->homes.'/alice/shared');
        file_put_contents($this->homes.'/alice/shared/compose.yml', "services: {}\n");
        symlink('../shared/compose.yml', $this->alice.'/docker-compose.yml');

        $this->assertSame("services: {}\n", AccountHomeRead::contents($this->alice.'/docker-compose.yml'));
    }

    public function test_a_link_to_another_accounts_file_is_not_read(): void
    {
        symlink($this->bob.'/.env', $this->alice.'/.env');

        $this->assertFalse(AccountHomeRead::contents($this->alice.'/.env'));
    }

    public function test_a_file_below_a_directory_linked_into_another_home_is_not_read(): void
    {
        symlink($this->bob, $this->alice.'/server');

        $this->assertFalse(AccountHomeRead::contents($this->alice.'/server/.env'));
    }

    public function test_a_dot_dot_into_another_home_is_not_read(): void
    {
        $this->assertFalse(AccountHomeRead::contents($this->alice.'/../../bob/project/.env'));
    }

    public function test_a_link_out_of_every_home_is_not_read(): void
    {
        file_put_contents($this->root.'/core-secret', 'APP_KEY=core');
        symlink($this->root.'/core-secret', $this->alice.'/package.json');
        symlink('/dev/zero', $this->alice.'/composer.lock');

        $this->assertFalse(AccountHomeRead::contents($this->alice.'/package.json'));
        $this->assertFalse(AccountHomeRead::contents($this->alice.'/composer.lock'));
    }

    /** An inspection clone lives outside every home; a link in it must not reach into one. */
    public function test_a_checkout_outside_the_homes_does_not_read_into_one(): void
    {
        mkdir($this->root.'/clone');
        file_put_contents($this->root.'/clone/README.md', 'readme');
        symlink($this->bob.'/.env', $this->root.'/clone/.env');

        $this->assertFalse(AccountHomeRead::contents($this->root.'/clone/.env'));
        $this->assertSame('readme', AccountHomeRead::contents($this->root.'/clone/README.md'));
    }

    public function test_a_link_out_of_the_checkout_being_inspected_is_not_read(): void
    {
        $clone = $this->cloneBesideCoreEnv();
        symlink($this->root.'/core/.env', $clone.'/.env.example');

        $this->assertFalse(AccountHomeRead::confinedTo($clone, static fn () => AccountHomeRead::contents($clone.'/.env.example')));
    }

    public function test_a_link_inside_the_checkout_being_inspected_is_read(): void
    {
        $clone = $this->cloneBesideCoreEnv();
        mkdir($clone.'/config');
        file_put_contents($clone.'/config/defaults.env', "APP_NAME=clone\n");
        symlink('config/defaults.env', $clone.'/.env.example');

        $this->assertSame("APP_NAME=clone\n", AccountHomeRead::confinedTo($clone, static fn () => AccountHomeRead::contents($clone.'/.env.example')));
    }

    /** Every link names a path inside the checkout; only the kernel sees the last one land outside it. */
    public function test_links_that_resolve_out_of_the_checkout_through_each_other_are_not_read(): void
    {
        $clone = $this->cloneBesideCoreEnv();
        symlink('.', $clone.'/here');
        symlink('here/..', $clone.'/up');
        symlink('up/core/.env', $clone.'/.env.example');

        $this->assertFalse(AccountHomeRead::confinedTo($clone, static fn () => AccountHomeRead::contents($clone.'/.env.example')));
    }

    /** As the deploy reads it: a project's own link elsewhere in its home is the tenant's own file. */
    public function test_a_checkout_in_a_home_is_held_to_the_home(): void
    {
        mkdir($this->homes.'/alice/shared');
        file_put_contents($this->homes.'/alice/shared/compose.yml', "services: {}\n");
        symlink('../shared/compose.yml', $this->alice.'/docker-compose.yml');
        symlink($this->bob.'/.env', $this->alice.'/.env');

        $read = AccountHomeRead::confinedTo($this->alice, fn (): array => [
            AccountHomeRead::contents($this->alice.'/docker-compose.yml'),
            AccountHomeRead::contents($this->alice.'/.env'),
        ]);

        $this->assertSame(["services: {}\n", false], $read);
    }

    public function test_inspection_does_not_report_keys_from_a_file_linked_out_of_the_checkout(): void
    {
        $clone = $this->cloneBesideCoreEnv();
        file_put_contents($clone.'/index.html', '<h1>clone</h1>');
        symlink($this->root.'/core/.env', $clone.'/.env.example');

        $report = AppInspector::inspect($clone);

        $this->assertNotContains('APP_KEY', $report['environment']['variables']);
    }

    public function test_inspection_reports_keys_from_a_file_linked_inside_the_checkout(): void
    {
        $clone = $this->cloneBesideCoreEnv();
        file_put_contents($clone.'/index.html', '<h1>clone</h1>');
        mkdir($clone.'/config');
        file_put_contents($clone.'/config/defaults.env', "APP_NAME=clone\n");
        symlink('config/defaults.env', $clone.'/.env.example');

        $report = AppInspector::inspect($clone);

        $this->assertContains('APP_NAME', $report['environment']['variables']);
    }

    public function test_a_missing_file_is_false(): void
    {
        $this->assertFalse(AccountHomeRead::contents($this->alice.'/absent'));
    }

    /** Laravel turns a notice into an exception; file_get_contents() of a directory raised none either. */
    public function test_a_directory_is_false_without_a_notice(): void
    {
        mkdir($this->alice.'/composer.json');
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });
        try {
            $this->assertFalse(AccountHomeRead::contents($this->alice.'/composer.json'));
            $this->assertFalse(AccountHomeRead::contents($this->alice));
        } finally {
            restore_error_handler();
        }
    }

    public function test_inspection_does_not_report_another_accounts_env_keys(): void
    {
        file_put_contents($this->alice.'/index.html', '<h1>alice</h1>');
        symlink($this->bob.'/.env', $this->alice.'/.env');

        $report = AppInspector::inspect($this->alice);

        $this->assertNotContains('BOB_DB_PASSWORD', $report['environment']['variables']);
    }

    public function test_detection_does_not_read_another_accounts_package_json(): void
    {
        symlink($this->bob.'/package.json', $this->alice.'/package.json');

        $context = ProjectContext::at($this->alice);

        $this->assertNull($context->package());
        $this->assertFalse($context->hasDep('bob-only'));
    }

    /** An inspection clone outside every home, next to a file standing in for core's own .env. */
    private function cloneBesideCoreEnv(): string
    {
        mkdir($this->root.'/core');
        file_put_contents($this->root.'/core/.env', "APP_KEY=base64:core-secret\n");
        mkdir($this->root.'/clone');

        return $this->root.'/clone';
    }
}
