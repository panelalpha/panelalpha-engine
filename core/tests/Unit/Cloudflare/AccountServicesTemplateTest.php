<?php

namespace Tests\Unit\Cloudflare;

use PHPUnit\Framework\TestCase;

/**
 * The s6 services an account's container runs, as the DinD project template
 * ships them. Templates live under engine/templates locally; on some hosts
 * they are bind-mounted outside the core tree -- try known roots then skip.
 *
 * @see \App\System\Project\Dind\Services\S6ServiceManager
 */
class AccountServicesTemplateTest extends TestCase
{
    private function projectTemplateDir(): string
    {
        $candidates = [
            dirname(__DIR__, 4) . '/templates/user/dind/project',
            '/opt/panelalpha/shared-hosting/templates/user/dind/project',
            '/var/www/templates/user/dind/project',
        ];
        foreach ($candidates as $dir) {
            if (is_dir($dir . '/services')) {
                return $dir;
            }
        }
        $this->markTestSkipped('DinD project templates not available in this environment');
    }

    public function test_dockerd_and_cron_run_in_the_foreground(): void
    {
        $base = $this->projectTemplateDir() . '/services';

        $this->assertStringContainsString('exec dockerd', (string) file_get_contents($base . '/docker/run'));
        $this->assertStringContainsString('exec cron -f', (string) file_get_contents($base . '/cron/run'));
        foreach (['docker', 'cron', 'cloudflared'] as $service) {
            $this->assertTrue(is_executable("{$base}/{$service}/run"), "{$service}/run is executable");
        }
    }

    /** A new account's connector stays down until a tunnel is attached. */
    public function test_cloudflared_ships_down_and_takes_its_token_from_env(): void
    {
        $base = $this->projectTemplateDir() . '/services/cloudflared';
        $run = (string) file_get_contents($base . '/run');

        $this->assertFileExists($base . '/down');
        $this->assertStringContainsString('exec s6-envdir ./env cloudflared --no-autoupdate tunnel run', $run);
        // In the environment, not argv, so the process list does not show it.
        $this->assertStringNotContainsString('--token', $run);
    }

    public function test_the_account_starts_s6_and_mounts_the_services(): void
    {
        $dir = $this->projectTemplateDir();
        $entrypoint = (string) file_get_contents($dir . '/entrypoint.sh');
        $compose = (string) file_get_contents($dir . '/docker-compose.yml.blade.php');

        $this->assertStringContainsString('exec s6-svscan /run/service', $entrypoint);
        // Versioned with the engine, like engine-core: the image ships s6.
        $this->assertStringContainsString('image: ghcr.io/panelalpha/engine-user-dind:v2.0.2', $compose);
        $this->assertStringContainsString('./services/:/etc/s6/account/:ro', $compose);
        // Docker mounts tmpfs noexec; s6 execs run scripts from the scan dir.
        $this->assertStringContainsString('/run/service:mode=755,size=4m,exec', $compose);
        $this->assertStringNotContainsString('supervisor', $compose);
        $this->assertFileDoesNotExist($dir . '/supervisord.conf');
    }
}
