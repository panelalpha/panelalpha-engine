<?php

namespace Tests\Unit\Deploy\Credentials;

use App\Lib\Deploy\Credentials\AppCredentials;
use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\Platform\ManifestException;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle of an application's generated login across deploys: generated
 * once, kept, adopted from the file a recipe used to keep it in, overridden by
 * the project's own env_vars, dropped when no longer declared.
 */
class AppCredentialsTest extends TestCase
{
    private function spec(array $fields, ?string $adopt = null, ?string $login = null): CredentialSpec
    {
        $spec = CredentialSpec::parse(
            array_filter(['fields' => $fields, 'adopt_from' => $adopt, 'login_path' => $login]),
            fn (string $m) => new ManifestException($m)
        );
        $this->assertNotNull($spec);

        return $spec;
    }

    private function sonarr(?string $adopt = null): CredentialSpec
    {
        return $this->spec([
            'SONARR_ADMIN_USER' => ['kind' => 'username', 'value' => 'admin'],
            'SONARR_ADMIN_PASSWORD' => ['kind' => 'password'],
        ], $adopt, '/login');
    }

    private static function noFile(): \Closure
    {
        return static fn (string $path): ?array => null;
    }

    public function test_a_first_deploy_generates_every_field(): void
    {
        $result = AppCredentials::reconcile($this->sonarr(), null, [], self::noFile(), '2026-09-30T10:00:00+00:00');
        $stored = $result['stored'];

        $this->assertSame(['SONARR_ADMIN_USER', 'SONARR_ADMIN_PASSWORD'], $result['generated']);
        $this->assertSame(['kind' => 'username', 'value' => 'admin'], $stored['fields']['SONARR_ADMIN_USER']);
        $this->assertSame('password', $stored['fields']['SONARR_ADMIN_PASSWORD']['kind']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', $stored['fields']['SONARR_ADMIN_PASSWORD']['value']);
        $this->assertSame('/login', $stored['login_path']);
        $this->assertSame('2026-09-30T10:00:00+00:00', $stored['created_at']);
    }

    public function test_defaults_for_a_username_and_an_email(): void
    {
        $stored = AppCredentials::reconcile(
            $this->spec(['U' => ['kind' => 'username'], 'E' => ['kind' => 'email'], 'F' => ['kind' => 'email', 'value' => 'ops@acme.test']]),
            null,
            [],
            self::noFile()
        )['stored'];

        $this->assertSame('admin', $stored['fields']['U']['value']);
        $this->assertSame('admin@example.com', $stored['fields']['E']['value']);
        $this->assertSame('ops@acme.test', $stored['fields']['F']['value']);
    }

    public function test_placeholders_are_filled_once_and_then_kept(): void
    {
        $spec = $this->spec(['E' => ['kind' => 'email', 'value' => 'admin-{random}@{host}']]);

        $first = AppCredentials::reconcile($spec, null, [], self::noFile(), null, 'App.Example.org')['stored'];
        $this->assertMatchesRegularExpression('/^admin-[0-9a-f]{8}@app\.example\.org$/', $first['fields']['E']['value']);

        $again = AppCredentials::reconcile($spec, $first, [], self::noFile(), null, 'moved.example.net')['stored'];
        $this->assertSame($first['fields']['E']['value'], $again['fields']['E']['value']);

        $noHost = AppCredentials::reconcile($spec, null, [], self::noFile())['stored'];
        $this->assertMatchesRegularExpression('/^admin-[0-9a-f]{8}@example\.com$/', $noHost['fields']['E']['value']);
    }

    public function test_the_email_placeholder_is_the_projects_email_or_a_random_one(): void
    {
        $spec = $this->spec(['E' => ['kind' => 'email', 'value' => '{email}']]);

        $owner = AppCredentials::reconcile($spec, null, [], self::noFile(), null, 'app.example.org', 'owner@acme.test')['stored'];
        $this->assertSame('owner@acme.test', $owner['fields']['E']['value']);

        foreach ([null, '', "o'brien@acme.test"] as $email) {
            $fallback = AppCredentials::reconcile($spec, null, [], self::noFile(), null, 'app.example.org', $email)['stored'];
            $this->assertMatchesRegularExpression('/^admin-[0-9a-f]{8}@app\.example\.org$/', $fallback['fields']['E']['value']);
        }
    }

    public function test_a_redeploy_keeps_every_stored_value(): void
    {
        $first = AppCredentials::reconcile($this->sonarr(), null, [], self::noFile(), 'then')['stored'];
        $again = AppCredentials::reconcile($this->sonarr(), $first, [], self::noFile(), 'now');

        $this->assertSame($first, $again['stored']);
        $this->assertSame([], $again['generated']);
    }

    public function test_a_newly_declared_field_is_generated_once_and_the_rest_kept(): void
    {
        $first = AppCredentials::reconcile($this->sonarr(), null, [], self::noFile())['stored'];
        $wider = $this->spec([
            'SONARR_ADMIN_USER' => ['kind' => 'username'],
            'SONARR_ADMIN_PASSWORD' => ['kind' => 'password'],
            'SONARR_API_SECRET' => ['kind' => 'password', 'symbol' => true],
        ]);
        $result = AppCredentials::reconcile($wider, $first, [], self::noFile());

        $this->assertSame(['SONARR_API_SECRET'], $result['generated']);
        $this->assertSame($first['fields']['SONARR_ADMIN_PASSWORD'], $result['stored']['fields']['SONARR_ADMIN_PASSWORD']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+[-_!][A-Za-z0-9]*$/', $result['stored']['fields']['SONARR_API_SECRET']['value']);
    }

    public function test_a_field_no_longer_declared_is_dropped(): void
    {
        $first = AppCredentials::reconcile($this->sonarr(), null, [], self::noFile())['stored'];
        $narrower = $this->spec(['SONARR_ADMIN_PASSWORD' => ['kind' => 'password']]);

        $stored = AppCredentials::reconcile($narrower, $first, [], self::noFile())['stored'];

        $this->assertSame(['SONARR_ADMIN_PASSWORD'], array_keys($stored['fields']));
        $this->assertSame($first['fields']['SONARR_ADMIN_PASSWORD'], $stored['fields']['SONARR_ADMIN_PASSWORD']);
    }

    public function test_nothing_declared_stores_nothing(): void
    {
        $first = AppCredentials::reconcile($this->sonarr(), null, [], self::noFile())['stored'];

        $this->assertNull(AppCredentials::reconcile(null, $first, [], self::noFile())['stored']);
    }

    public function test_the_first_generation_adopts_what_the_recipe_file_already_holds(): void
    {
        $asked = [];
        $read = function (string $path) use (&$asked): array {
            $asked[] = $path;

            return ['SONARR_ADMIN_USER' => 'admin', 'SONARR_ADMIN_PASSWORD' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90'];
        };

        $result = AppCredentials::reconcile($this->sonarr('.panelalpha/sonarr/admin.env'), null, [], $read);

        $this->assertSame([AppCredentials::ENV_FILE, '.panelalpha/sonarr/admin.env'], $asked, 'read once for all fields');
        $this->assertSame(['SONARR_ADMIN_USER', 'SONARR_ADMIN_PASSWORD'], $result['adopted']);
        $this->assertSame('a1b2c3d4e5f60718293a4b5c6d7e8f90', $result['stored']['fields']['SONARR_ADMIN_PASSWORD']['value']);
    }

    public function test_a_lost_record_takes_back_what_the_engine_delivered_before(): void
    {
        $read = fn (string $path): ?array => match ($path) {
            AppCredentials::ENV_FILE => ['SONARR_ADMIN_PASSWORD' => 'Delivered1'],
            '.panelalpha/sonarr/admin.env' => ['SONARR_ADMIN_PASSWORD' => 'OlderRecipe2', 'SONARR_ADMIN_USER' => 'root'],
            default => null,
        };

        $stored = AppCredentials::reconcile($this->sonarr('.panelalpha/sonarr/admin.env'), null, [], $read)['stored'];

        $this->assertSame('Delivered1', $stored['fields']['SONARR_ADMIN_PASSWORD']['value']);
        $this->assertSame('root', $stored['fields']['SONARR_ADMIN_USER']['value']);
    }

    public function test_a_kept_value_is_not_replaced_by_the_file(): void
    {
        $first = AppCredentials::reconcile($this->sonarr(), null, [], self::noFile())['stored'];
        $read = fn (string $path): array => ['SONARR_ADMIN_PASSWORD' => 'from-the-old-file'];

        $stored = AppCredentials::reconcile($this->sonarr('.panelalpha/sonarr/admin.env'), $first, [], $read)['stored'];

        $this->assertSame($first, $stored);
    }

    public function test_a_missing_or_unusable_adopt_file_generates(): void
    {
        $result = AppCredentials::reconcile(
            $this->sonarr('.panelalpha/sonarr/admin.env'),
            null,
            [],
            fn (string $path): array => ['SONARR_ADMIN_PASSWORD' => "it's quoted"]
        );

        $this->assertSame(['SONARR_ADMIN_USER', 'SONARR_ADMIN_PASSWORD'], $result['generated']);
        $this->assertSame([], $result['adopted']);
    }

    public function test_the_projects_env_vars_win_and_are_stored_as_the_effective_value(): void
    {
        $first = AppCredentials::reconcile($this->sonarr(), null, [], self::noFile())['stored'];
        $result = AppCredentials::reconcile($this->sonarr(), $first, ['SONARR_ADMIN_PASSWORD' => 'Chosen-By-Owner-1', 'OTHER' => 'x'], self::noFile());

        $this->assertSame(['SONARR_ADMIN_PASSWORD'], $result['overridden']);
        $this->assertSame('Chosen-By-Owner-1', $result['stored']['fields']['SONARR_ADMIN_PASSWORD']['value']);
        $this->assertArrayNotHasKey('OTHER', $result['stored']['fields']);
    }

    public function test_a_changed_kind_generates_afresh(): void
    {
        $stored = ['fields' => ['X' => ['kind' => 'username', 'value' => 'admin']], 'created_at' => 'then'];

        $result = AppCredentials::reconcile($this->spec(['X' => ['kind' => 'password']]), $stored, [], self::noFile());

        $this->assertSame(['X'], $result['generated']);
        $this->assertNotSame('admin', $result['stored']['fields']['X']['value']);
        $this->assertSame('then', $result['stored']['created_at']);
    }

    public function test_generated_passwords_have_every_character_class_and_differ(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $password = AppCredentials::generatePassword();
            $this->assertSame(AppCredentials::PASSWORD_LENGTH, strlen($password));
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $password);
            $seen[$password] = true;

            $symbol = AppCredentials::generatePassword(true);
            $this->assertSame(1, preg_match_all('/[-_!]/', $symbol));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]/', $symbol, 'never first, where a CLI reads an option');
        }
        $this->assertCount(200, $seen);
    }

    public function test_the_env_file_is_single_quoted_and_names_the_api(): void
    {
        $stored = ['fields' => [
            'SONARR_ADMIN_USER' => ['kind' => 'username', 'value' => 'admin'],
            'SONARR_ADMIN_PASSWORD' => ['kind' => 'password', 'value' => 'aB3-x'],
        ]];

        $this->assertSame(
            "# Written by the engine on every deploy; GET /projects/{name}/app-credentials returns it.\n"
            . "SONARR_ADMIN_USER='admin'\nSONARR_ADMIN_PASSWORD='aB3-x'\n",
            AppCredentials::envFile($stored)
        );
    }

    public function test_only_passwords_are_secret_values(): void
    {
        $stored = ['fields' => [
            'U' => ['kind' => 'username', 'value' => 'admin'],
            'E' => ['kind' => 'email', 'value' => 'admin@example.com'],
            'P' => ['kind' => 'password', 'value' => 'Secret123'],
            'S' => ['kind' => 'password', 'value' => 'abc'],
        ]];

        $this->assertSame(['Secret123'], AppCredentials::secretValues($stored));
        $this->assertSame([], AppCredentials::secretValues(null));
    }

    public function test_the_pointer_names_fields_and_never_a_value(): void
    {
        $stored = AppCredentials::reconcile($this->sonarr(), null, [], self::noFile())['stored'];

        $pointer = AppCredentials::pointer($stored, 'https://tv.example.com', 'acsonarr1');

        $this->assertSame([
            'available' => true,
            'fields' => [
                ['name' => 'SONARR_ADMIN_USER', 'kind' => 'username'],
                ['name' => 'SONARR_ADMIN_PASSWORD', 'kind' => 'password'],
            ],
            'login_url' => 'https://tv.example.com/login',
            'endpoint' => '/api/projects/acsonarr1/app-credentials',
        ], $pointer);
        $this->assertStringNotContainsString($stored['fields']['SONARR_ADMIN_PASSWORD']['value'], (string) json_encode($pointer));

        $this->assertSame(
            ['available' => false, 'fields' => [], 'login_url' => null, 'endpoint' => '/api/projects/plain/app-credentials'],
            AppCredentials::pointer(null, 'https://x.example.com', 'plain')
        );
    }

    public function test_reveal_returns_the_values_and_an_unavailable_shape_without_them(): void
    {
        $stored = AppCredentials::reconcile($this->sonarr(), null, [], self::noFile(), '2026-09-30T10:00:00+00:00')['stored'];

        $this->assertSame([
            'available' => true,
            'login_url' => 'https://tv.example.com/login',
            'created_at' => '2026-09-30T10:00:00+00:00',
            'fields' => [
                ['name' => 'SONARR_ADMIN_USER', 'kind' => 'username', 'value' => 'admin'],
                ['name' => 'SONARR_ADMIN_PASSWORD', 'kind' => 'password', 'value' => $stored['fields']['SONARR_ADMIN_PASSWORD']['value']],
            ],
        ], AppCredentials::reveal($stored, 'https://tv.example.com/'));

        $this->assertSame(
            ['available' => false, 'login_url' => null, 'created_at' => null, 'fields' => []],
            AppCredentials::reveal(null, 'https://tv.example.com')
        );
    }

    public function test_the_login_url_is_the_public_url_without_a_path(): void
    {
        $this->assertSame('https://a.example.com/', AppCredentials::loginUrl('https://a.example.com', null));
        $this->assertNull(AppCredentials::loginUrl(null, '/login'));
    }
}
