<?php

namespace Tests\Integration\System\Project\PhpHosting\WordPress;

use App\System\Project\PhpHosting\WordPress\ContainerMustUsePlugin;
use PHPUnit\Framework\TestCase;
use Tests\Support\SsoFixtures;

/**
 * Contract for SSO: MU plugin ensured before the URL is returned, resource
 * body shape, resolved --path on WP-CLI, and non-zero exit as Exception.
 */
class SsoTest extends TestCase
{
    use SsoFixtures;

    public function test_the_container_plugin_code_writes_the_file_only_when_it_differs(): void
    {
        $dir = sys_get_temp_dir().'/pa-mu-'.bin2hex(random_bytes(4));
        $code = ContainerMustUsePlugin::writeCode("<?php // plugin\n");
        $run = static function () use ($dir, $code): void {
            if (! defined('WPMU_PLUGIN_DIR')) {
                define('WPMU_PLUGIN_DIR', $dir);
            }
            eval($code);
        };

        try {
            mkdir($dir);
            $run();
            $this->assertSame("<?php // plugin\n", file_get_contents($dir.'/panelalpha-app.php'));
            touch($dir.'/panelalpha-app.php', 1000);
            clearstatcache();
            $run();
            clearstatcache();
            $this->assertSame(1000, filemtime($dir.'/panelalpha-app.php'), 'same contents: not rewritten');
        } finally {
            @unlink($dir.'/panelalpha-app.php');
            @rmdir($dir);
        }
    }
}
