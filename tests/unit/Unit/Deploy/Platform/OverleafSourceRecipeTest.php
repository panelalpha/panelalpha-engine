<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Overleaf pins mongo:8.0, which refuses to start on Linux 6.19+ (SERVER-121912),
 * and Overleaf 6.x refuses MongoDB below 8.0, so the recipe runs mongo 8.2.
 */
class OverleafSourceRecipeTest extends TestCase
{
    private const URL = 'https://github.com/overleaf/overleaf';

    public function test_the_recipe_replaces_the_compose_with_mongo_8_2_as_a_replica_set(): void
    {
        $dir = SourceRecipes::directoryFor(self::URL);
        $this->assertNotNull($dir);
        $config = AppConfigDirectory::read(new LocalAppConfigSource(), $dir, true);
        $this->assertNotNull($config);
        $compose = Yaml::parse((string) $config->replacingCompose());

        $mongo = $compose['services']['mongo'];
        $this->assertStringStartsWith('mongo:8.2.12@sha256:', $mongo['image']);
        $this->assertSame(['--replSet', 'overleaf'], array_slice($mongo['command'], 0, 2));
        $this->assertStringStartsWith('./bin/shared/mongodb-init-replica-set.js:', $mongo['volumes'][1]);
        $this->assertStringStartsWith('sharelatex/sharelatex:6.3.0@sha256:', $compose['services']['sharelatex']['image']);
        $this->assertSame('${PA_PUBLIC_URL}', $compose['services']['sharelatex']['environment']['OVERLEAF_SITE_URL']);
    }

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
