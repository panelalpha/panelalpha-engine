<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\EnvFile;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DindProjectEnvironmentTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');

        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-env-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        $this->projectDir = $this->homeRoot . '/alice/project';
        mkdir($this->projectDir, 0777, true);
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_apply_writes_env_from_example_and_fills_blank_app_key(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\nAPP_KEY=\nAPP_DEBUG=true\n");

        $model = $this->dindModel();
        $this->dind($model)->applyProjectEnvVars();

        $this->assertFileExists($this->projectDir . '/.env.default');
        $this->assertFileExists($this->projectDir . '/.env');

        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('Demo', $env['APP_NAME']);
        $this->assertNotSame('', $env['APP_KEY']);
        $this->assertStringStartsWith('base64:', $env['APP_KEY']);
        $this->assertFalse($model->usedCustomEnvVars());
    }

    /** LinkAce ships no .env.example: key:generate found no APP_KEY line to fill. */
    public function test_a_laravel_project_without_a_template_gets_an_app_key_line(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'laravel']);
        $this->forcedEnvironment($model, tracked: false)->apply();
        $first = $this->vars((string) file_get_contents($this->projectDir . '/.env'))['APP_KEY'] ?? '';

        $this->assertStringStartsWith('base64:', $first);
        $this->assertSame(32, strlen(base64_decode(substr($first, 7))));

        // A reclone lands here again: the same key, not a new one.
        unlink($this->projectDir . '/.env');
        $this->forcedEnvironment($model, tracked: false)->apply();
        $this->assertSame($first, $this->vars((string) file_get_contents($this->projectDir . '/.env'))['APP_KEY']);
    }

    public function test_a_laravel_template_without_the_line_and_a_blank_untracked_env_get_one(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\n");
        $this->forcedEnvironment($this->dindModel(['deploy_strategy' => 'laravel']), tracked: false)->apply();
        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('Demo', $env['APP_NAME']);
        $this->assertStringStartsWith('base64:', $env['APP_KEY']);

        // An archive's .env left from a deploy before this fix: empty, untracked.
        file_put_contents($this->projectDir . '/.env', "APP_KEY=\n");
        $this->forcedEnvironment($this->dindModel(['deploy_strategy' => 'laravel']), tracked: false)->apply();
        $this->assertSame($env['APP_KEY'], $this->vars((string) file_get_contents($this->projectDir . '/.env'))['APP_KEY']);
    }

    public function test_an_app_key_line_is_left_alone_where_it_is_not_ours(): void
    {
        // A key the project's .env already has.
        file_put_contents($this->projectDir . '/.env', "APP_KEY=base64:mine\n");
        $this->forcedEnvironment($this->dindModel(['deploy_strategy' => 'laravel']), tracked: false)->apply();
        $this->assertSame("APP_KEY=base64:mine\n", file_get_contents($this->projectDir . '/.env'));

        // A .env the repository tracks.
        file_put_contents($this->projectDir . '/.env', "APP_NAME=Tracked\n");
        $this->forcedEnvironment($this->dindModel(['deploy_strategy' => 'laravel']), tracked: true)->apply();
        $this->assertSame("APP_NAME=Tracked\n", file_get_contents($this->projectDir . '/.env'));

        // Not Laravel.
        unlink($this->projectDir . '/.env');
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\n");
        $this->forcedEnvironment($this->dindModel(['deploy_strategy' => 'php']), tracked: false)->apply();
        $this->assertArrayNotHasKey('APP_KEY', $this->vars((string) file_get_contents($this->projectDir . '/.env')));

        // The account's own key wins.
        unlink($this->projectDir . '/.env');
        $model = $this->dindModel(['deploy_strategy' => 'laravel', 'env_vars' => ['APP_KEY' => 'base64:account']]);
        $this->forcedEnvironment($model, tracked: false)->apply();
        $this->assertSame('base64:account', $this->vars((string) file_get_contents($this->projectDir . '/.env'))['APP_KEY']);
    }

    public function test_a_utf16_example_is_read_as_text(): void
    {
        // DumbPad ships .env.example as UTF-16LE with a BOM and CRLF; copied
        // byte for byte, compose refused `.env` on "\x00".
        $utf16 = "\xFF\xFE" . mb_convert_encoding("# DumbPad\r\nPORT=3000\r\n\r\nDUMBPAD_PIN=\r\n", 'UTF-16LE', 'UTF-8');
        file_put_contents($this->projectDir . '/.env.example', $utf16);

        $this->dind($this->dindModel())->applyProjectEnvVars();

        $contents = (string) file_get_contents($this->projectDir . '/.env');
        $this->assertStringNotContainsString("\0", $contents);
        $this->assertSame('3000', $this->vars($contents)['PORT']);
    }

    public function test_apply_comments_out_example_lines_compose_would_refuse(): void
    {
        // saltcorn: a .env.example meant to be `source`d (engine#135).
        file_put_contents(
            $this->projectDir . '/.env.example',
            "unset DATABASE_URL SQLITE_FILEPATH\nexport SALTCORN_SESSION_SECRET='hrh64b45b3'\n"
        );

        $model = $this->dindModel(['env_vars' => ['EXTRA' => '1']]);
        $this->dind($model)->applyProjectEnvVars();

        foreach (['.env.default', '.env'] as $file) {
            $contents = (string) file_get_contents($this->projectDir . '/' . $file);
            $this->assertStringContainsString("# unset DATABASE_URL SQLITE_FILEPATH\n", $contents, $file);
            $this->assertSame([], EnvFile::withoutComposeRejectedLines($contents)[1], $file);
        }
        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('hrh64b45b3', $env['SALTCORN_SESSION_SECRET']);
        $this->assertSame('1', $env['EXTRA']);
    }

    public function test_apply_merges_non_empty_overrides_onto_example_base(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\nAPP_KEY=\n");

        $model = $this->dindModel(['env_vars' => ['APP_NAME' => 'Custom', 'EMPTY' => '']]);
        $this->dind($model)->applyProjectEnvVars();

        $default = $this->vars((string) file_get_contents($this->projectDir . '/.env.default'));
        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));

        $this->assertSame('Demo', $default['APP_NAME']);
        $this->assertSame('Custom', $env['APP_NAME']);
        $this->assertArrayNotHasKey('EMPTY', $env);
        $this->assertTrue($model->usedCustomEnvVars());
    }

    /**
     * An archive project keeps ~/project, and its .env, across redeploys: an
     * env var removed since the last deploy must leave .env too, back to the
     * base value or out entirely, while the ones still set and the file's own
     * lines stay.
     */
    public function test_a_removed_env_var_leaves_an_env_that_survives_redeploys(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\nMODE=base\n");
        $model = $this->dindModel(['env_vars' => ['MODE' => 'custom', 'TOREMOVE' => 'keepme-1', 'KEEPME' => 'stay']]);
        $this->forcedEnvironment($model, tracked: false)->apply();
        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame(['custom', 'keepme-1', 'stay'], [$env['MODE'], $env['TOREMOVE'], $env['KEEPME']]);

        // No reclone: the archive's directory, with an edit the operator made in place.
        file_put_contents($this->projectDir . '/.env', "EDITED=1\n", FILE_APPEND);
        $model->setDetails(['env_vars' => ['KEEPME' => 'stay']]);
        $this->forcedEnvironment($model, tracked: false)->apply();

        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        // Re-merged after the base, so KEEPME moves to the end: order is not the point.
        $this->assertEquals(['APP_NAME' => 'Demo', 'MODE' => 'base', 'KEEPME' => 'stay', 'EDITED' => '1'], $env);
        $default = $this->vars((string) file_get_contents($this->projectDir . '/.env.default'));
        $this->assertSame(['APP_NAME' => 'Demo', 'MODE' => 'base', 'EDITED' => '1'], $default);

        $model->setDetails(['env_vars' => []]);
        $this->forcedEnvironment($model, tracked: false)->apply();
        $this->assertSame(
            ['APP_NAME' => 'Demo', 'MODE' => 'base', 'EDITED' => '1'],
            $this->vars((string) file_get_contents($this->projectDir . '/.env'))
        );
    }

    /**
     * #178: every deploy re-clones ~/project, so the key written from
     * .env.example has to be the same one each time or every session dies.
     */
    public function test_a_redeploy_from_env_example_keeps_the_same_app_key(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\nAPP_KEY=\n");
        $this->dind($this->dindModel())->applyProjectEnvVars();
        $first = $this->vars((string) file_get_contents($this->projectDir . '/.env'))['APP_KEY'];

        $this->reclone("APP_NAME=Demo\nAPP_KEY=\n");
        $this->dind($this->dindModel())->applyProjectEnvVars();
        $second = $this->vars((string) file_get_contents($this->projectDir . '/.env'))['APP_KEY'];

        $this->assertSame($first, $second);
        $this->assertSame(32, strlen((string) base64_decode(substr($first, 7), true)));
        // The value a compose project's APP_KEY placeholder already gets.
        $seed = hash_hmac('sha256', 'compose-placeholders:alice', (string) config('app.key'));
        $this->assertSame(ComposePlaceholders::publishedSecret('APP_KEY', $seed), $first);
    }

    public function test_two_accounts_from_the_same_template_get_different_keys(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_KEY=\n");
        mkdir($this->homeRoot . '/bob/project', 0777, true);
        mkdir($this->tmpRoot . '/users/bob', 0777, true);
        file_put_contents($this->homeRoot . '/bob/project/.env.example', "APP_KEY=\n");

        $this->dind($this->dindModel())->applyProjectEnvVars();
        $this->dind($this->dindModel([], 'bob'))->applyProjectEnvVars();

        $this->assertNotSame(
            $this->vars((string) file_get_contents($this->projectDir . '/.env'))['APP_KEY'],
            $this->vars((string) file_get_contents($this->homeRoot . '/bob/project/.env'))['APP_KEY']
        );
    }

    /** A key set in the panel is the account's answer and outranks the derived one. */
    public function test_an_app_key_set_in_the_panel_still_wins(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_KEY=SomeRandomString\n");
        $mine = 'base64:' . base64_encode(str_repeat('m', 32));

        $this->dind($this->dindModel(['env_vars' => ['APP_KEY' => $mine]]))->applyProjectEnvVars();

        $this->assertSame($mine, $this->vars((string) file_get_contents($this->projectDir . '/.env'))['APP_KEY']);
    }

    /** A .env already in the checkout (a prepare hook wrote it) keeps its key. */
    public function test_an_env_written_before_apply_keeps_its_key(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_KEY=\n");
        file_put_contents($this->projectDir . '/.env', "APP_KEY=base64:kept\n");

        $this->dind($this->dindModel())->applyProjectEnvVars();

        $this->assertSame('base64:kept', $this->vars((string) file_get_contents($this->projectDir . '/.env'))['APP_KEY']);
    }

    /**
     * Plainpad (app_root: server) restores its own key into server/.env in the
     * install stage. That only works while the root .env, the env_file, names
     * no APP_KEY for the container to hold instead.
     */
    public function test_an_app_root_layout_puts_no_app_key_in_the_container_env(): void
    {
        mkdir($this->projectDir . '/server');
        file_put_contents($this->projectDir . '/server/.env.example', "APP_NAME=Plainpad\nAPP_KEY={KEY}\n");
        file_put_contents($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE, "services:\n  app:\n    image: php:8-cli\n    env_file:\n      - .env\n");

        $this->dind($this->dindModel())->applyProjectEnvVars();

        $this->assertSame('', file_get_contents($this->projectDir . '/.env'));
        $this->assertFileExists($this->projectDir . '/server/.env');
    }

    public function test_apply_creates_the_env_file_a_generated_run_file_names(): void
    {
        // A PHP-plain archive: no .env, no .env.example, no compose file of its own.
        // The engine's run file still names `.env`, and compose refuses to start without it.
        file_put_contents(
            $this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE,
            "services:
  app:
    image: php:8-cli
    env_file:
      - .env
"
        );

        $this->dind($this->dindModel())->applyProjectEnvVars();

        $this->assertFileExists($this->projectDir . '/.env');
        $this->assertSame('', file_get_contents($this->projectDir . '/.env'));
    }

    /**
     * #179: a nested .env.example gets the root copy's secrets rule. Plainpad's
     * Laravel API is server/ (its app_root) and its template ships
     * APP_KEY={KEY}; a well-known signing key is replaced the same way.
     */
    public function test_a_nested_env_example_does_not_keep_its_published_secrets(): void
    {
        mkdir($this->projectDir . '/server');
        file_put_contents(
            $this->projectDir . '/server/.env.example',
            "APP_NAME=Plainpad\nAPP_KEY={KEY}\nSECRET_KEY=changeme\nDB_HOST=127.0.0.1\n"
        );

        $dind = $this->dind($this->dindModel());
        $dind->applyProjectEnvVars();

        $env = $this->vars((string) file_get_contents($this->projectDir . '/server/.env'));
        $this->assertStringStartsWith('base64:', $env['APP_KEY']);
        $this->assertSame(32, strlen((string) base64_decode(substr($env['APP_KEY'], 7), true)));
        // Seeded like the root copy (#178), so a redeploy keeps the key.
        $this->assertSame(
            ComposePlaceholders::publishedSecret('APP_KEY', $dind->strategy()->secrets()->for('compose-placeholders')),
            $env['APP_KEY']
        );
        $this->assertNotSame('changeme', $env['SECRET_KEY']);
        $this->assertNotSame('', $env['SECRET_KEY']);
        $this->assertSame('Plainpad', $env['APP_NAME']);
        $this->assertSame('127.0.0.1', $env['DB_HOST']);
    }

    /** A nested file copied from a real .env (not a template) is left exactly as it is. */
    public function test_a_nested_copy_of_a_real_env_is_not_rewritten(): void
    {
        file_put_contents($this->projectDir . '/.env', "APP_KEY=base64:theirs\nSECRET_KEY=changeme\n");
        file_put_contents($this->projectDir . '/package.json', '{"scripts":{"dev":"next dev --env-file=.env.local"}}');

        $this->dind($this->dindModel())->applyProjectEnvVars();

        $this->assertSame(
            "APP_KEY=base64:theirs\nSECRET_KEY=changeme\n",
            file_get_contents($this->projectDir . '/.env.local')
        );
        $this->assertSame("APP_KEY=base64:theirs\nSECRET_KEY=changeme\n", file_get_contents($this->projectDir . '/.env'));
    }

    /**
     * ADR-0001 D3: a tracked `.env` is the client's. The overrides go to
     * `.env.panelalpha` instead, attached only to the service whose own
     * `env_file` already loads `.env` -- not the database sidecar, which
     * never did.
     */
    public function test_tracked_env_stays_untouched_and_overrides_go_to_env_panelalpha(): void
    {
        $envContents = "APP_NAME=Demo\nAPP_KEY=base64:committedbytheclient\n";
        file_put_contents($this->projectDir . '/.env', $envContents);
        file_put_contents($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE, <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env
          db:
            image: mariadb
            env_file: .env.db
        YAML);

        $model = $this->dindModel(['env_vars' => ['APP_NAME' => 'Custom']]);
        $this->forcedEnvironment($model, tracked: true)->apply();

        $this->assertSame($envContents, file_get_contents($this->projectDir . '/.env'));

        $overrides = $this->vars((string) file_get_contents(
            $this->projectDir . '/' . EngineArtifacts::ENV_OVERRIDES
        ));
        $this->assertSame('Custom', $overrides['APP_NAME']);

        $compose = Yaml::parseFile($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
        $this->assertSame(['.env', EngineArtifacts::ENV_OVERRIDES], $compose['services']['app']['env_file']);
        $this->assertSame('.env.db', $compose['services']['db']['env_file']);
        $this->assertTrue($model->usedCustomEnvVars());
    }

    /**
     * The pre-ADR-0001 behaviour: an untracked `.env` (or no git at all) is
     * still merged in place, and no `.env.panelalpha` is created.
     */
    public function test_untracked_env_is_merged_as_before(): void
    {
        file_put_contents($this->projectDir . '/.env', "APP_NAME=Demo\nAPP_KEY=base64:generated\n");
        file_put_contents($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE, <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env
        YAML);

        $model = $this->dindModel(['env_vars' => ['APP_NAME' => 'Custom']]);
        $this->forcedEnvironment($model, tracked: false)->apply();

        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('Custom', $env['APP_NAME']);
        $this->assertFileDoesNotExist($this->projectDir . '/' . EngineArtifacts::ENV_OVERRIDES);

        $compose = Yaml::parseFile($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
        $this->assertSame('.env', $compose['services']['app']['env_file']);
    }

    /**
     * Clearing every env_vars field is itself a redeploy: the next apply()
     * has to remove `.env.panelalpha` and detach it from the run file again,
     * not leave a stale override in force.
     */
    public function test_removing_all_env_vars_removes_env_panelalpha_and_its_env_file_entry(): void
    {
        file_put_contents($this->projectDir . '/.env', "APP_NAME=Demo\n");
        file_put_contents($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE, <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env
        YAML);

        $this->forcedEnvironment(
            $this->dindModel(['env_vars' => ['APP_NAME' => 'Custom']]),
            tracked: true
        )->apply();
        $this->assertFileExists($this->projectDir . '/' . EngineArtifacts::ENV_OVERRIDES);

        $this->forcedEnvironment($this->dindModel(['env_vars' => []]), tracked: true)->apply();

        $this->assertFileDoesNotExist($this->projectDir . '/' . EngineArtifacts::ENV_OVERRIDES);
        $compose = Yaml::parseFile($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
        // detach() drops the entry rather than collapsing a lone survivor back
        // to a bare scalar, so the round trip leaves a one-item list.
        $this->assertSame(['.env'], $compose['services']['app']['env_file']);
    }

    /**
     * kaneo (#144): postgres reads its password from `.env` through
     * `env_file:`, the repo ships no `.env.example`, and an empty `.env`
     * left the database refusing its first start.
     */
    public function test_compose_database_password_read_through_env_file_is_generated_into_env(): void
    {
        file_put_contents($this->projectDir . '/compose.yml', <<<'YAML'
        services:
          postgres:
            image: postgres:16-alpine
            env_file:
              - .env
          kaneo:
            image: ghcr.io/usekaneo/kaneo:latest
            env_file:
              - .env
        YAML);

        $model = $this->dindModel(['deploy_strategy' => 'compose']);
        $dind = $this->dind($model);
        $dind->applyProjectEnvVars();

        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame(
            ComposePlaceholders::generatedSecret(
                'POSTGRES_PASSWORD',
                $dind->strategy()->secrets()->for('compose-placeholders')
            ),
            $env['POSTGRES_PASSWORD']
        );

        // A redeploy over the .env it wrote keeps the same value.
        $this->forcedEnvironment($model, tracked: false)->apply();
        $this->assertSame($env, $this->vars((string) file_get_contents($this->projectDir . '/.env')));
    }

    /** borgwarehouse ships `.env.sample`, and its compose file requires every key in it. */
    public function test_a_env_sample_template_seeds_env_like_env_example(): void
    {
        file_put_contents($this->projectDir . '/docker-compose.yml', <<<'YAML'
        services:
          borgwarehouse:
            image: borgwarehouse/borgwarehouse
            ports:
              - '${WEB_SERVER_PORT:?WEB_SERVER_PORT variable missing}:${WEB_SERVER_PORT}'
            volumes:
              - ${CONFIG_PATH:?CONFIG_PATH variable missing}:/home/borgwarehouse/app/config
        YAML);
        file_put_contents($this->projectDir . '/.env.sample', "WEB_SERVER_PORT=3000\nCONFIG_PATH=./config\n");

        $this->dind($this->dindModel(['deploy_strategy' => 'compose']))->applyProjectEnvVars();

        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('3000', $env['WEB_SERVER_PORT']);
        $this->assertSame('./config', $env['CONFIG_PATH']);
    }

    /** onetimesecret (#140): `${VAR:?}` in an included file's `command:`. */
    public function test_compose_required_variables_in_an_included_file_are_generated_into_an_existing_env(): void
    {
        mkdir($this->projectDir . '/docker/compose', 0777, true);
        file_put_contents($this->projectDir . '/docker-compose.yml', "include:\n  - path: docker/compose/simple.yml\n");
        file_put_contents($this->projectDir . '/docker/compose/simple.yml', <<<'YAML'
        services:
          maindb:
            image: valkey/valkey:8.1
            command: valkey-server --requirepass ${VALKEY_PASSWORD:?VALKEY_PASSWORD must be set}
        YAML);
        file_put_contents($this->projectDir . '/.env', "SMTP_HOST=\n");

        $this->forcedEnvironment($this->dindModel(['deploy_strategy' => 'compose']), tracked: false)->apply();

        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('', $env['SMTP_HOST']);
        $this->assertSame(48, strlen($env['VALKEY_PASSWORD']));
    }

    /**
     * TaskingAI: docker/docker-compose.yml with docker/.env.example beside it.
     * Run from the root, compose only reads the root .env.
     */
    public function test_a_nested_compose_files_env_is_carried_into_the_root_env(): void
    {
        mkdir($this->projectDir . '/docker');
        $compose = $this->projectDir . '/docker/docker-compose.yml';
        file_put_contents($compose, <<<'YAML'
        services:
          api:
            image: taskingai/api
            environment:
              OBJECT_STORAGE_TYPE: ${OBJECT_STORAGE_TYPE}
              POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?}
        YAML);
        file_put_contents($this->projectDir . '/docker/.env.example', "OBJECT_STORAGE_TYPE=local\nPOSTGRES_PASSWORD=\n");

        $this->dind($this->dindModel(['deploy_strategy' => 'compose']))->applyProjectEnvVars($compose);

        $this->assertFileExists($this->projectDir . '/docker/.env');
        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('local', $env['OBJECT_STORAGE_TYPE']);
        $this->assertArrayHasKey('POSTGRES_PASSWORD', $env);
    }

    public function test_the_root_env_wins_over_a_nested_compose_files_env(): void
    {
        mkdir($this->projectDir . '/docker');
        $compose = $this->projectDir . '/docker/docker-compose.yml';
        file_put_contents($compose, "services:\n  api:\n    image: acme/api\n");
        file_put_contents($this->projectDir . '/docker/.env', "MODE=nested\nONLY_NESTED=1\n");

        $model = $this->dindModel(['deploy_strategy' => 'compose', 'env_vars' => ['MODE' => 'mine']]);
        $this->forcedEnvironment($model, tracked: false)->apply($compose);

        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('mine', $env['MODE']);
        $this->assertSame('1', $env['ONLY_NESTED']);
    }

    public function test_a_tracked_env_is_not_given_generated_values(): void
    {
        file_put_contents($this->projectDir . '/docker-compose.yml', "services:\n  db:\n    image: postgres:16\n    env_file: .env\n");
        file_put_contents($this->projectDir . '/.env', "APP_NAME=Demo\n");

        $this->forcedEnvironment($this->dindModel(['deploy_strategy' => 'compose']), tracked: true)->apply();

        $this->assertSame("APP_NAME=Demo\n", file_get_contents($this->projectDir . '/.env'));
    }

    /**
     * #173: `.env.default` copies `.env` after the prepare hook wrote its
     * secrets, and ~/project is traversable by every uid on the host. An
     * earlier deploy's 0644 copy is tightened on the next apply().
     */
    public function test_env_default_is_written_owner_only_and_env_keeps_its_mode(): void
    {
        file_put_contents($this->projectDir . '/.env', "ADMIN_PASS=generated-by-the-hook\n");
        chmod($this->projectDir . '/.env', 0644);
        file_put_contents($this->projectDir . '/.env.default', "stale\n");
        chmod($this->projectDir . '/.env.default', 0644);

        $this->dind($this->dindModel())->applyProjectEnvVars();

        $this->assertSame('0600', $this->mode('.env.default'));
        $this->assertSame("ADMIN_PASS=generated-by-the-hook\n", file_get_contents($this->projectDir . '/.env.default'));
        // The app's own file: containers read it as their own uid.
        $this->assertSame('0644', $this->mode('.env'));
    }

    public function test_env_overrides_are_written_owner_only(): void
    {
        file_put_contents($this->projectDir . '/.env', "APP_NAME=Demo\n");
        file_put_contents($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE, <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env
        YAML);

        $model = $this->dindModel(['env_vars' => ['DB_PASSWORD' => 'hunter2']]);
        $this->forcedEnvironment($model, tracked: true)->apply();

        $this->assertSame('0600', $this->mode(EngineArtifacts::ENV_OVERRIDES));
        $this->assertSame('0600', $this->mode('.env.default'));
        // The run file now carries .env.panelalpha too; port detection reads it through sudo.
        $this->assertSame('0600', $this->mode(EngineArtifacts::RUN_COMPOSE));
    }

    private function mode(string $name): string
    {
        clearstatcache();

        return sprintf('%04o', fileperms($this->projectDir . '/' . $name) & 0777);
    }

    public function test_defer_to_compose_defaults_matches_lib_lychee_case(): void
    {
        $envExample = <<<'ENV'
APP_NAME=Lychee
APP_KEY=base64:generatedbytheengine
DB_CONNECTION=sqlite
DB_HOST=
DB_DATABASE=lychee
ENV;
        $compose = <<<'YAML'
x-common-env: &common-env
  APP_KEY: "${APP_KEY}"
  DB_CONNECTION: "${DB_CONNECTION:-mysql}"
  DB_HOST: "${DB_HOST:-lychee_db}"
  DB_DATABASE: "${DB_DATABASE:-lychee}"
YAML;

        [$trimmed, $dropped] = Dind\ProjectEnvironment::deferToComposeDefaults($envExample, $compose);

        $this->assertContains('DB_CONNECTION', $dropped);
        $this->assertArrayNotHasKey('DB_CONNECTION', $this->vars($trimmed));
        $this->assertSame('Lychee', $this->vars($trimmed)['APP_NAME']);
    }

    /**
     * @return array<string, string>
     */
    private function vars(string $contents): array
    {
        $values = [];
        foreach (EnvFile::parse($contents) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $values[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $values;
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->homeRoot), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /**
     * A `ProjectEnvironment` whose tracked/untracked decision is forced
     * rather than answered by a real git repository: `GitRepository` only
     * runs through a live DinD shell, which nothing here provides.
     */
    private function forcedEnvironment(ModelsUser $model, bool $tracked): Dind\ProjectEnvironment
    {
        return new class($this->dind($model), $tracked) extends Dind\ProjectEnvironment {
            public function __construct(Dind $dind, private bool $tracked)
            {
                parent::__construct($dind);
            }

            protected function envIsTracked(): bool
            {
                return $this->tracked;
            }
        };
    }

    /** What a redeploy does to ~/project: cleared, then cloned again. */
    private function reclone(string $example): void
    {
        $this->removeTree($this->projectDir);
        mkdir($this->projectDir, 0777, true);
        file_put_contents($this->projectDir . '/.env.example', $example);
    }

    private function dindModel(array $details = [], string $username = 'alice'): ModelsUser
    {
        $model = new class extends ModelsUser {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = $username;
        $model->setDetails(array_merge([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
        ], $details));

        return $model;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
