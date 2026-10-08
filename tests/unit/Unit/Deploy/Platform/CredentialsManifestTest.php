<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `credentials:` declares the login an application is seeded with; the engine
 * generates the values. Read by the manifest and, ahead of detection, by the
 * app config a compose recipe is.
 */
class CredentialsManifestTest extends TestCase
{
    private const SONARR = [
        'login_path' => '/login',
        'adopt_from' => '.panelalpha/sonarr/admin.env',
        'fields' => [
            'SONARR_ADMIN_USER' => ['kind' => 'username', 'value' => 'admin'],
            'SONARR_ADMIN_PASSWORD' => ['kind' => 'password'],
        ],
    ];

    private function make(array $overrides = []): PlatformManifest
    {
        return PlatformManifest::fromArray(array_merge([
            'id' => 'demo',
            'label' => 'Demo',
            'priority' => 100,
            'runtime' => 'php',
            'detect' => ['file' => 'index.php'],
            'commands' => [],
        ], $overrides));
    }

    public function test_absent_declares_nothing(): void
    {
        $this->assertNull($this->make()->credentials);
        $this->assertNull($this->make(['credentials' => null])->credentials);
    }

    public function test_a_declaration_is_read(): void
    {
        $spec = $this->make(['credentials' => self::SONARR])->credentials;

        $this->assertNotNull($spec);
        $this->assertSame('/login', $spec->loginPath);
        $this->assertSame('.panelalpha/sonarr/admin.env', $spec->adoptFrom);
        $this->assertSame(
            [
                'SONARR_ADMIN_USER' => ['kind' => 'username', 'value' => 'admin', 'symbol' => false],
                'SONARR_ADMIN_PASSWORD' => ['kind' => 'password', 'value' => null, 'symbol' => false],
            ],
            $spec->fields
        );
    }

    public function test_the_decision_carries_it_and_reads_back_the_same(): void
    {
        $context = ProjectContext::make(sys_get_temp_dir(), []);
        $decision = $this->make(['credentials' => self::SONARR])->describe($context);

        $this->assertIsArray($decision['credentials']);
        $this->assertEquals(
            CredentialSpec::parse(self::SONARR, fn (string $m) => new ManifestException($m)),
            CredentialSpec::fromArray($decision['credentials'])
        );
        $this->assertNull($this->make()->describe($context)['credentials']);
        $this->assertNull(CredentialSpec::fromArray(null));
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalid(): array
    {
        $field = static fn (array $f): array => ['fields' => ['X' => $f]];

        return [
            'a list' => [['SONARR_ADMIN_USER'], 'must be a mapping'],
            'no fields' => [['login_path' => '/login'], 'fields must be a non-empty mapping'],
            'unknown key' => [['fields' => ['X' => ['kind' => 'password']], 'persist' => true], 'unknown key'],
            'bad env name' => [['fields' => ['1X' => ['kind' => 'password']]], 'not a valid environment variable name'],
            'bad kind' => [$field(['kind' => 'token']), 'kind must be one of'],
            'missing kind' => [$field(['value' => 'admin']), 'kind must be one of'],
            'password value' => [$field(['kind' => 'password', 'value' => 'hunter2']), 'cannot declare a value'],
            'quoted value' => [$field(['kind' => 'username', 'value' => "ad'min"]), 'value must be'],
            'unknown placeholder' => [$field(['kind' => 'email', 'value' => 'admin@{domain}']), 'value must be'],
            'symbol on a username' => [$field(['kind' => 'username', 'symbol' => true]), 'password only'],
            'field unknown key' => [$field(['kind' => 'password', 'length' => 32]), 'unknown key'],
            'relative login path' => [['login_path' => 'login', 'fields' => ['X' => ['kind' => 'password']]], 'login_path'],
            'absolute adopt' => [['adopt_from' => '/etc/shadow', 'fields' => ['X' => ['kind' => 'password']]], 'adopt_from'],
            'escaping adopt' => [['adopt_from' => '../other/.env', 'fields' => ['X' => ['kind' => 'password']]], 'adopt_from'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_anything_else_is_refused(mixed $value, string $message): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/^demo: .*' . preg_quote($message, '/') . '/');
        $this->make(['credentials' => $value]);
    }

    public function test_a_compose_recipe_declares_it_without_describing_a_manifest(): void
    {
        $config = AppConfig::fromYaml(
            "description: a compose app\ncredentials:\n  fields:\n    ADMIN_PASSWORD: {kind: password, symbol: true}\n"
        );

        $this->assertNotNull($config);
        // Only a login: no manifest, so detection still picks the recipe's compose file.
        $this->assertNull($config->manifest());
        $this->assertNull(SourceRecipes::fromAppConfig($config, 'panelalpha.yaml'));
        $this->assertSame(
            ['ADMIN_PASSWORD' => ['kind' => 'password', 'value' => null, 'symbol' => true]],
            $config->credentials()?->fields
        );
    }

    public function test_the_app_config_refuses_a_bad_declaration_by_file(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/^panelalpha\.yaml: credentials\.fields/');
        AppConfig::fromYaml("credentials:\n  fields: []\n");
    }

    public function test_a_source_recipe_extending_a_shipped_one_carries_it_to_the_manifest(): void
    {
        $config = AppConfig::fromYaml(
            "extends: laravel\ncredentials:\n  login_path: /admin\n  fields:\n    ADMIN_EMAIL: {kind: email}\n"
        );
        $manifest = SourceRecipes::fromAppConfig($config, 'panelalpha.yaml');

        $this->assertNotNull($manifest);
        $this->assertSame('/admin', $manifest->credentials?->loginPath);
        $this->assertSame('email', $manifest->credentials?->fields['ADMIN_EMAIL']['kind']);
    }

    public function test_the_shipped_sonarr_recipe_declares_its_login(): void
    {
        $dir = SourceRecipes::defaultDirectory() . '/github.com/sonarr/sonarr';
        $config = AppConfig::fromYaml((string) file_get_contents($dir . '/panelalpha.yaml'));

        $this->assertSame('.panelalpha/sonarr/admin.env', $config?->credentials()?->adoptFrom);
        $this->assertSame(
            ['SONARR_ADMIN_USER', 'SONARR_ADMIN_PASSWORD'],
            array_keys($config?->credentials()?->fields ?? [])
        );
        $this->assertStringContainsString(
            '../.panelalpha/app-credentials.env',
            (string) file_get_contents($dir . '/overrides/docker-compose.yml')
        );
        $this->assertStringNotContainsString('openssl rand', (string) file_get_contents($dir . '/hooks/prepare.sh'));
    }

    /** @return array<string, array{string, list<string>, string, string}> */
    public static function lastMigratedRecipes(): array
    {
        return [
            'pyfedi' => ['codeberg.org/rimu/pyfedi', ['ADMIN_USER', 'ADMIN_PASSWORD'], 'overrides/docker-compose.yml', 'ADMIN_PASSWORD='],
            'jarr' => ['git.1pxsolidblack.pl/fcxs/jarr', ['JARR_ADMIN_LOGIN', 'JARR_ADMIN_PASSWORD'], 'overrides/docker-compose.yml', 'ADMIN_PASSWORD='],
            'concretecms' => [
                'github.com/concretecms/concretecms',
                ['PA_CONCRETE_ADMIN_USER', 'PA_CONCRETE_ADMIN_EMAIL', 'PA_CONCRETE_ADMIN_PASSWORD'],
                'overrides/docker-compose.override.yml',
                'PA_CONCRETE_ADMIN_PASSWORD=',
            ],
            'galette' => ['github.com/galette/galette', ['username', 'password'], 'overrides/docker-compose.override.yml', 'urandom'],
            'budibase' => ['github.com/budibase/budibase', ['BB_ADMIN_USER_EMAIL', 'BB_ADMIN_USER_PASSWORD'], 'overrides/docker-compose.yml', 'BB_ADMIN_USER_PASSWORD='],
        ];
    }

    /**
     * These recipes generated the admin password themselves and never told the
     * customer; the engine now generates it and the app reads its env file.
     *
     * @param list<string> $fields
     */
    #[DataProvider('lastMigratedRecipes')]
    public function test_the_last_seeding_recipes_take_their_login_from_the_engine(
        string $recipe,
        array $fields,
        string $compose,
        string $generatedHere
    ): void {
        $dir = SourceRecipes::defaultDirectory() . '/' . $recipe;
        $config = AppConfig::fromYaml((string) file_get_contents($dir . '/panelalpha.yaml'));

        $this->assertSame($fields, array_keys($config?->credentials()?->fields ?? []), $recipe);
        $this->assertStringContainsString(
            '../.panelalpha/app-credentials.env',
            (string) file_get_contents($dir . '/' . $compose),
            $recipe
        );
        foreach (glob($dir . '/{hooks,files/*,files/*/*,files/*/*/*}/*.sh', GLOB_BRACE) ?: [] as $script) {
            $this->assertStringNotContainsString($generatedHere, (string) file_get_contents($script), $script);
        }
    }

    public function test_pyfedi_seeds_its_admin_without_a_terminal(): void
    {
        // init-db reads the password through pwinput, which fails without a TTY.
        $init = (string) file_get_contents(
            SourceRecipes::defaultDirectory() . '/codeberg.org/rimu/pyfedi/files/panelalpha/pyfedi/init.sh'
        );

        $this->assertStringNotContainsString('| flask init-db', $init);
        $this->assertStringContainsString('pwinput.pwinput = lambda prompt="", mask="*": input(prompt)', $init);
    }
}
