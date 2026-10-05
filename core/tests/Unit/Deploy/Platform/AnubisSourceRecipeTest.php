<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Anubis's challenge assets come from a Makefile the Go strategy cannot run,
 * so the recipe runs the published image in front of a placeholder site.
 */
class AnubisSourceRecipeTest extends TestCase
{
    private const URL = 'https://github.com/TecharoHQ/anubis';

    public function test_a_fresh_deploy_protects_the_placeholder_until_target_is_set(): void
    {
        $dir = SourceRecipes::directoryFor(self::URL);
        $this->assertNotNull($dir);
        $config = AppConfigDirectory::read(new LocalAppConfigSource(), $dir, true);
        $this->assertNotNull($config);
        $compose = Yaml::parse((string) $config->replacingCompose());

        $anubis = $compose['services']['anubis'];
        $this->assertStringStartsWith('ghcr.io/techarohq/anubis:v1.27.0@sha256:', $anubis['image']);
        $this->assertSame('${TARGET:-http://backend:80}', $anubis['environment']['TARGET']);
        $this->assertSame('${TARGET_HOST:-}', $anubis['environment']['TARGET_HOST']);
        $this->assertSame(['8923:8923'], $anubis['ports']);
        $this->assertContains('panelalpha/anubis/index.html', array_column($config->files(), 'path'));
    }

    /** A new signing key would invalidate every pass already issued. */
    public function test_the_signing_key_is_generated_once(): void
    {
        $home = sys_get_temp_dir() . '/pa-anubis-' . bin2hex(random_bytes(4));
        mkdir($home);
        $hook = SourceRecipes::directoryFor(self::URL) . '/hooks/prepare.sh';

        $seen = [];
        foreach ([1, 2] as $run) {
            exec('HOME=' . escapeshellarg($home) . ' bash ' . escapeshellarg($hook) . ' 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", $output));
            $seen[] = (string) file_get_contents($home . '/.panelalpha/anubis/key.env');
        }
        exec('rm -rf ' . escapeshellarg($home));

        // anubis wants exactly 64 hex characters: an ed25519 seed.
        $this->assertMatchesRegularExpression('/^ED25519_PRIVATE_KEY_HEX=[0-9a-f]{64}$/m', $seen[0]);
        $this->assertSame($seen[0], $seen[1]);
    }
}
