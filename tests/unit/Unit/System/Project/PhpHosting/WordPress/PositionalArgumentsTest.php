<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\WordPress;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Closure;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A caller's value never reaches WP-CLI as a positional argument when it
 * starts with '-': WP-CLI 2.8.1 reads it as an option (--exec, --require,
 * --path) wherever it stands, and ignores '--'. This holds for every caller,
 * the artisan commands included, not only the HTTP routes.
 */
class PositionalArgumentsTest extends TestCase
{
    private const OPTION = '--exec=file_put_contents("/tmp/pwned", 1);';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<string, array{Closure(WordPress): mixed}> */
    public static function namedOperations(): array
    {
        $o = self::OPTION;

        return [
            'plugin get' => [static fn (WordPress $wp) => $wp->plugins()->get($o)],
            'plugin is-installed' => [static fn (WordPress $wp) => $wp->plugins()->isInstalled($o)],
            'plugin install' => [static fn (WordPress $wp) => $wp->plugins()->install($o)],
            'plugin activate' => [static fn (WordPress $wp) => $wp->plugins()->activate($o)],
            'plugin deactivate' => [static fn (WordPress $wp) => $wp->plugins()->deactivate($o)],
            'plugin update' => [static fn (WordPress $wp) => $wp->plugins()->update($o)],
            'plugin delete' => [static fn (WordPress $wp) => $wp->plugins()->delete($o)],
            'plugin search' => [static fn (WordPress $wp) => $wp->plugins()->search($o)],
            'theme get' => [static fn (WordPress $wp) => $wp->themes()->get($o)],
            'theme is-installed' => [static fn (WordPress $wp) => $wp->themes()->isInstalled($o)],
            'theme install' => [static fn (WordPress $wp) => $wp->themes()->install($o)],
            'theme activate' => [static fn (WordPress $wp) => $wp->themes()->activate($o)],
            'theme update' => [static fn (WordPress $wp) => $wp->themes()->update($o)],
            'theme delete' => [static fn (WordPress $wp) => $wp->themes()->delete($o)],
            'theme search' => [static fn (WordPress $wp) => $wp->themes()->search($o)],
            'user create, login' => [static fn (WordPress $wp) => $wp->users()->create(['login' => $o, 'email' => 'a@example.test', 'password' => 'secret123'])],
            'user create, email' => [static fn (WordPress $wp) => $wp->users()->create(['login' => 'jdoe', 'email' => '--require=/home/site/x.php@a.test', 'password' => 'secret123'])],
            'user update' => [static fn (WordPress $wp) => $wp->users()->update($o, ['role' => 'editor'])],
            'user delete' => [static fn (WordPress $wp) => $wp->users()->delete($o)],
            'user password' => [static fn (WordPress $wp) => $wp->users()->setPassword($o, 'secret123')],
            'application password list' => [static fn (WordPress $wp) => $wp->applicationPasswords()->list($o)],
            'application password create, user' => [static fn (WordPress $wp) => $wp->applicationPasswords()->create($o, 'ci')],
            'application password create, name' => [static fn (WordPress $wp) => $wp->applicationPasswords()->create('admin', $o)],
            'application password delete, user' => [static fn (WordPress $wp) => $wp->applicationPasswords()->delete($o, '6d0c5d1e-5b4b-4f8a-9f3e-0e1f2a3b4c5d')],
            'application password delete, uuid' => [static fn (WordPress $wp) => $wp->applicationPasswords()->delete('admin', $o)],
            'option get' => [static fn (WordPress $wp) => $wp->options()->get($o)],
            'option set' => [static fn (WordPress $wp) => $wp->options()->set($o, 'x')],
            'config get' => [static fn (WordPress $wp) => $wp->config()->get($o)],
            'config set' => [static fn (WordPress $wp) => $wp->config()->set($o, 'x')],
            'config delete' => [static fn (WordPress $wp) => $wp->config()->delete($o)],
            'language' => [static fn (WordPress $wp) => $wp->language()->set($o)],
            'database export' => [static fn (WordPress $wp) => $wp->database()->export($o)],
            'database import' => [static fn (WordPress $wp) => $wp->database()->import($o)],
        ];
    }

    #[DataProvider('namedOperations')]
    public function test_a_value_starting_with_a_dash_is_refused_before_wp_cli_runs(Closure $operation): void
    {
        $site = new FakeWordPressSite([], []);

        try {
            $operation($site->wordPress());
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertStringContainsString("starts with '-'", $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }

        $this->assertSame([], $site->calls);
    }

    public function test_any_other_value_passes_unchanged(): void
    {
        foreach (['akismet', '/home/alice/staged/wordpress-migrator.zip', 'pl_PL', 'a-b', '1'] as $value) {
            $this->assertSame($value, WpCli::positional($value));
        }
    }
}
