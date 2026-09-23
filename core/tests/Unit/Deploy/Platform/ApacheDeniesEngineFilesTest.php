<?php

namespace Tests\Unit\Deploy\Platform;

use PHPUnit\Framework\TestCase;

/**
 * ProjeQtOr is flat PHP served from the repository root, so the engine's own
 * generated files sit in the document root beside index.php. Fetched over the
 * public URL, `/docker-compose.yml` returned 200 with its real contents --
 * the base image, the account uid, the published ports and the name of every
 * environment variable the project was given -- and `/panelalpha-entrypoint.sh`
 * likewise. The dotfile rules beside them were doing their job on `.git` and
 * `.env`; nothing covered these two.
 *
 * nginx-site.stub has denied them since it was written. This is the same rule
 * for the Apache path.
 *
 * The rule has since been wrong in both directions at once, which is why it is
 * now a prefix evaluated against files that exist rather than a flat denial of
 * a request path:
 *
 *   too narrow  `docker-compose\.ya?ml` never matched the engine's own
 *               `docker-compose.override.yml`, which was served 200.
 *   too broad   <FilesMatch> matches the last segment of the *request*, so
 *               under a front controller a customer page whose slug began
 *               "panelalpha-" was 403 to everyone including its author.
 */
class ApacheDeniesEngineFilesTest extends TestCase
{
    private function stub(): string
    {
        $path = __DIR__ . '/../../../../resources/deploy/templates/apache-vhost.stub';
        $contents = file_get_contents($path);
        $this->assertIsString($contents, 'apache-vhost.stub is unreadable');

        return $contents;
    }

    /** The one <FilesMatch> that carries the engine-file rule. */
    private function pattern(): string
    {
        $stub = $this->stub();
        $this->assertSame(
            1,
            preg_match('/<FilesMatch "(\^\(\?:docker-compose[^"]*)">/', $stub, $matches),
            'the engine-file deny rule is not in the stub'
        );

        return $matches[1];
    }

    /**
     * @return list<string> the engine's own artefacts, as they land in a
     *                      document root
     */
    private static function engineFiles(): array
    {
        return [
            'docker-compose.yml',
            'docker-compose.yaml',
            // AppConfigBootstrap::writeCompose(), from a recipe's overrides/.
            'docker-compose.override.yml',
            // ComposeFileInspector::COMPOSE_STASH_SUFFIX.
            'docker-compose.yml.panelalpha-local',
            'compose.yaml',
            'panelalpha.yaml',
            'panelalpha-entrypoint.sh',
            'panelalpha.nginx.conf',
            'panelalpha.serve.mjs',
        ];
    }

    public function test_every_engine_written_file_matches(): void
    {
        $pattern = '/' . str_replace('/', '\/', $this->pattern()) . '/';

        foreach (self::engineFiles() as $name) {
            $this->assertSame(1, preg_match($pattern, $name), "{$name} is served to anyone who asks");
        }
    }

    /**
     * The bug the -f guard exists for. These are request paths under a front
     * controller, not files -- Concrete CMS published a page at
     * /panelalpha-verification and it was 403 to its own author.
     */
    public function test_a_customer_page_is_only_spared_by_the_file_test(): void
    {
        $pattern = '/' . str_replace('/', '\/', $this->pattern()) . '/';

        // The pattern does match them -- that is exactly why the denial has to
        // be conditional on the path being a real file rather than flat.
        $this->assertSame(1, preg_match($pattern, 'panelalpha-verification'));
        $this->assertSame(1, preg_match($pattern, 'panelalpha.foo'));

        $this->assertMatchesRegularExpression(
            '/Require expr !-f %\{REQUEST_FILENAME\}/',
            $this->stub(),
            'the deny rule 403s virtual paths under a front controller'
        );
    }

    /** A slug that merely starts with the word is not the engine's. */
    public function test_the_separator_is_load_bearing(): void
    {
        $pattern = '/' . str_replace('/', '\/', $this->pattern()) . '/';

        $this->assertSame(0, preg_match($pattern, 'panelalphaxyz'));
        $this->assertSame(0, preg_match($pattern, 'index.php'));
        $this->assertSame(0, preg_match($pattern, 'composer.json'));
    }

    /** The deny has to actually deny, not merely match. */
    public function test_the_match_carries_a_denial(): void
    {
        $stub = $this->stub();
        $offset = strpos($stub, '<FilesMatch "^(?:docker-compose');
        $this->assertIsInt($offset);

        $block = substr($stub, $offset, 200);
        $this->assertStringContainsString('Require expr', $block);
    }

    /** The dotfile rules that were already working must survive. */
    public function test_dotfiles_are_still_denied_and_acme_still_reachable(): void
    {
        $stub = $this->stub();

        $this->assertStringContainsString('<FilesMatch "^\.(?!well-known)">', $stub);
        $this->assertStringContainsString('<DirectoryMatch "/\.(?!well-known)">', $stub);
    }

    /**
     * Apache's default is Off, which 404s any path containing %2F before
     * mod_rewrite sees it -- in tine, one broken image on every page. Only
     * valid in server or vhost context, so no recipe can supply it.
     */
    public function test_encoded_slashes_reach_the_application(): void
    {
        $stub = $this->stub();

        $this->assertStringContainsString('AllowEncodedSlashes NoDecode', $stub);

        $vhost = strpos($stub, '<VirtualHost');
        $directive = strpos($stub, 'AllowEncodedSlashes');
        $this->assertIsInt($vhost);
        $this->assertIsInt($directive);
        $this->assertGreaterThan($vhost, $directive, 'the directive is only valid inside the vhost');
    }
}
