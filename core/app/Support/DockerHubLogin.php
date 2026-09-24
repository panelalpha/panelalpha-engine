<?php

namespace App\Support;

use App\System;
use GuzzleHttp\Client;

/**
 * The Docker Hub login registry-proxy pulls with.
 *
 * Every Docker Hub pull on this host goes through panelalpha-registry-proxy:
 * account daemons and the host daemon both mirror to it. So this one login
 * decides the rate limit for the whole engine, and it is the only place a Hub
 * credential should live: `REGISTRY_PROXY_USERNAME/PASSWORD` in the engine's
 * compose `.env`, which core sees at the same path through its /opt/panelalpha
 * mount.
 *
 * A pull-through proxy serves anything its login can read to every account,
 * private repositories included, which is why a login is checked for those
 * before it is saved.
 */
final class DockerHubLogin
{
    public const ENV_USERNAME = 'REGISTRY_PROXY_USERNAME';
    public const ENV_PASSWORD = 'REGISTRY_PROXY_PASSWORD';
    public const CONTAINER = 'panelalpha-registry-proxy';

    /** @var callable(string, string, array<string, string>, ?string): ?array{status: int, headers: array<string, string>, body: string} */
    private $http;

    public function __construct(private readonly System $system, ?callable $http = null)
    {
        $this->http = $http ?? self::send(...);
    }

    public static function badUsername(string $username): ?string
    {
        return preg_match('/^[a-z0-9][a-z0-9_.-]{1,254}$/', $username) === 1
            ? null
            : 'A Docker Hub username: lowercase letters, digits, dots, dashes and underscores.';
    }

    public static function badToken(string $token): ?string
    {
        return preg_match('/^[A-Za-z0-9_.-]{8,512}$/', $token) === 1
            ? null
            : 'A Docker Hub access token (dckr_pat_…) or password without spaces or quotes.';
    }

    /**
     * Does Docker Hub accept this login, and what limit does it get?
     *
     * The probe repository is Docker's own for exactly this; a HEAD on it
     * does not count against the limit it reports.
     *
     * @return array{ok: bool, error: ?string, limit: ?string, remaining: ?string, source: ?string}
     */
    public function check(string $username, string $token): array
    {
        $none = ['limit' => null, 'remaining' => null, 'source' => null];
        $auth = ($this->http)(
            'GET',
            'https://auth.docker.io/token?service=registry.docker.io&scope=repository:ratelimitpreview/test:pull',
            ['Authorization' => 'Basic ' . base64_encode("{$username}:{$token}")],
            null
        );
        if ($auth === null) {
            return ['ok' => false, 'error' => 'Docker Hub could not be reached from this server.'] + $none;
        }
        $bearer = json_decode($auth['body'], true)['token'] ?? null;
        if ($auth['status'] === 401 || $auth['status'] === 403) {
            return ['ok' => false, 'error' => 'Docker Hub refused this username and token.'] + $none;
        }
        if ($auth['status'] !== 200 || !is_string($bearer)) {
            return ['ok' => false, 'error' => "Docker Hub answered HTTP {$auth['status']}."] + $none;
        }

        $head = ($this->http)(
            'HEAD',
            'https://registry-1.docker.io/v2/ratelimitpreview/test/manifests/latest',
            ['Authorization' => "Bearer {$bearer}"],
            null
        );

        return [
            'ok' => true,
            'error' => null,
            'limit' => self::perWindow($head['headers']['ratelimit-limit'] ?? null),
            'remaining' => self::perWindow($head['headers']['ratelimit-remaining'] ?? null),
            'source' => $head['headers']['docker-ratelimit-source'] ?? null,
        ];
    }

    /**
     * Private repositories in the account's own namespace that this token can
     * pull, which the proxy would then hand to any tenant. Null when it cannot
     * be told, e.g. a token Hub's API does not accept for listing.
     *
     * @return list<string>|null
     */
    public function pullablePrivateRepositories(string $username, string $token): ?array
    {
        $login = ($this->http)(
            'POST',
            'https://hub.docker.com/v2/users/login',
            ['Content-Type' => 'application/json'],
            (string) json_encode(['username' => $username, 'password' => $token])
        );
        $jwt = json_decode($login['body'] ?? '', true)['token'] ?? null;
        if (($login['status'] ?? 0) !== 200 || !is_string($jwt)) {
            return null;
        }

        $list = ($this->http)(
            'GET',
            "https://hub.docker.com/v2/namespaces/{$username}/repositories?page_size=100",
            ['Authorization' => "Bearer {$jwt}"],
            null
        );
        $results = json_decode($list['body'] ?? '', true)['results'] ?? null;
        if (($list['status'] ?? 0) !== 200 || !is_array($results)) {
            return null;
        }

        $pullable = [];
        foreach ($results as $repository) {
            if (!is_array($repository) || ($repository['is_private'] ?? false) !== true) {
                continue;
            }
            $name = "{$username}/" . ($repository['name'] ?? '');
            if ($this->canPull($username, $token, $name)) {
                $pullable[] = $name;
            }
        }

        return $pullable;
    }

    /**
     * The login the running proxy uses: its username ('' for anonymous) and
     * token, or null when the proxy is not running.
     *
     * @return array{username: string, token: string}|null
     */
    public function current(): ?array
    {
        try {
            $env = (string) $this->system->exec(
                ['sudo', 'docker', 'inspect', '-f', '{{if .State.Running}}{{range .Config.Env}}{{println .}}{{end}}{{end}}', self::CONTAINER],
                [],
                30
            );
        } catch (\Exception $e) {
            return null;
        }
        if (trim($env) === '') {
            return null;
        }

        $values = [];
        foreach (preg_split('/\r?\n/', $env) ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $values[$key] = $value;
        }

        return [
            'username' => $values[self::ENV_USERNAME] ?? '',
            'token' => $values[self::ENV_PASSWORD] ?? '',
        ];
    }

    /** Whether the host daemon mirrors Docker Hub through the proxy too. */
    public function hostMirrors(): ?bool
    {
        try {
            $mirrors = (string) $this->system->exec(
                ['sudo', 'docker', 'info', '--format', '{{json .RegistryConfig.Mirrors}}'],
                [],
                30
            );
        } catch (\Exception $e) {
            return null;
        }

        return str_contains($mirrors, '127.0.0.1:5001');
    }

    /** Write the login to the host's `.env` and recreate registry-proxy with it. */
    public function save(string $username, string $token): void
    {
        $bad = self::badUsername($username) ?? self::badToken($token);
        if ($bad !== null) {
            throw new \InvalidArgumentException($bad);
        }
        $this->apply($username, $token);
    }

    /** Back to anonymous pulls. */
    public function clear(): void
    {
        $this->apply('', '');
    }

    /**
     * $env with both keys set in place, appended when absent, everything else
     * (comments included) untouched.
     */
    public static function updatedEnv(string $env, string $username, string $token): string
    {
        $want = [self::ENV_USERNAME => $username, self::ENV_PASSWORD => $token];
        $lines = $env === '' ? [] : explode("\n", rtrim($env, "\n"));
        foreach ($lines as $i => $line) {
            $key = explode('=', $line, 2)[0];
            if (array_key_exists($key, $want)) {
                $lines[$i] = "{$key}={$want[$key]}";
                unset($want[$key]);
            }
        }
        foreach ($want as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines) . "\n";
    }

    /** Write the keys, keeping a backup, then recreate registry-proxy alone so it reads them. */
    private function apply(string $username, string $token): void
    {
        $dir = $this->system->engineDirPath();
        $path = "{$dir}/.env";
        $current = is_file($path) ? (string) file_get_contents($path) : '';
        if ($current !== '' && !copy($path, $path . EnvFile::BACKUP_SUFFIX)) {
            throw new \RuntimeException("Could not back up {$path}");
        }
        if (file_put_contents($path, self::updatedEnv($current, $username, $token)) === false) {
            throw new \RuntimeException("Could not write {$path}");
        }

        $this->system->exec(
            ['sudo', 'docker', 'compose', '--project-directory', $dir, 'up', '-d', '--no-deps', 'registry-proxy'],
            [],
            300
        );
    }

    private function canPull(string $username, string $token, string $repository): bool
    {
        $auth = ($this->http)(
            'GET',
            "https://auth.docker.io/token?service=registry.docker.io&scope=repository:{$repository}:pull",
            ['Authorization' => 'Basic ' . base64_encode("{$username}:{$token}")],
            null
        );
        $jwt = json_decode($auth['body'] ?? '', true)['token'] ?? '';
        $payload = json_decode((string) base64_decode(strtr(explode('.', (string) $jwt)[1] ?? '', '-_', '+/')), true);

        foreach ($payload['access'] ?? [] as $grant) {
            if (($grant['name'] ?? '') === $repository && in_array('pull', $grant['actions'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /** "200;w=3600" as "200 per hour". */
    private static function perWindow(?string $header): ?string
    {
        if ($header === null || preg_match('/^(\d+)(?:;w=(\d+))?/', $header, $m) !== 1) {
            return null;
        }
        $window = (int) ($m[2] ?? 0);

        return $m[1] . match (true) {
            $window === 3600 => ' per hour',
            $window === 21600 => ' per 6 hours',
            $window > 0 => " per {$window}s",
            default => '',
        };
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function send(string $method, string $url, array $headers, ?string $body): ?array
    {
        try {
            $response = (new Client(['connect_timeout' => 5, 'timeout' => 20, 'http_errors' => false]))
                ->request($method, $url, ['headers' => $headers] + ($body === null ? [] : ['body' => $body]));
        } catch (\Throwable $e) {
            return null;
        }

        $flat = [];
        foreach ($response->getHeaders() as $name => $values) {
            $flat[strtolower($name)] = implode(', ', $values);
        }

        return ['status' => $response->getStatusCode(), 'headers' => $flat, 'body' => (string) $response->getBody()];
    }
}
