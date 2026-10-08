<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use PHPUnit\Framework\TestCase;

/**
 * The repository is the document root for a site with no build step, so
 * everything in it is one request away. Mattermost's deploy fell through to
 * the placeholder and published `.git/config`, `.git/HEAD`, `.git/index` and
 * `packed-refs` -- and an index plus the object store is the source.
 *
 * The old rule denied two engine filenames and served the rest.
 */
class DotfilesNotServedTest extends TestCase
{
    public function test_a_dotfile_is_refused_in_both_configs(): void
    {
        foreach (['site' => NginxConfig::site('home.html'), 'spa' => NginxConfig::contents()] as $which => $conf) {
            $this->assertMatchesRegularExpression(
                '/location\s+~\s+\/\\\\\.\s*\{\s*deny all;/',
                $conf,
                "the $which config serves dotfiles"
            );
        }
    }

    /**
     * HTTP-01 validation writes into `.well-known`, which is a dotfile path.
     * A prefix location beats a regex one, so the allow has to be `^~` --
     * spelling it `location /.well-known/` would lose to the deny above.
     */
    public function test_acme_survives_the_deny(): void
    {
        foreach ([NginxConfig::site(null), NginxConfig::contents()] as $conf) {
            $this->assertStringContainsString('location ^~ /.well-known/', $conf);
        }
    }

    /** The engine's own files stay denied; this is an addition, not a swap. */
    public function test_the_engines_files_are_still_denied(): void
    {
        $conf = NginxConfig::site(null);

        $this->assertStringContainsString('docker-compose', $conf);
        $this->assertStringContainsString('panelalpha[-.]', $conf);
    }
}
