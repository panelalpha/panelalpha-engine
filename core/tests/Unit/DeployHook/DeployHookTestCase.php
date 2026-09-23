<?php

namespace Tests\Unit\DeployHook;

use App\Models\DeployHook;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The hook tables, and the users they hang off, on an in-memory sqlite
 * database: the real migrations, run by hand against the test's connection
 * (production is a MySQL this suite must not touch).
 */
abstract class DeployHookTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://203.0.113.10',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        $this->app->forgetInstance('encrypter');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        foreach ([
            '2014_10_12_000000_create_users_table.php',
            '2025_09_29_121922_add_status_to_users_table.php',
            '2026_09_21_000000_create_deploy_hooks_tables.php',
            '2026_09_22_000000_add_pending_delivery_to_deploy_hooks_table.php',
            '2026_09_23_000000_add_diagnostics_to_deploy_hooks_tables.php',
        ] as $migration) {
            (require base_path('database/migrations/' . $migration))->up();
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hook_deliveries');
        Schema::dropIfExists('deploy_hooks');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /**
     * A project whose Deploy-managed checkout tracks `$branch`.
     *
     * @param array<string, mixed> $details
     */
    protected function user(string $branch = 'main', array $details = [], string $username = 'alice'): User
    {
        /** @var User */
        return User::create([
            'username' => $username,
            'domain' => $username . '.example.test',
            'details' => array_merge([
                'template' => 'dind',
                'git_repo' => 'https://github.com/octocat/Hello-World.git',
                'git_branch' => $branch,
            ], $details),
        ]);
    }

    /**
     * A project with no Deploy-managed repository whose `public_html` is a
     * Site Git checkout tracking `$branch`.
     */
    protected function siteGitUser(string $branch = 'main', string $username = 'alice'): User
    {
        return $this->user($branch, [
            'git_repo' => '',
            'git_branch' => null,
            'site_git' => ['public_html' => [
                'repo_url' => 'https://github.com/octocat/Hello-World.git',
                'branch' => $branch,
                'token' => null,
            ]],
        ], $username);
    }

    protected function hook(User $user, string $secret = 'a-shared-secret-of-some-length', string $pathKey = 'project'): DeployHook
    {
        $hook = new DeployHook([
            'user_id' => $user->id,
            'path_key' => $pathKey,
            'public_id' => DeployHook::newPublicId(),
        ]);
        $hook->setSecret($secret);
        $hook->save();

        return $hook;
    }
}
