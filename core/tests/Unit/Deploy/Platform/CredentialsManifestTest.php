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
}
