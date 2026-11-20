<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\System\Services\Webserver;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/**
 * The /projects/{project}/wp/* endpoints on a PHP hosting WordPress site: each request becomes one WP-CLI command run
 * as the account in the site's document root, and WP-CLI's answer, or its failure on the request's own field, is
 * the response.
 */
class WordPressSiteHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const ROOT = '--path=/home/shop/shop.test/public_html';

    private ScriptedBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->broker->answer('compose.serviceContainers', json_encode(['Labels' => 'com.panelalpha.webserver=nginx-proxy']));
        $main = $this->makeMainDomain($this->makeUser('shop', ['template' => 'wordpress', 'UID' => 1001, 'GID' => 1001], 'shop.test'), 'shop.test');
        $main->setDetails(['php_version' => '8.3']);
        $main->save();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_users_are_listed_with_the_role_and_fields_asked_for(): void
    {
        $this->wp(['user', 'list'], json_encode([['ID' => 1, 'user_login' => 'admin']]));

        $this->getJson('/api/projects/shop/wp/users?role=editor&fields=ID,user_login')
            ->assertOk()
            ->assertExactJson(['data' => [['ID' => 1, 'user_login' => 'admin']]]);
        $this->assertSent(['user', 'list', '--format=json', '--role=editor', '--fields=ID,user_login']);
    }

    public function test_a_user_is_created_updated_given_a_password_and_deleted(): void
    {
        $this->postJson('/api/projects/shop/wp/users', ['login' => 'ed', 'email' => 'ed@example.com', 'password' => 'long-password', 'role' => 'editor'])
            ->assertOk()
            ->assertJsonPath('data.user', 'ed');
        $this->assertSent(['user', 'create', 'ed', 'ed@example.com', '--user_pass=long-password', '--role=editor']);

        $this->putJson('/api/projects/shop/wp/users/ed', ['email' => 'ed2@example.com', 'role' => 'author'])->assertOk();
        $this->assertSent(['user', 'update', 'ed', '--user_email=ed2@example.com', '--role=author']);

        $this->putJson('/api/projects/shop/wp/users/ed/password', ['password' => 'long-password-2'])->assertOk();
        $this->assertSent(['user', 'update', 'ed', '--user_pass=long-password-2']);

        $this->deleteJson('/api/projects/shop/wp/users/ed?reassign=admin')->assertOk();
        $this->assertSent(['user', 'delete', 'ed', '--yes', '--reassign=admin']);
    }

    public function test_wp_cli_refusing_a_user_is_a_422_with_its_error(): void
    {
        $this->wp(['user', 'create'], '', 1, 'Error: Sorry, that username already exists!');

        $this->postJson('/api/projects/shop/wp/users', ['login' => 'admin', 'email' => 'a@example.com', 'password' => 'long-password'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Error: Sorry, that username already exists!']);
    }

    public function test_a_single_sign_on_link_is_made_on_the_site_url(): void
    {
        $this->wp(['option', 'get', 'siteurl'], "https://shop.test\n");

        $url = (string) $this->postJson('/api/projects/shop/wp/users/ed/sso')->assertOk()->json('data.url');

        $this->assertStringStartsWith('https://shop.test/', $url);
        $this->assertContains(['user', 'meta', 'update', 'ed', 'panelalpha_sso'], array_map(static fn (array $call): array => array_slice($call, 0, 5), $this->wpCalls()));
    }

    public function test_application_passwords_are_listed_created_and_revoked(): void
    {
        $this->wp(['application-password', 'list'], json_encode([['uuid' => 'u-1', 'name' => 'ci', 'created' => 1]]));
        $this->wp(['application-password', 'create'], "abcd efgh ijkl mnop\n");

        $this->getJson('/api/projects/shop/wp/users/ed/application-passwords')->assertOk()->assertJsonPath('data.0.name', 'ci');
        $this->postJson('/api/projects/shop/wp/users/ed/application-passwords', ['name' => 'deploy'])
            ->assertOk()
            ->assertJsonPath('data.password', 'abcd efgh ijkl mnop');
        $this->assertSent(['user', 'application-password', 'create', 'ed', 'deploy', '--porcelain']);

        $uuid = '6f1c8a3e-2b4d-4c1a-9e7f-0a1b2c3d4e5f';
        $this->deleteJson("/api/projects/shop/wp/users/ed/application-passwords/{$uuid}")->assertOk()->assertJsonPath('data.uuid', $uuid);
        $this->assertSent(['user', 'application-password', 'delete', 'ed', $uuid]);
    }

    public function test_wp_config_is_listed_read_set_and_deleted(): void
    {
        $this->wp(['config', 'list'], json_encode([['name' => 'WP_DEBUG', 'value' => false, 'type' => 'constant']]));
        $this->wp(['config', 'get', 'WP_DEBUG', '--format=json'], 'false');

        $this->getJson('/api/projects/shop/wp/config?type=constant')->assertOk()->assertJsonPath('data.0.name', 'WP_DEBUG');
        $this->getJson('/api/projects/shop/wp/config/WP_DEBUG')->assertOk()->assertJsonPath('data.constant', 'WP_DEBUG');

        $this->putJson('/api/projects/shop/wp/config/WP_DEBUG', ['value' => 'true', 'raw' => true])
            ->assertOk()
            ->assertJsonPath('data.value', 'true');
        $this->assertSent(['config', 'set', 'WP_DEBUG', 'true', '--type=constant', '--raw']);

        $this->deleteJson('/api/projects/shop/wp/config/WP_DEBUG')->assertOk();
        $this->assertSent(['config', 'delete', 'WP_DEBUG', '--type=constant']);
    }

    public function test_wp_config_is_created_with_the_sites_database_host(): void
    {
        $this->postJson('/api/projects/shop/wp/config', ['dbname' => 'shop_wp', 'dbuser' => 'shop_wp', 'dbpass' => 'x', 'force' => true])->assertOk();

        $this->assertContains(
            ['config', 'create', '--dbname=shop_wp', '--dbuser=shop_wp', '--dbpass=x', '--dbhost=database-users.shared-hosting.palocal', '--force', '--exec=umask(0077);', self::ROOT],
            $this->wpCalls(),
        );
    }

    public function test_plugins_are_searched_listed_shown_and_managed(): void
    {
        $this->wp(['plugin', 'search'], json_encode([['slug' => 'wordpress-seo']]));
        $this->wp(['plugin', 'list'], json_encode([['name' => 'akismet', 'status' => 'active']]));
        $this->wp(['plugin', 'get'], json_encode(['name' => 'akismet', 'version' => '5.3']));

        $this->getJson('/api/projects/shop/wp/plugins/search?term=seo&per_page=5')->assertOk()->assertJsonPath('data.0.slug', 'wordpress-seo');
        $this->assertSent(['plugin', 'search', 'seo', '--format=json', '--per-page=5']);
        $this->getJson('/api/projects/shop/wp/plugins?status=active')->assertOk()->assertJsonPath('data.0.name', 'akismet');
        $this->getJson('/api/projects/shop/wp/plugins/akismet')->assertOk()->assertJsonPath('data.version', '5.3');
        $this->getJson('/api/projects/shop/wp/plugins/akismet/installed')->assertOk()->assertJsonPath('data.installed', true);

        foreach (['activate' => 'activate', 'deactivate' => 'deactivate', 'update' => 'update'] as $route => $command) {
            $this->postJson("/api/projects/shop/wp/plugins/akismet/{$route}")->assertOk()->assertJsonPath('data.plugin', 'akismet');
            $this->assertSent(['plugin', $command, 'akismet']);
        }
        $this->deleteJson('/api/projects/shop/wp/plugins/akismet')->assertOk();
        $this->assertSent(['plugin', 'uninstall', 'akismet', '--deactivate']);
    }

    public function test_a_plugin_that_is_not_installed_says_so(): void
    {
        $this->wp(['plugin', 'is-installed'], '', 1);

        $this->getJson('/api/projects/shop/wp/plugins/akismet/installed')->assertOk()->assertJsonPath('data.installed', false);
    }

    public function test_themes_are_searched_listed_shown_and_managed(): void
    {
        $this->wp(['theme', 'search'], json_encode([['slug' => 'astra']]));
        $this->wp(['theme', 'list'], json_encode([['name' => 'astra', 'status' => 'inactive']]));
        $this->wp(['theme', 'get'], json_encode(['name' => 'astra', 'version' => '4.0']));

        $this->getJson('/api/projects/shop/wp/themes/search?term=blog')->assertOk()->assertJsonPath('data.0.slug', 'astra');
        $this->getJson('/api/projects/shop/wp/themes')->assertOk()->assertJsonPath('data.0.name', 'astra');
        $this->getJson('/api/projects/shop/wp/themes/astra')->assertOk()->assertJsonPath('data.version', '4.0');
        $this->getJson('/api/projects/shop/wp/themes/astra/installed')->assertOk()->assertJsonPath('data.installed', true);

        foreach (['activate', 'update'] as $command) {
            $this->postJson("/api/projects/shop/wp/themes/astra/{$command}")->assertOk()->assertJsonPath('data.theme', 'astra');
            $this->assertSent(['theme', $command, 'astra']);
        }
        $this->deleteJson('/api/projects/shop/wp/themes/astra')->assertOk();
        $this->assertSent(['theme', 'delete', 'astra']);
    }

    public function test_core_status_updates_and_install(): void
    {
        $this->wp(['core', 'version'], "6.6.2\n");
        $this->wp(['core', 'check-update'], json_encode([['version' => '6.7', 'update_type' => 'minor']]));

        $this->getJson('/api/projects/shop/wp/core')
            ->assertOk()
            ->assertJsonPath('data.installed', true)
            ->assertJsonPath('data.version', '6.6.2');
        $this->getJson('/api/projects/shop/wp/core/updates')->assertOk()->assertJsonPath('data.0.version', '6.7');

        $this->postJson('/api/projects/shop/wp/core/install', [
            'url' => 'https://shop.test',
            'title' => 'Shop',
            'admin_user' => 'owner',
            'admin_email' => 'o@example.com',
            'admin_password' => 'owner-password',
        ])->assertOk();
        $this->assertContains('--admin_user=owner', $this->wpCalls()[count($this->wpCalls()) - 1]);
    }

    public function test_a_core_update_that_left_the_old_version_is_refused(): void
    {
        $this->wp(['core', 'version'], "6.6.2\n");

        $this->postJson('/api/projects/shop/wp/core/update', ['version' => '6.7'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'WordPress did not install 6.7: the site runs 6.6.2, so nothing was updated.');
        $this->assertContains(['core', 'update', '--version=6.7', self::ROOT], $this->wpCalls());
    }

    public function test_core_install_needs_its_fields(): void
    {
        $this->postJson('/api/projects/shop/wp/core/install', ['url' => 'https://shop.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'admin_user', 'admin_email', 'admin_password']);
    }

    public function test_maintenance_mode_cache_and_language(): void
    {
        $this->getJson('/api/projects/shop/wp/maintenance')->assertOk()->assertJsonPath('data.enabled', true);
        $this->putJson('/api/projects/shop/wp/maintenance', ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertContains(['maintenance-mode', 'activate', self::ROOT], $this->wpCalls());

        $this->postJson('/api/projects/shop/wp/cache/flush')->assertOk()->assertJsonPath('data.page_caches', 'flushed');
        $this->assertContains(['cache', 'flush', self::ROOT], $this->wpCalls());

        $this->putJson('/api/projects/shop/wp/language', ['language' => 'pl_PL'])->assertOk()->assertJsonPath('data.language', 'pl_PL');
        $this->assertSent(['language', 'core', 'install', 'pl_PL', '--activate']);
    }

    public function test_auto_updates_are_read_and_set(): void
    {
        $this->wp(['config', 'get', 'WP_AUTO_UPDATE_CORE'], '"minor"');
        $this->wp(['plugin', 'auto-updates', 'status'], json_encode([['name' => 'akismet', 'status' => 'enabled']]));
        $this->wp(['theme', 'auto-updates', 'status'], json_encode([['name' => 'astra', 'status' => 'disabled']]));

        $this->getJson('/api/projects/shop/wp/auto-update')->assertOk()->assertJsonPath('data.core', 'minor');

        $this->putJson('/api/projects/shop/wp/auto-update', ['core' => 'minor', 'plugins' => 'on'])
            ->assertOk()
            ->assertExactJson(['data' => ['core' => 'minor', 'plugins' => 'on']]);
        $this->assertSent(['plugin', 'auto-updates', 'enable', '--all']);

        $this->putJson('/api/projects/shop/wp/auto-update', [])->assertUnprocessable();
    }

    public function test_options_are_listed_read_and_updated(): void
    {
        $this->wp(['option', 'list'], json_encode([['option_name' => 'blogname', 'option_value' => 'Shop']]));
        $this->wp(['option', 'get', 'blogname'], "Shop\n");

        $this->getJson('/api/projects/shop/wp/options')->assertOk()->assertJsonPath('data.0.option_name', 'blogname');
        $this->getJson('/api/projects/shop/wp/options/blogname')->assertOk()->assertJsonPath('data.value', 'Shop');
        $this->putJson('/api/projects/shop/wp/options/blogname', ['value' => 'New Shop'])->assertOk()->assertJsonPath('data.value', 'New Shop');
        $this->assertSent(['option', 'update', 'blogname', '"New Shop"', '--format=json']);
    }

    public function test_search_replace_runs_over_the_database(): void
    {
        $this->postJson('/api/projects/shop/wp/search-replace', ['search' => 'http://shop.test', 'replacement' => 'https://shop.test', 'args' => ['--dry-run']])
            ->assertOk();

        $this->assertSame(['search-replace', 'http://shop.test', 'https://shop.test'], array_slice($this->lastWp(), 0, 3));
        $this->assertContains('--dry-run', $this->lastWp());
    }

    public function test_a_wp_cli_command_runs_with_its_arguments(): void
    {
        $this->wp(['option', 'get', 'home'], "https://shop.test\n");

        $this->postJson('/api/projects/shop/wp/command', ['args' => ['option', 'get', 'home']])
            ->assertOk()
            ->assertExactJson(['stdout' => "https://shop.test\n", 'stderr' => '', 'exit_code' => 0]);
    }

    /** @param list<string> $args */
    private function wp(array $args, string $stdout, int $exit = 0, string $stderr = ''): void
    {
        $this->broker->answer('account-container.exec', $stdout, $exit, $stderr, $args);
    }

    /** @param list<string> $args */
    private function assertSent(array $args): void
    {
        $this->assertSame([...$args, self::ROOT], $this->lastWp());
    }

    /** @return list<string> */
    private function lastWp(): array
    {
        $calls = $this->wpCalls();

        return end($calls) ?: [];
    }

    /** @return list<list<string>> */
    private function wpCalls(): array
    {
        $calls = [];
        foreach ($this->broker->valuesOf('account-container.exec') as $values) {
            $at = array_search('/opt/wp-cli.phar', $values, true);
            if ($at !== false) {
                $calls[] = array_slice($values, $at + 2);
            }
        }

        return $calls;
    }
}
