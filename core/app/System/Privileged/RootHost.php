<?php

namespace App\System\Privileged;

use App\Lib\Deploy\Dind\DindImageStore;

/** Commands in the host's namespaces. */
final class RootHost
{
    private const AREA = 'host';

    private const ENGINE_CERTIFICATE_FLAGS = ['staging', 'force-renewal', 'skip-dns-check', 'dry-run'];

    private const CACHE_OWNER = '/\A\d+:\d+\z/';

    private const HTTP_METHODS = ['GET', 'HEAD', 'DELETE'];

    private const HTTP_URL = '~\Ahttps?://[^\s\x00-\x1f\x7f]+\z~';

    private const HTTP_HEADERS = ['Accept'];

    private const LINE_BREAK = '~[\x00\n\r]~';

    private const EMAIL = '/\A[^\s@-][^\s@]*@[^\s@]+\z/';

    /**
     * The account's node_modules volume and the images only it pulled, removed through the host's daemon.
     *
     * @param  list<string>  $images  sidecar images only this account pulled
     * @return list<string>
     */
    public function removeAccountDockerStore(string $volume, array $images): array
    {
        return $this->onHost(__FUNCTION__, [
            Arguments::name($volume),
            ...$this->counted(Arguments::images($images)),
        ]);
    }

    /**
     * The account's caches and its ~/docker, removed on disk.
     *
     * @param  list<string>  $legacyCaches
     * @return list<string>
     */
    public function removeAccountDockerFiles(string $projectCache, array $legacyCaches, string $dockerDir): array
    {
        return $this->onHost(__FUNCTION__, [
            Arguments::path($projectCache),
            ...$this->counted(Arguments::paths($legacyCaches)),
            Arguments::path($dockerDir),
        ]);
    }

    /** @return list<string> */
    public function applyBuildNetworkFirewall(string $network): array
    {
        return $this->onHost(__FUNCTION__, [Arguments::name($network)]);
    }

    /**
     * Creates the tenant network when it is missing and applies its firewall.
     *
     * @return list<string>
     */
    public function applyTenantNetworkFirewall(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /**
     * Pulls one of our images from the cache registry writer unless the host has it.
     *
     * @return list<string>
     */
    public function ensureOwnImage(string $image): array
    {
        Arguments::image(DindImageStore::HOST_CACHE_REGISTRY.'/'.Arguments::image($image));

        return $this->onHost(__FUNCTION__, [$image]);
    }

    /**
     * Pulls again through the host's registry mirror.
     *
     * @return list<string>
     */
    public function refreshPublicImage(string $image): array
    {
        return $this->onHost(__FUNCTION__, [Arguments::image($image)]);
    }

    /**
     * cgroup readings of every running project container.
     *
     * @param  array<int, string>  $usernames
     * @return list<string>
     */
    public function projectStats(array $usernames, int $timeout): array
    {
        if ($timeout < 1) {
            throw InvalidArgument::of('timeout', (string) $timeout);
        }

        return $this->onHost(__FUNCTION__, [(string) $timeout, ...array_map(Arguments::username(...), array_values($usernames))]);
    }

    /**
     * @param  array<string, string>  $homes  username => home directory
     * @return list<string>
     */
    public function projectDisk(array $homes): array
    {
        return $this->onHost(__FUNCTION__, ActionValues::pairs($this->byUsername($homes, Arguments::path(...))));
    }

    /**
     * @param  array<string, array{home: string, docroot: string}>  $docroots  username => paths on the host
     * @return list<string>
     */
    public function classicAppTypes(array $docroots): array
    {
        $checked = $this->byUsername($docroots, static fn (array $paths): array => [
            'home' => Arguments::path($paths['home']),
            'docroot' => Arguments::path($paths['docroot']),
        ]);

        return $this->onHost(__FUNCTION__, $this->docrootValues($checked));
    }

    /** @return list<string> */
    public function edgeProbe(string $domain, string $ip, int $timeout, bool $https): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw InvalidArgument::of('ip', $ip);
        }

        return $this->onHost(__FUNCTION__, [Arguments::hostname($domain), $ip, (string) $timeout, $https ? 'https' : '']);
    }

    /** @return list<string> */
    public function lxcfsFiles(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /**
     * Hands each cache directory, never what a build left in it, to $identity (`uid:gid`) and moves the account's older
     * caches in.
     *
     * @return list<string>
     */
    public function prepareProjectCache(string $username, string $identity): array
    {
        if (preg_match(self::CACHE_OWNER, $identity) !== 1) {
            throw InvalidArgument::of('cache owner', $identity);
        }

        return $this->onHost(__FUNCTION__, [Arguments::username($username), $identity]);
    }

    /**
     * @param  array<int, string>  $skipUsernames
     * @return list<string>
     */
    public function pruneProjectCaches(int $maxAgeSeconds, bool $dryRun, array $skipUsernames): array
    {
        if ($maxAgeSeconds < 0) {
            throw InvalidArgument::of('cache age', (string) $maxAgeSeconds);
        }

        return $this->onHost(__FUNCTION__, [
            (string) $maxAgeSeconds, $dryRun ? 'dry-run' : '', ...array_map(Arguments::username(...), array_values($skipUsernames)),
        ]);
    }

    /**
     * GET, HEAD or DELETE to the cache registry writer, from the host's network namespace.
     *
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    public function httpRequest(string $method, string $url, array $headers): array
    {
        $this->assertHttpRequest($method, $url);
        foreach ($headers as $name => $value) {
            $this->header((string) $name, $value);
        }

        return $this->onHost(__FUNCTION__, [$method, $url, ...ActionValues::pairs($headers)]);
    }

    /**
     * @param  array{domain?: ?string, email?: ?string, ip?: ?string}  $values
     * @param  list<string>  $flags
     * @return list<string>
     */
    public function requestEngineCertificate(string $engineDir, array $values, array $flags): array
    {
        $checked = $this->certificateValues($values);
        $known = array_values(array_intersect(self::ENGINE_CERTIFICATE_FLAGS, $flags));

        return $this->onHost(__FUNCTION__, [
            Arguments::path($engineDir), $checked['domain'] ?? '', $checked['email'] ?? '', $checked['ip'] ?? '', ...$known,
        ]);
    }

    /** @return list<string> */
    public function detectStuckDind(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function recoverStuckDind(bool $wipeInner, bool $recreate): array
    {
        return $this->onHost(__FUNCTION__, $this->recoveryValues($wipeInner, $recreate));
    }

    /** @return list<string> */
    public function recoverDindAccount(string $username, bool $wipeInner, bool $recreate): array
    {
        return $this->onHost(__FUNCTION__, [Arguments::username($username), ...$this->recoveryValues($wipeInner, $recreate)]);
    }

    /** @return list<string> */
    public function enqueueEngineUpdate(string $engineDir, ?string $licenseKey): array
    {
        $key = $licenseKey === null || $licenseKey === '' ? '' : Arguments::operand($licenseKey);

        return $this->onHost(__FUNCTION__, [Arguments::path($engineDir), $key]);
    }

    /** @return list<string> */
    public function seedEngineUpdatePlaceholder(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function recordEngineUpdateRefusal(string $output, int $exitCode): array
    {
        return $this->onHost(__FUNCTION__, [$output, (string) $exitCode]);
    }

    /** @return list<string> */
    public function changeWebserver(string $engineDir, string $webserver, ?string $serialNumber): array
    {
        $serial = $serialNumber ?? '';

        return $this->onHost(__FUNCTION__, [
            Arguments::path($engineDir), Arguments::name($webserver), $serial === '' ? '' : Arguments::operand($serial),
        ]);
    }

    /**
     * `kill -0` on a host process.
     *
     * @return list<string>
     */
    public function processExists(int $pid): array
    {
        if ($pid < 1) {
            throw InvalidArgument::of('pid', (string) $pid);
        }

        return $this->onHost(__FUNCTION__, [(string) $pid]);
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $values
     * @param  callable(T): T  $check
     * @return array<string, T>
     */
    private function byUsername(array $values, callable $check): array
    {
        $checked = [];
        foreach ($values as $username => $value) {
            $checked[Arguments::username((string) $username)] = $check($value);
        }

        return $checked;
    }

    /** @return list<string> */
    private function recoveryValues(bool $wipeInner, bool $recreate): array
    {
        return [$wipeInner ? 'wipe-inner' : '', $recreate ? 'recreate' : ''];
    }

    /**
     * @param  array<string, array{home: string, docroot: string}>  $docroots
     * @return list<string>
     */
    private function docrootValues(array $docroots): array
    {
        $values = [(string) count($docroots)];
        foreach ($docroots as $username => $paths) {
            array_push($values, (string) $username, $paths['home'], $paths['docroot']);
        }

        return $values;
    }

    /**
     * @param  array<int, string>  $items
     * @return list<string>
     */
    private function counted(array $items): array
    {
        return [(string) count($items), ...array_values($items)];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function onHost(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, $values, true);
    }

    private function assertHttpRequest(string $method, string $url): void
    {
        if (! in_array($method, self::HTTP_METHODS, true)) {
            throw InvalidArgument::of('HTTP method', $method);
        }
        if (preg_match(self::HTTP_URL, $url) !== 1 || ! str_starts_with($url, 'http://'.DindImageStore::HOST_CACHE_REGISTRY.'/v2/')) {
            throw InvalidArgument::of('URL', $url);
        }
    }

    private function header(string $name, string $value): string
    {
        if (! in_array($name, self::HTTP_HEADERS, true) || preg_match(self::LINE_BREAK, $value) === 1) {
            throw InvalidArgument::of('HTTP header', $name.': '.$value);
        }

        return "{$name}: {$value}";
    }

    /**
     * @param  array{domain?: ?string, email?: ?string, ip?: ?string}  $values
     * @return array<string, string>
     */
    private function certificateValues(array $values): array
    {
        $checks = [
            'domain' => fn (string $value): bool => self::passes(fn () => Arguments::hostname($value)),
            'email' => fn (string $value): bool => preg_match(self::EMAIL, $value) === 1,
            'ip' => fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP) !== false,
        ];
        $options = [];
        foreach ($checks as $name => $valid) {
            $value = $values[$name] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            if (! $valid($value)) {
                throw InvalidArgument::of($name, $value);
            }
            $options[$name] = $value;
        }

        return $options;
    }

    private static function passes(\Closure $check): bool
    {
        try {
            $check();
        } catch (InvalidArgument) {
            return false;
        }

        return true;
    }
}
