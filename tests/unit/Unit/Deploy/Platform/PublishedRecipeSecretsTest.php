<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Secrets an upstream compose file or env template publishes, which every install of a recipe would otherwise share.
 */
class PublishedRecipeSecretsTest extends TestCase
{
    public function test_resin_generates_its_proxy_token(): void
    {
        $config = $this->config('resinat/resin');

        $this->assertSame(CredentialSpec::KIND_PASSWORD, $config->credentials()?->fields['RESIN_PROXY_TOKEN']['kind'] ?? null);
        $envFiles = Yaml::parse((string) $config->compose())['services']['app']['env_file'];
        $this->assertSame(['.env', '../.panelalpha/app-credentials.env'], $envFiles, 'the generated token is read after .env');
    }

    public function test_unleash_makes_no_demo_client_token(): void
    {
        $environment = Yaml::parse((string) $this->config('unleash/unleash')->compose())['services']['web']['environment'];

        $this->assertSame('', $environment['INIT_FRONTEND_API_TOKENS']);
        $this->assertSame('', $environment['INIT_BACKEND_API_TOKENS']);
    }

    public function test_passwordcockpit_generates_its_vault_key_and_jwt_secret(): void
    {
        $fields = $this->config('passwordcockpit/passwordcockpit')->credentials()?->fields ?? [];
        $start = (string) file_get_contents($this->directory('passwordcockpit/passwordcockpit').'/files/panelalpha/passwordcockpit/start.sh');

        $this->assertSame(CredentialSpec::KIND_PASSWORD, $fields['PASSWORDCOCKPIT_VAULT_KEY']['kind'] ?? null);
        $this->assertSame(CredentialSpec::KIND_PASSWORD, $fields['PASSWORDCOCKPIT_JWT_SECRET']['kind'] ?? null);
        $this->assertStringContainsString('export PASSWORDCOCKPIT_BLOCK_CIPHER_KEY="${key}"', $start);
        $this->assertStringContainsString('export PASSWORDCOCKPIT_AUTHENTICATION_SECRET_KEY="${PASSWORDCOCKPIT_JWT_SECRET}"', $start);
    }

    public function test_a_new_vault_is_encrypted_with_the_generated_key_and_says_so(): void
    {
        $this->requireVaultKey();

        $this->assertSame(['generated-key', 'generated'], panelalpha_vault_key(null, 0, 'generated-key', 'supersecretkey'));
        $this->assertSame(['generated-key', null], panelalpha_vault_key('generated', 5, 'generated-key', 'supersecretkey'));
    }

    public function test_a_vault_already_encrypted_with_the_published_key_keeps_it(): void
    {
        $this->requireVaultKey();

        $this->assertSame(['supersecretkey', 'published'], panelalpha_vault_key(null, 3, 'generated-key', 'supersecretkey'));
        $this->assertSame(['supersecretkey', null], panelalpha_vault_key('published', 0, 'generated-key', 'supersecretkey'));
    }

    public function test_an_unknown_vault_keeps_the_published_key_without_recording_it(): void
    {
        $this->requireVaultKey();

        $this->assertSame(['supersecretkey', null], panelalpha_vault_key(null, null, 'generated-key', 'supersecretkey'));
        $this->assertSame(['supersecretkey', null], panelalpha_vault_key(null, 0, '', 'supersecretkey'));
    }

    public function test_a_vault_of_the_generated_key_never_falls_back_to_another(): void
    {
        $this->requireVaultKey();

        $this->expectException(\RuntimeException::class);
        panelalpha_vault_key('generated', 0, '', 'supersecretkey');
    }

    public function test_linkding_and_n8n_run_pinned_images(): void
    {
        $linkding = Yaml::parse((string) $this->config('sissbruecker/linkding')->compose())['services'];
        $n8n = Yaml::parse((string) $this->config('n8n-io/n8n')->compose())['services'];

        $this->assertMatchesRegularExpression('#^sissbruecker/linkding:\d+\.\d+\.\d+$#', $linkding['linkding']['image']);
        $this->assertMatchesRegularExpression('#^docker\.n8n\.io/n8nio/n8n:\d+\.\d+\.\d+$#', $n8n['n8n']['image']);
        $this->assertSame($n8n['n8n']['image'], $n8n['ready']['image'], 'the gate reuses the image n8n already pulled');
    }

    private function requireVaultKey(): void
    {
        require_once $this->directory('passwordcockpit/passwordcockpit').'/files/panelalpha/passwordcockpit/vault-key.php';
    }

    private function config(string $repository): AppConfig
    {
        $config = AppConfigDirectory::read(new LocalAppConfigSource, $this->directory($repository), true);
        $this->assertNotNull($config);

        return $config;
    }

    private function directory(string $repository): string
    {
        return SourceRecipes::defaultDirectory().'/github.com/'.$repository;
    }
}
