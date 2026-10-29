<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\AccountFileCopy;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallDestinationPluginStep;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * The migrator zip is staged under ~/.panelalpha for WP-CLI, which must not
 * open ~/.panelalpha itself to 0755: it holds the account's private files.
 * The account owns ~/.panelalpha, so the staging dir in it is the tenant's
 * to replace with a link; the copy must never follow one.
 */
class InstallDestinationPluginStagingTest extends TestCase
{
    private string $root;

    private string $home;

    private string $account;

    private LocallyRunSystem $system;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/wp-migrator-staging-'.bin2hex(random_bytes(6));
        $this->home = $this->root.'/home/alice';
        mkdir($this->home, 0755, true);
        $this->account = posix_getuid().':'.posix_getgid();
        $this->system = new LocallyRunSystem;
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwx '.escapeshellarg($this->root).' && rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_staging_keeps_panelalpha_private_and_the_accounts(): void
    {
        $staged = $this->step()->stageZip($this->source(), $this->home, $this->account, '7');

        $this->assertSame($this->home.'/.panelalpha/wordpress-migrator/7.zip', $staged);
        $this->assertSame('zip', file_get_contents($staged));
        $this->assertSame('0700', $this->mode($this->home.'/.panelalpha'));
        $this->assertSame('0755', $this->mode($this->home.'/.panelalpha/wordpress-migrator'));
        $this->assertSame('0644', $this->mode($staged));
        foreach ($this->system->callsAs('root') as $argv) {
            $this->assertSame($this->home.'/.panelalpha', $argv[5]);
        }
    }

    public function test_staging_refuses_a_staging_dir_linked_into_another_account(): void
    {
        $victim = $this->root.'/home/bob/public_html';
        mkdir($victim, 0750, true);
        chmod($victim, 0750);
        mkdir($this->home.'/.panelalpha', 0700);
        symlink($victim, $this->home.'/.panelalpha/wordpress-migrator');

        try {
            $this->step()->stageZip($this->source(), $this->home, $this->account, '7');
            $this->fail('Staging through a symlinked directory must be refused.');
        } catch (Exception $e) {
            $this->assertStringContainsString('is a symlink', $e->getMessage());
        }

        $this->assertSame([], array_values(array_diff(scandir($victim), ['.', '..'])));
        $this->assertSame('0750', $this->mode($victim));
    }

    private function step(): InstallDestinationPluginStep
    {
        return new InstallDestinationPluginStep(null, new AccountFileCopy($this->system));
    }

    private function source(): string
    {
        $source = $this->root.'/source.zip';
        file_put_contents($source, 'zip');

        return $source;
    }

    private function mode(string $path): string
    {
        clearstatcache();

        return sprintf('%04o', fileperms($path) & 07777);
    }
}
