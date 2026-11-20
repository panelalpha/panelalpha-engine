<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\AppSsoToken;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/**
 * /projects/{project}/app/*: each request runs the app's management script in the account's container, and the
 * script's JSON is the answer; the script's own error, or a stopped app, is a 422 on `app`.
 */
class AppUserHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const COMMANDS = ['users:list', 'users:add', 'users:delete', 'users:reset-password', 'users:sso', 'roles:list', 'install'];

    private ScriptedBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
        $this->makeMainDomain($this->makeUser('shop', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001], 'shop.test'), 'shop.test');
        $this->script('info', json_encode(self::COMMANDS));
        $this->script('users:list', json_encode([['id' => 1, 'login' => 'admin'], ['id' => 2, 'login' => 'editor']]));
    }

    protected function tearDown(): void
    {
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_the_app_info_users_and_roles_are_the_script_answers(): void
    {
        $this->script('roles:list', json_encode(['administrator', 'editor']));

        $this->getJson('/api/projects/shop/app/info')->assertOk()->assertExactJson(['data' => self::COMMANDS]);
        $this->getJson('/api/projects/shop/app/users')->assertOk()->assertJsonPath('data.1.login', 'editor');
        $this->getJson('/api/projects/shop/app/roles')->assertOk()->assertExactJson(['data' => ['administrator', 'editor']]);
    }

    public function test_a_user_is_added_with_the_fields_sent(): void
    {
        $this->script('users:add', json_encode(['id' => 3, 'login' => 'writer']));

        $this->postJson('/api/projects/shop/app/users', ['login' => 'writer', 'email' => 'w@example.com', 'password' => 'long-password', 'role' => 'editor'])
            ->assertCreated()
            ->assertExactJson(['data' => ['id' => 3, 'login' => 'writer']]);

        $this->assertSame(['users:add', 'writer', 'w@example.com', 'long-password', 'editor'], array_slice($this->lastScriptCall(), -5));
    }

    public function test_a_new_user_without_the_required_fields_is_refused(): void
    {
        $this->postJson('/api/projects/shop/app/users', ['login' => 'writer', 'password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'role']);
    }

    public function test_the_scripts_own_error_is_the_refusal(): void
    {
        $this->script('users:add', '', 1, '{"error": "That login is taken."}');

        $this->postJson('/api/projects/shop/app/users', ['login' => 'admin', 'email' => 'a@example.com', 'password' => 'long-password', 'role' => 'editor'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.app.0', 'That login is taken.');
    }

    public function test_a_stopped_app_says_so(): void
    {
        $this->script('roles:list', '', 1, 'service "app" is not running');

        $this->getJson('/api/projects/shop/app/roles')
            ->assertUnprocessable()
            ->assertJsonPath('errors.app.0', 'The application is not running. Deploy or start the project, then try again.');
    }

    public function test_a_user_is_deleted_with_a_reassignment_and_an_unknown_one_is_not_found(): void
    {
        $this->deleteJson('/api/projects/shop/app/users/2?reassign=1')->assertNoContent();
        $this->assertSame(['users:delete', '2', '1'], array_slice($this->lastScriptCall(), -3));

        $this->deleteJson('/api/projects/shop/app/users/99')->assertNotFound();
    }

    public function test_a_password_is_reset_once_it_is_long_enough(): void
    {
        $this->putJson('/api/projects/shop/app/users/2/password', ['password' => 'short'])->assertUnprocessable();

        $this->putJson('/api/projects/shop/app/users/2/password', ['password' => 'new-long-password'])->assertNoContent();
        $this->assertSame(['users:reset-password', '2', 'new-long-password'], array_slice($this->lastScriptCall(), -3));
    }

    public function test_a_login_url_from_the_app_is_handed_on(): void
    {
        $this->script('users:sso', json_encode(['url' => 'https://shop.test/wp-login.php?key=abc']));

        $this->postJson('/api/projects/shop/app/users/1/sso')->assertOk()->assertExactJson(['url' => 'https://shop.test/wp-login.php?key=abc']);
    }

    public function test_a_login_path_from_the_app_is_put_on_the_primary_domain(): void
    {
        $this->script('users:sso', json_encode(['path' => '/admin/login?token=abc']));

        $this->postJson('/api/projects/shop/app/users/1/sso')->assertOk()->assertExactJson(['url' => 'https://shop.test/admin/login?token=abc']);
    }

    public function test_a_session_cookie_from_the_app_is_handed_over_once(): void
    {
        $this->script('users:sso', json_encode(['cookie' => 'app_session', 'value' => 's3cr3t', 'redirect' => '/dashboard']));

        $url = (string) $this->postJson('/api/projects/shop/app/users/1/sso')->assertOk()->json('url');

        $this->assertStringStartsWith('https://shop.test/panelalpha-sso?token=', $url);
        $token = substr($url, strlen('https://shop.test/panelalpha-sso?token='));
        $this->assertSame('app_session', AppSsoToken::query()->where('token', $token)->value('cookie_name'));

        $redeemed = $this->redeem($token);
        $redeemed->assertRedirect('/dashboard');
        $redeemed->assertCookie('app_session', 's3cr3t', false);

        $this->redeem($token)->assertNotFound();
    }

    public function test_an_sso_answer_without_a_way_in_is_refused(): void
    {
        $this->script('users:sso', json_encode(['cookie' => 'app_session']));

        $this->postJson('/api/projects/shop/app/users/1/sso')
            ->assertUnprocessable()
            ->assertJsonPath('errors.app.0', 'Invalid SSO response returned by app');
    }

    public function test_the_app_is_installed_with_the_admin_account(): void
    {
        $this->postJson('/api/projects/shop/app/install', ['url' => 'https://shop.test', 'title' => 'Shop'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_user', 'admin_email', 'admin_password']);

        $this->postJson('/api/projects/shop/app/install', [
            'url' => 'https://shop.test',
            'title' => 'Shop',
            'admin_user' => 'owner',
            'admin_email' => 'owner@example.com',
            'admin_password' => 'owner-password',
        ])->assertNoContent();

        $this->assertSame(['install', 'https://shop.test', 'Shop', 'owner', 'owner@example.com', 'owner-password'], array_slice($this->lastScriptCall(), -6));
    }

    public function test_a_project_that_is_not_a_container_project_is_refused(): void
    {
        $this->makeUser('classic', ['template' => 'default']);

        $this->getJson('/api/projects/classic/app/info')
            ->assertForbidden()
            ->assertExactJson(['message' => 'App info works only on container projects (template dind).']);
        $this->getJson('/api/projects/classic/app/users')
            ->assertForbidden()
            ->assertExactJson(['message' => 'App user management works only on container projects (template dind).']);
    }

    private function script(string $command, string $stdout, int $exit = 0, string $stderr = ''): void
    {
        $this->broker->answer('account-container.exec', $stdout, $exit, $stderr, $command, exactly: true);
    }

    /** @return list<string> */
    private function lastScriptCall(): array
    {
        $calls = $this->broker->valuesOf('account-container.exec');

        return end($calls) ?: [];
    }

    private function redeem(string $token): TestResponse
    {
        return $this->get('/api/projects/shop/app/sso-token?token=' . $token);
    }
}
