<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Tests\Support\OverleafSourceRecipeFixtures;

/**
 * Overleaf pins mongo:8.0, which refuses to start on Linux 6.19+ (SERVER-121912),
 * and Overleaf 6.x refuses MongoDB below 8.0, so the recipe runs mongo 8.2.
 */
class OverleafSourceRecipeTest extends TestCase
{
    use OverleafSourceRecipeFixtures;

    /**
     * The image refuses to start without the invite secret, a new one breaks
     * every invite, and a new session secret logs everyone out.
     */
    public function test_the_secrets_are_generated_once(): void
    {
        $home = sys_get_temp_dir() . '/pa-overleaf-' . bin2hex(random_bytes(4));
        mkdir($home);

        $seen = [$this->prepare($home), $this->prepare($home)];
        exec('rm -rf ' . escapeshellarg($home));

        $this->assertMatchesRegularExpression('/^OVERLEAF_INVITE_TOKEN_SECRET=[0-9a-f]{64}$/m', $seen[0]);
        $this->assertMatchesRegularExpression('/^OVERLEAF_SESSION_SECRET=[0-9a-f]{64}$/m', $seen[0]);
        $this->assertSame($seen[0], $seen[1]);
    }

    /** An account deployed before the session secret keeps its invite secret and gains the other. */
    public function test_an_existing_invite_secret_is_kept_and_the_session_secret_added(): void
    {
        $home = sys_get_temp_dir() . '/pa-overleaf-' . bin2hex(random_bytes(4));
        mkdir($home . '/.panelalpha/overleaf', 0700, true);
        $invite = 'OVERLEAF_INVITE_TOKEN_SECRET=' . str_repeat('a', 64) . "\n";
        file_put_contents($home . '/.panelalpha/overleaf/app.env', $invite);

        $env = $this->prepare($home);
        exec('rm -rf ' . escapeshellarg($home));

        $this->assertStringStartsWith($invite, $env);
        $this->assertMatchesRegularExpression('/^OVERLEAF_SESSION_SECRET=[0-9a-f]{64}$/m', $env);
    }

    private function prepare(string $home): string
    {
        $hook = SourceRecipes::directoryFor(self::URL) . '/hooks/prepare.sh';
        exec('HOME=' . escapeshellarg($home) . ' bash ' . escapeshellarg($hook) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));

        return (string) file_get_contents($home . '/.panelalpha/overleaf/app.env');
    }
}
