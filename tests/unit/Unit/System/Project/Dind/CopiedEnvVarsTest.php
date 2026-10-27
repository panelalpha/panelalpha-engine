<?php

namespace Tests\Unit\System\Project\Dind;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind\Strategy\ManifestDatabase;
use Illuminate\Support\Facades\Facade;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * A staging, clone or push copies the env files the source's env vars were
 * written into. The copy stores those env vars too, so its next rebuild writes
 * the same files instead of dropping or reverting the values.
 */
class CopiedEnvVarsTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'env.USERS_DB_HOST' => 'localhost',
        ]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
        $this->root = sys_get_temp_dir().'/pa-copied-env-vars-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/home/stg/project', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_copy_stores_the_sources_env_vars_pointed_at_itself(): void
    {
        [$live, $stg] = $this->pair();
        $liveDatabase = ManifestDatabase::environment((array) AppDatabase::credentialsOf($live))['DB_DATABASE'];
        $live->setDetails([
            'env_vars' => [
                'FOO' => 'one',
                'APP_URL' => 'https://live.example.test/shop',
                'DB_DATABASE' => $liveDatabase,
                'PANELALPHA_ENGINE_TOKEN' => 'the-sources-token',
            ],
            'used_custom_env_vars' => true,
            'env_override_keys' => ['FOO', 'APP_URL', 'DB_DATABASE'],
        ]);
        $live->save();

        $this->project($stg)->adoptCopiedEnvVars($live);

        $stg = User::findByUsernameOrFail('stg');
        $this->assertSame(
            ['FOO' => 'one', 'APP_URL' => 'https://stg.example.test/shop', 'DB_DATABASE' => 'stg_app'],
            $stg->getEnvVars()
        );
        $this->assertTrue($stg->getDetails()['used_custom_env_vars']);
        $this->assertSame(['FOO', 'APP_URL', 'DB_DATABASE'], $stg->getDetails()['env_override_keys']);
        $this->assertStringStartsWith('laravel-encrypted:v1:', json_decode($stg->getAttributes()['details'], true)['env_vars']);
    }

    /**
     * A staging copy, a clone and a push target all adopt the source's env vars
     * here: a value a vault reference set on the source stays masked on the copy,
     * and a target's own stale list is replaced.
     */
    public function test_a_value_a_vault_reference_set_stays_masked_on_the_copy(): void
    {
        [$live, $stg] = $this->pair();
        $live->setDetails(['env_vars' => ['TOKEN' => 'vault-secret', 'PLAIN' => 'one'], User::DETAIL_VAULT_ENV_KEYS => ['TOKEN']]);
        $live->save();
        $stg->setDetails(['env_vars' => ['OLD' => 'two'], User::DETAIL_VAULT_ENV_KEYS => ['OLD']]);
        $stg->save();

        $this->project($stg)->adoptCopiedEnvVars($live);

        $this->assertSame(['TOKEN'], User::findByUsernameOrFail('stg')->getVaultEnvKeys());
        $this->withoutMiddleware(Authenticate::class);
        $response = $this->getJson('/api/projects/stg/env-vars?reveal=1');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame([
            ['key' => 'TOKEN', 'value' => null, 'masked' => true],
            ['key' => 'PLAIN', 'value' => 'one', 'masked' => false],
        ], $response->json('data.vars'));
    }

    public function test_a_source_without_env_vars_leaves_the_copy_without_any(): void
    {
        [$live, $stg] = $this->pair();
        $stg->setDetails(['env_vars' => ['OLD' => 'two'], 'env_override_keys' => ['OLD'], 'used_custom_env_vars' => true]);
        $stg->save();

        $this->project($stg)->adoptCopiedEnvVars($live);

        $stg = User::findByUsernameOrFail('stg');
        $this->assertSame([], $stg->getEnvVars());
        $this->assertNull($stg->getDetails()['env_override_keys']);
        $this->assertNull($stg->getDetails()['used_custom_env_vars']);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pair(): array
    {
        $live = $this->makeUser('live', ['template' => 'dind', 'mysql_prefix' => 'live_', 'UID' => 1001, 'GID' => 1001], 'live.example.test');
        $stg = $this->makeUser('stg', ['template' => 'dind', 'mysql_prefix' => 'stg_', 'UID' => 1002, 'GID' => 1002], 'stg.example.test');
        $this->makeMainDomain($live, 'live.example.test');
        $this->makeMainDomain($stg, 'stg.example.test');
        $live->setDetails([AppDatabase::PASSWORD_DETAIL => 'live-secret']);
        $live->save();
        $stg->setDetails([AppDatabase::PASSWORD_DETAIL => 'stg-secret']);
        $stg->save();

        return [$live->refresh(), $stg->refresh()];
    }

    private function project(User $user): ProjectAggregate
    {
        return new ProjectAggregate(new LocalHostSystem($this->root, $this->root.'/home'), $user);
    }
}
