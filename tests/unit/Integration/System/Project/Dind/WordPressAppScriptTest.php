<?php

namespace Tests\Integration\System\Project\Dind;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The mu-plugin's users:delete always hands wp_delete_user() a reassignment,
 * the caller's or the first administrator, and refuses when there is nobody.
 */
class WordPressAppScriptTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/pa-appscript-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/wp-content/mu-plugins', 0777, true);
        mkdir($this->tmp.'/wp-admin/includes', 0777, true);
        copy($this->pluginSource(), $this->tmp.'/wp-content/mu-plugins/panelalpha-app.php');
        file_put_contents($this->tmp.'/wp-load.php', self::WP_LOAD);
        file_put_contents($this->tmp.'/wp-admin/includes/user.php', self::USER_INCLUDES);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->tmp]))->run();
        parent::tearDown();
    }

    public function test_an_explicit_reassign_id_is_handed_to_wordpress(): void
    {
        $this->state(admins: [1], users: [1, 7]);

        $process = $this->runScript('users:delete', '7', '1');

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame([7, 1], $this->deleted());
    }

    public function test_a_missing_reassign_id_falls_back_to_the_first_administrator(): void
    {
        $this->state(admins: [5, 6], users: [5, 6, 7]);

        $this->runScript('users:delete', '7');

        $this->assertSame([7, 5], $this->deleted());
    }

    public function test_an_unusable_reassign_id_falls_back_to_an_administrator(): void
    {
        $this->state(admins: [5], users: [5, 7]);

        $this->runScript('users:delete', '7', '99');

        $this->assertSame([7, 5], $this->deleted());
    }

    public function test_the_deleted_user_is_not_left_as_their_own_reassign_target(): void
    {
        $this->state(admins: [5], users: [5, 7]);

        $this->runScript('users:delete', '7', '7');

        $this->assertSame([7, 5], $this->deleted());
    }

    public function test_without_an_administrator_the_delete_is_refused(): void
    {
        $this->state(admins: [], users: [7]);

        $process = $this->runScript('users:delete', '7');

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('reassign', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->tmp.'/deleted.json');
    }

    private const WP_LOAD = <<<'PHP'
        <?php
        define('ABSPATH', __DIR__.'/');
        $GLOBALS['pa_state'] = json_decode((string) file_get_contents(__DIR__.'/state.json'), true) ?: [];
        function is_blog_installed(): bool { return true; }
        function get_users(array $args = []): array
        {
            if (($args['role'] ?? '') !== 'administrator') {
                return [];
            }
            return array_values(array_diff($GLOBALS['pa_state']['admins'] ?? [], $args['exclude'] ?? []));
        }
        function get_user_by(string $field, int $value)
        {
            return in_array($value, $GLOBALS['pa_state']['users'] ?? [], true) ? (object) ['ID' => $value] : false;
        }
        PHP;

    private const USER_INCLUDES = <<<'PHP'
        <?php
        function wp_delete_user(int $id, ?int $reassign = null): bool
        {
            file_put_contents(dirname(__DIR__, 2).'/deleted.json', json_encode([$id, $reassign]));
            return true;
        }
        PHP;

    /**
     * @param  list<int>  $admins
     * @param  list<int>  $users
     */
    private function state(array $admins, array $users): void
    {
        file_put_contents($this->tmp.'/state.json', json_encode(['admins' => $admins, 'users' => $users]));
    }

    /** @return array{int, int|null} */
    private function deleted(): array
    {
        return json_decode((string) file_get_contents($this->tmp.'/deleted.json'), true);
    }

    private function runScript(string ...$args): Process
    {
        $process = new Process([PHP_BINARY, $this->tmp.'/wp-content/mu-plugins/panelalpha-app.php', ...$args]);
        $process->run();

        return $process;
    }

    private function pluginSource(): string
    {
        return dirname(__DIR__, 6).'/core/resources/sources/github.com/wordpress/wordpress/files/wp-content/mu-plugins/panelalpha-app.php';
    }
}
