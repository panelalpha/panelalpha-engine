<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tiledesk's upstream compose signs its tokens with published secrets, and its RabbitMQ image trusts the same key.
 */
class TiledeskSecretsTest extends TestCase
{
    private const PUBLISHED = ['tokenKey', 'nodeauthsecret', 'WHFyAhecCMkYsvldLFWbSrJt7lAZFAmrp7O5QVMvGQsLVn9SkfT', 'eyJ'];

    /** @var list<string> */
    private array $homes = [];

    protected function tearDown(): void
    {
        foreach ($this->homes as $home) {
            exec('rm -rf '.escapeshellarg($home));
        }
    }

    public function test_the_compose_file_holds_no_published_secret_or_token(): void
    {
        $compose = (string) AppConfigDirectory::read(new LocalAppConfigSource, $this->directory(), true)?->compose();
        $services = Yaml::parse($compose)['services'];

        foreach ($services as $name => $service) {
            foreach ((array) ($service['environment'] ?? []) as $entry) {
                foreach (self::PUBLISHED as $published) {
                    $this->assertStringNotContainsString($published, (string) $entry, $name);
                }
            }
        }
        foreach (['server' => 'server.env', 'chatbot' => 'chatbot.env', 'chat21httpserver' => 'chat21-http.env', 'chat21server' => 'chat21-server.env', 'backend-llm-train' => 'llm.env', 'backend-llm-qa' => 'llm.env'] as $name => $file) {
            $this->assertSame(['../.panelalpha/tiledesk/'.$file], $services[$name]['env_file'], $name);
        }
        $this->assertContains('../.panelalpha/tiledesk/rabbitmq-advanced.config:/etc/rabbitmq/advanced.config:ro', $services['rabbitmq']['volumes']);
    }

    public function test_every_token_is_signed_with_the_accounts_own_key(): void
    {
        $store = $this->runHook($this->home());
        $secrets = $this->env($store.'/secrets.env');
        $key = $secrets['CHAT21_SECRET'];

        $server = $this->env($store.'/server.env');
        $http = $this->env($store.'/chat21-http.env');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertSame($key, $server['CHAT21_JWT_SECRET']);
        $this->assertSame($key, $http['JWT_KEY']);
        $this->assertSame($secrets['GLOBAL_SECRET'], $server['GLOBAL_SECRET']);
        $this->assertSame($secrets['GLOBAL_SECRET'], $server['APPS_ACCESS_TOKEN_SECRET']);
        $this->assertSame($secrets['LLM_SECRET'], $server['JWT_SECRET_KEY']);
        $this->assertSame($secrets['LLM_SECRET'], $this->env($store.'/llm.env')['JWT_SECRET_KEY']);

        $this->assertSame(['100-APIADMIN', 'admin'], $this->claims($server['CHAT21_ADMIN_TOKEN'], $key));
        $this->assertSame(['100-APIADMIN', 'admin'], $this->claims($http['PUSH_WH_CHAT21_API_ADMIN_TOKEN'], $key));
        $this->assertSame(['100-APIADMIN', 'admin'], $this->claims($this->amqpToken($http['RABBITMQ_URI']), $key));
        foreach ([$server['AMQP_MANAGER_URL'], $this->env($store.'/chatbot.env')['AMQP_MANAGER_URL'], $this->env($store.'/chat21-server.env')['RABBITMQ_URI']] as $url) {
            $this->assertSame(['01-OBSERVER', 'user'], $this->claims($this->amqpToken($url), $key));
        }
        $this->assertStringEndsWith('/push/webhook/endpoint/'.$http['PUSH_WH_WEBHOOK_TOKEN'], $this->env($store.'/chat21-server.env')['WEBHOOK_ENDPOINTS']);

        $rabbitmq = (string) file_get_contents($store.'/rabbitmq-advanced.config');
        $this->assertStringContainsString('<<"value">> => <<"'.$key.'">>', $rabbitmq);
        $this->assertStringNotContainsString('tokenKey', $rabbitmq);
        $this->assertSame('0644', substr(sprintf('%o', fileperms($store.'/rabbitmq-advanced.config')), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($store.'/server.env')), -4));
    }

    public function test_a_redeploy_keeps_the_secrets_and_another_account_gets_its_own(): void
    {
        $home = $this->home();
        $store = $this->runHook($home);
        $first = $this->files($store);
        $this->runHook($home);

        $this->assertSame($first, $this->files($store));
        $this->assertNotSame(
            $this->env($store.'/secrets.env')['CHAT21_SECRET'],
            $this->env($this->runHook($this->home()).'/secrets.env')['CHAT21_SECRET']
        );
    }

    private function runHook(string $home): string
    {
        exec('HOME='.escapeshellarg($home).' bash '.escapeshellarg($this->directory().'/hooks/prepare.sh').' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));

        return $home.'/.panelalpha/tiledesk';
    }

    private function home(): string
    {
        $home = sys_get_temp_dir().'/pa-tiledesk-'.bin2hex(random_bytes(6));
        mkdir($home.'/project', 0777, true);
        $this->homes[] = $home;

        return $home;
    }

    /** @return array<string, string> */
    private function env(string $path): array
    {
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$name, $value] = explode('=', $line, 2);
            $values[$name] = $value;
        }

        return $values;
    }

    /** @return array<string, string> */
    private function files(string $store): array
    {
        $files = [];
        foreach (glob($store.'/*') ?: [] as $path) {
            $files[basename($path)] = (string) file_get_contents($path);
        }

        return $files;
    }

    private function amqpToken(string $url): string
    {
        $this->assertSame(1, preg_match('#^amqp://ignored:([^@]+)@rabbitmq:5672\?heartbeat=60$#', $url, $m), $url);

        return $m[1];
    }

    /** @return array{0: string, 1: string} sub and role of an HS256 token signed with $key */
    private function claims(string $token, string $key): array
    {
        [$header, $payload, $signature] = explode('.', $token);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, $key, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $signature);
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame(['rabbitmq', $claims['sub']], $claims['aud']);
        $this->assertContains('rabbitmq.configure:*/*/*', $claims['scope']);

        return [$claims['sub'], $claims['tiledesk_api_roles']];
    }

    private function directory(): string
    {
        return SourceRecipes::defaultDirectory().'/github.com/tiledesk/tiledesk';
    }
}
