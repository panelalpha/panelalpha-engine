<?php

namespace App\Lib\Deploy\Dind;

/**
 * A project's logins to private image registries: the `registry-auth`
 * setting, one `host username token` line per registry.
 *
 * Pure: parsing, which registry an image reference addresses, and the Docker
 * client config that carries the logins. Writing that config into the account
 * for the length of a deploy is {@see \App\System\Project\Dind\RegistryLogin}.
 */
final class RegistryAuth
{
    /** Docker Hub, as `docker` itself keys it in config.json. */
    public const DOCKER_HUB_KEY = 'https://index.docker.io/v1/';

    private const DOCKER_HUB = 'docker.io';

    private const DOCKER_HUB_ALIASES = ['docker.io', 'index.docker.io', 'registry-1.docker.io', 'registry.hub.docker.com'];

    /** @param list<array{host: string, username: string, token: string}> $entries */
    private function __construct(private readonly array $entries)
    {
    }

    /**
     * @throws \InvalidArgumentException naming the line that is wrong
     */
    public static function parse(string $value): self
    {
        $entries = [];
        foreach (preg_split('/\R/', $value) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $fields = preg_split('/\s+/', $line) ?: [];
            $number = $index + 1;
            if (count($fields) !== 3) {
                throw new \InvalidArgumentException(
                    "registry-auth line {$number}: expected 'host username token', separated by spaces."
                );
            }
            $host = self::normalizeHost($fields[0]);
            if ($host === null) {
                throw new \InvalidArgumentException(
                    "registry-auth line {$number}: '{$fields[0]}' is not a registry host (e.g. ghcr.io, registry.example.com:5000)."
                );
            }
            if (isset($entries[$host])) {
                throw new \InvalidArgumentException("registry-auth line {$number}: {$host} is listed twice.");
            }
            $entries[$host] = ['host' => $host, 'username' => $fields[1], 'token' => $fields[2]];
        }

        return new self(array_values($entries));
    }

    /** Never throws: a stored value that no longer parses means no logins, and pulls stay anonymous. */
    public static function fromStored(?string $value): self
    {
        if ($value === null || trim($value) === '') {
            return new self([]);
        }
        try {
            return self::parse($value);
        } catch (\InvalidArgumentException) {
            return new self([]);
        }
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** @return list<string> */
    public function hosts(): array
    {
        return array_column($this->entries, 'host');
    }

    /** Whether an image is pulled from a registry this project has a login for. */
    public function covers(string $image): bool
    {
        return in_array(self::registryFor($image), $this->hosts(), true);
    }

    /**
     * The registry host an image reference addresses, the way Docker decides
     * it: the first path component is a host only when it has a dot or a port
     * or is `localhost`; everything else is Docker Hub.
     */
    public static function registryFor(string $image): string
    {
        $image = trim($image);
        $slash = strpos($image, '/');
        if ($slash === false) {
            return self::DOCKER_HUB;
        }
        $first = substr($image, 0, $slash);
        if (!str_contains($first, '.') && !str_contains($first, ':') && $first !== 'localhost') {
            return self::DOCKER_HUB;
        }

        return self::normalizeHost($first) ?? strtolower($first);
    }

    /**
     * What a deploy log must never show: each token, and the encoded login
     * that carries it.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $secrets = [];
        foreach ($this->entries as $entry) {
            $secrets[] = $entry['token'];
            $secrets[] = base64_encode($entry['username'] . ':' . $entry['token']);
        }

        return $secrets;
    }

    /** `{"auths": {...}}` for these logins, as `docker login` would have written it. */
    public function dockerConfigJson(): string
    {
        $auths = [];
        foreach ($this->entries as $entry) {
            $key = $entry['host'] === self::DOCKER_HUB ? self::DOCKER_HUB_KEY : $entry['host'];
            $auths[$key] = ['auth' => base64_encode($entry['username'] . ':' . $entry['token'])];
        }

        return (string) json_encode(['auths' => $auths], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    /** `ghcr.io`, `https://GHCR.io/` and `registry.example.com:5000` alike; null for anything else. */
    private static function normalizeHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = rtrim($host, '/');
        if (str_ends_with($host, '/v1') || str_ends_with($host, '/v2')) {
            $host = substr($host, 0, -3);
        }
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?$/', $host) !== 1) {
            return null;
        }

        return in_array($host, self::DOCKER_HUB_ALIASES, true) ? self::DOCKER_HUB : $host;
    }
}
