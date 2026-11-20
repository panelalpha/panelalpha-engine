<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Tests\Support\AnubisSourceRecipeFixtures;

/**
 * Anubis's challenge assets come from a Makefile the Go strategy cannot run,
 * so the recipe runs the published image in front of a placeholder site.
 */
class AnubisSourceRecipeTest extends TestCase
{
    use AnubisSourceRecipeFixtures;

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
