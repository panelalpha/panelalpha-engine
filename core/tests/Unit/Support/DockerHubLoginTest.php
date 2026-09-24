<?php

namespace Tests\Unit\Support;

use App\Support\DockerHubLogin;
use App\System;
use PHPUnit\Framework\TestCase;

/**
 * The Docker Hub login registry-proxy pulls with: checked against Hub,
 * refused when it would hand private repositories to every account, and
 * written into the compose `.env` without disturbing anything else there.
 */
class DockerHubLoginTest extends TestCase
{
    /**
     * @param array<string, array{status: int, headers?: array<string, string>, body?: string}> $routes "METHOD url-prefix"
     */
    private function login(array $routes): DockerHubLogin
    {
        return new DockerHubLogin(
            $this->createStub(System::class),
            static function (string $method, string $url) use ($routes): ?array {
                foreach ($routes as $key => $route) {
                    [$m, $prefix] = explode(' ', $key, 2);
                    if ($m === $method && str_starts_with($url, $prefix)) {
                        return $route + ['headers' => [], 'body' => ''];
                    }
                }

                return null;
            }
        );
    }

    /** A registry bearer token granting $actions on $repository, as auth.docker.io issues one. */
    private static function jwt(string $repository, array $actions): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'access' => [['type' => 'repository', 'name' => $repository, 'actions' => $actions]],
        ])), '+/', '-_'), '=');

        return json_encode(['token' => "e30.{$payload}.sig"]);
    }

    public function test_a_login_hub_accepts_reports_its_limit(): void
    {
        $check = $this->login([
            'GET https://auth.docker.io/token' => ['status' => 200, 'body' => '{"token":"t"}'],
            'HEAD https://registry-1.docker.io/' => ['status' => 200, 'headers' => [
                'ratelimit-limit' => '200;w=3600',
                'ratelimit-remaining' => '197;w=3600',
                'docker-ratelimit-source' => 'me',
            ]],
        ])->check('me', 'dckr_pat_abcdefgh');

        $this->assertTrue($check['ok']);
        $this->assertSame('200 per hour', $check['limit']);
        $this->assertSame('197 per hour', $check['remaining']);
        $this->assertSame('me', $check['source']);
    }

    public function test_a_refused_or_unreachable_login_says_which(): void
    {
        $refused = $this->login(['GET https://auth.docker.io/token' => ['status' => 401]])->check('me', 'dckr_pat_wrongwrong');
        $offline = $this->login([])->check('me', 'dckr_pat_abcdefgh');

        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('refused', (string) $refused['error']);
        $this->assertStringContainsString('could not be reached', (string) $offline['error']);
    }

    public function test_only_private_repositories_the_token_can_pull_are_reported(): void
    {
        $login = $this->login([
            'POST https://hub.docker.com/v2/users/login' => ['status' => 200, 'body' => '{"token":"hub"}'],
            'GET https://hub.docker.com/v2/namespaces/me/repositories' => ['status' => 200, 'body' => json_encode(['results' => [
                ['name' => 'site', 'is_private' => false],
                ['name' => 'secret', 'is_private' => true],
                ['name' => 'sealed', 'is_private' => true],
            ]])],
            'GET https://auth.docker.io/token?service=registry.docker.io&scope=repository:me/secret:pull' => ['status' => 200, 'body' => self::jwt('me/secret', ['pull'])],
            // A Public Repo Read-only token gets a token with no actions.
            'GET https://auth.docker.io/token?service=registry.docker.io&scope=repository:me/sealed:pull' => ['status' => 200, 'body' => self::jwt('me/sealed', [])],
        ]);

        $this->assertSame(['me/secret'], $login->pullablePrivateRepositories('me', 'dckr_pat_abcdefgh'));
    }

    public function test_not_knowing_is_null_not_an_all_clear(): void
    {
        $login = $this->login(['POST https://hub.docker.com/v2/users/login' => ['status' => 401]]);

        $this->assertNull($login->pullablePrivateRepositories('me', 'dckr_pat_abcdefgh'));
    }

    public function test_the_keys_are_replaced_in_place_and_nothing_else_moves(): void
    {
        $env = "# Which optional service groups start.\nCOMPOSE_PROFILES=full\n"
            . "# The Docker Hub account registry-proxy logs in as\nREGISTRY_PROXY_USERNAME=\nREGISTRY_PROXY_PASSWORD=\nOTHER=1\n";

        $this->assertSame(
            "# Which optional service groups start.\nCOMPOSE_PROFILES=full\n"
            . "# The Docker Hub account registry-proxy logs in as\nREGISTRY_PROXY_USERNAME=me\nREGISTRY_PROXY_PASSWORD=dckr_pat_x\nOTHER=1\n",
            DockerHubLogin::updatedEnv($env, 'me', 'dckr_pat_x')
        );
    }

    public function test_absent_keys_are_appended_and_clearing_empties_them(): void
    {
        $this->assertSame(
            "COMPOSE_PROFILES=full\nREGISTRY_PROXY_USERNAME=me\nREGISTRY_PROXY_PASSWORD=t\n",
            DockerHubLogin::updatedEnv("COMPOSE_PROFILES=full\n", 'me', 't')
        );
        $this->assertSame(
            "REGISTRY_PROXY_USERNAME=\nREGISTRY_PROXY_PASSWORD=\n",
            DockerHubLogin::updatedEnv("REGISTRY_PROXY_USERNAME=me\nREGISTRY_PROXY_PASSWORD=t\n", '', '')
        );
        $this->assertSame("REGISTRY_PROXY_USERNAME=me\nREGISTRY_PROXY_PASSWORD=t\n", DockerHubLogin::updatedEnv('', 'me', 't'));
    }

    public function test_nothing_that_could_break_the_env_file_is_accepted(): void
    {
        $this->assertNull(DockerHubLogin::badUsername('mariuszmod'));
        $this->assertNull(DockerHubLogin::badToken('dckr_pat_example-Token_0123'));
        $this->assertNotNull(DockerHubLogin::badUsername('Me Too'));
        $this->assertNotNull(DockerHubLogin::badToken("abc\ndef=ghi"));
        $this->assertNotNull(DockerHubLogin::badToken('with space in it'));
        $this->assertNotNull(DockerHubLogin::badToken('"quoted"'));
    }
}
