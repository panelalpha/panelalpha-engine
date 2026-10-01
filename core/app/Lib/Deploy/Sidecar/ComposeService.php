<?php

namespace App\Lib\Deploy\Sidecar;

/**
 * One Compose service, answering the questions the sidecar logic keeps
 * asking: what image, what ports, what variables, what it depends on.
 *
 * Compose accepts several spellings for each of those — `environment` as a
 * map or a list, `ports` as a string, a mapping or a long-form object — and
 * this is the one place that has to know.
 */
final class ComposeService
{
    /**
     * @param array<string, mixed> $service
     */
    private function __construct(private readonly array $service)
    {
    }

    /**
     * @param array<string, mixed> $service
     */
    public static function of(array $service): self
    {
        return new self($service);
    }

    public function image(): string
    {
        $image = $this->service['image'] ?? null;

        return is_string($image) ? trim($image) : '';
    }

    /**
     * The bare image name, with registry, tag and digest removed.
     *
     * Order matters: the digest goes first because it contains a colon of its
     * own (postgres@sha256:abc…), and the repository path is split before the
     * tag because a private registry may carry a port (registry:5000/postgres).
     */
    public static function familyOf(string $image): string
    {
        $repository = explode('@', strtolower(trim($image)), 2)[0];
        $segments = explode('/', $repository);

        return explode(':', (string) end($segments), 2)[0];
    }

    public function imageFamily(): string
    {
        return self::familyOf($this->image());
    }

    /**
     * Ports the service declares for itself, from `expose:` and from the
     * container side of `ports:`, merged with whatever the caller observed on
     * the image so both sources feed the same decision.
     *
     * @param list<int> $observedPorts
     * @return list<int>
     */
    public function ports(array $observedPorts = []): array
    {
        $ports = array_merge($observedPorts, $this->exposedPorts(), $this->publishedPorts());

        return array_values(array_unique(array_filter($ports, static fn (int $p): bool => $p > 0)));
    }

    /**
     * Env keys in either Compose form, uppercased. Values are irrelevant here
     * — the presence of the name is the evidence.
     *
     * @return list<string>
     */
    public function environmentKeys(): array
    {
        $environment = $this->service['environment'] ?? null;
        if (!is_array($environment)) {
            return [];
        }

        $keys = [];
        foreach ($environment as $key => $value) {
            $keys[] = is_int($key)
                ? strtoupper(trim(explode('=', is_string($value) ? $value : '', 2)[0]))
                : strtoupper(trim((string) $key));
        }

        return array_values(array_filter($keys, static fn (string $k): bool => $k !== ''));
    }

    /**
     * The environment as uppercased name => value, list or map form.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $environment = $this->service['environment'] ?? null;
        $values = [];
        foreach (is_array($environment) ? $environment : [] as $key => $value) {
            if (is_int($key)) {
                [$key, $value] = array_pad(explode('=', is_string($value) ? $value : '', 2), 2, '');
            }
            $key = strtoupper(trim((string) $key));
            if ($key !== '') {
                $values[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $values;
    }

    /**
     * @return list<string> lowercase service names
     */
    public function dependencyNames(): array
    {
        $dependsOn = $this->service['depends_on'] ?? [];
        if (!is_array($dependsOn)) {
            return [];
        }

        $names = [];
        foreach ($dependsOn as $key => $value) {
            $names[] = strtolower((string) (is_int($key) ? $value : $key));
        }
        // A link (`jackett` or `db:alias`) is a dependency just as depends_on is.
        foreach ((array) ($this->service['links'] ?? []) as $link) {
            if (is_string($link)) {
                $names[] = strtolower(trim(explode(':', $link, 2)[0]));
            }
        }

        return array_values(array_filter($names, static fn (string $n): bool => $n !== ''));
    }

    /**
     * @return list<int>
     */
    private function exposedPorts(): array
    {
        $ports = [];
        foreach ((array) ($this->service['expose'] ?? []) as $exposed) {
            $port = self::containerPort(is_array($exposed) ? '' : (string) $exposed);
            if ($port !== null) {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    /**
     * @return list<int>
     */
    private function publishedPorts(): array
    {
        $ports = [];
        foreach ((array) ($this->service['ports'] ?? []) as $mapping) {
            $port = is_array($mapping) ? self::longFormPort($mapping) : self::shortFormPort((string) $mapping);
            if ($port !== null) {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private static function longFormPort(array $mapping): ?int
    {
        $target = $mapping['target'] ?? null;

        return is_int($target) || (is_string($target) && $target !== '') ? (int) $target : null;
    }

    /**
     * "5432", "5432:5432", "127.0.0.1:15432:5432/tcp" — the container port is
     * always the last colon-separated field.
     */
    private static function shortFormPort(string $mapping): ?int
    {
        $fields = explode(':', $mapping);

        return self::containerPort((string) end($fields));
    }

    private static function containerPort(string $value): ?int
    {
        $value = trim(explode('/', trim($value), 2)[0]);

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }
}
