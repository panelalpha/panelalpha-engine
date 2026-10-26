<?php

namespace Tests\Unit\WordpressMigration;

use App\System;
use App\System\Project\PhpHosting\WordPress\Migration\AccountFileCopy;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallDestinationPluginStep;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * The migrator zip is staged under ~/.panelalpha for WP-CLI, which must not
 * open ~/.panelalpha itself to 0755: it holds the account's private files.
 */
class InstallDestinationPluginStagingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_staging_keeps_panelalpha_private_and_the_accounts(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'wp-migrator-src-');
        file_put_contents($source, 'zip');

        $calls = [];
        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')->andReturnUsing(function (array $cmd) use (&$calls): string {
            $calls[] = $cmd;

            return '';
        });

        try {
            $staged = (new InstallDestinationPluginStep(null, new AccountFileCopy($system)))
                ->stageZip($source, '/home/alice', '1001:1001', '7');
        } finally {
            @unlink($source);
        }

        $onPanelalpha = array_values(array_filter(
            $calls,
            static fn (array $cmd): bool => in_array($cmd[1], ['chmod', 'chown'], true)
                && end($cmd) === '/home/alice/.panelalpha'
        ));
        // Owned by the account so WP-CLI can pass through it, and never anything but 0700.
        $this->assertSame([
            ['sudo', 'chown', '1001:1001', '--', '/home/alice/.panelalpha'],
            ['sudo', 'chmod', '0700', '--', '/home/alice/.panelalpha'],
        ], $onPanelalpha);

        $this->assertSame('/home/alice/.panelalpha/wordpress-migrator/7.zip', $staged);
        $this->assertContains(['sudo', 'cp', '--no-dereference', '--', $source, $staged], $calls);
    }
}
