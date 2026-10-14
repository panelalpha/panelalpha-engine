<?php

namespace App\Lib\Git;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\DB;

/**
 * A project's SSH deploy key: an ed25519 pair whose public half the customer
 * adds to one repository as a read-only deploy key, and the host keys a clone
 * with it is allowed to trust.
 *
 * The private half is stored encrypted in the project details and only ever
 * leaves them as a 0600 file for the length of one git command
 * ({@see \App\System\Project\Git\WorkTree::execute()}). Host keys are pinned:
 * github.com, gitlab.com and bitbucket.org from their published keys, any
 * other host from one ssh-keyscan when the caller names it.
 */
class DeployKey
{
    public const DETAIL = 'git_deploy_key';

    /** Hosts whose published keys ship with the engine. */
    public const SHIPPED_HOSTS = ['github.com', 'gitlab.com', 'bitbucket.org'];

    private const HOST_PATTERN = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    public function __construct(private readonly System $system)
    {
    }

    /**
     * Create the key unless the project has one, and pin `$host` if it is not
     * pinned yet. Never rotates: a key already registered with a forge keeps working.
     *
     * The key is re-read and written under a row lock on the project, so two
     * concurrent calls return the same key and keep both pins.
     *
     * @return array<string, mixed>
     */
    public function ensure(User $user, ?string $host): array
    {
        $host = $host !== null && trim($host) !== '' ? self::knownHostsName($host) : null;
        [$fresh, $scanned] = $this->prepareBeforeTheLock($user, $host);

        return DB::transaction(fn (): array => $this->ensureLocked($user, $host, $fresh, $scanned));
    }

    /**
     * ssh-keygen and ssh-keyscan (10 s per address) run before the lock: on SQLite the
     * transaction is BEGIN IMMEDIATE, the whole database's write lock.
     *
     * @return array{0: ?array<string, mixed>, 1: ?list<string>} a new key when there was none, and the host's scanned keys
     */
    private function prepareBeforeTheLock(User $user, ?string $host): array
    {
        $seen = self::stored($user);
        $fresh = $seen === null ? $this->generate('panelalpha-' . $user->username) : null;
        $scanned = self::needsPin($seen, $host) ? $this->scan($host) : null;

        return [$fresh, $scanned];
    }

    /**
     * Re-reads the key under the row lock and saves what changed. The caller's $user is
     * refreshed from the locked row, since the response is built from it.
     *
     * @param ?array<string, mixed> $fresh
     * @param ?list<string> $scanned
     * @return array<string, mixed>
     */
    private function ensureLocked(User $user, ?string $host, ?array $fresh, ?array $scanned): array
    {
        /** @var User $locked */
        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

        $key = self::stored($locked);
        $created = false;
        if ($key === null) {
            $key = $fresh ?? $this->generate('panelalpha-' . $locked->username);
            $created = true;
        }

        if (self::needsPin($key, $host)) {
            $key['known_hosts'][$host] = $scanned ?? $this->scan($host);
        }

        if ($created || $host !== null) {
            $locked->setDetails([self::DETAIL => $key]);
            $locked->save();
        }

        $user->setRawAttributes($locked->getAttributes(), true);

        return ['created' => $created] + self::view($key);
    }

    /**
     * @param ?array<string, mixed> $key
     */
    private static function needsPin(?array $key, ?string $host): bool
    {
        return $host !== null && !self::isShipped($host) && !isset($key['known_hosts'][$host]);
    }

    /** False when the project had no key. */
    public static function delete(User $user): bool
    {
        if (self::stored($user) === null) {
            return false;
        }
        $user->setDetails([self::DETAIL => null]);
        $user->save();

        return true;
    }

    /**
     * @return ?array{private_key: string, public_key: string, fingerprint: string, known_hosts: array<string, list<string>>}
     */
    public static function stored(User $user): ?array
    {
        $key = $user->getDetails()[self::DETAIL] ?? null;
        if (!is_array($key) || !is_string($key['private_key'] ?? null) || $key['private_key'] === '') {
            return null;
        }
        $key['known_hosts'] = is_array($key['known_hosts'] ?? null) ? $key['known_hosts'] : [];

        return $key;
    }

    /** The known_hosts file a clone with this key trusts: the shipped forges plus the pinned hosts. */
    public static function knownHosts(User $user): string
    {
        $lines = [rtrim((string) file_get_contents(self::shippedFile()), "\n")];
        foreach (self::stored($user)['known_hosts'] ?? [] as $hostLines) {
            foreach ((array) $hostLines as $line) {
                $lines[] = (string) $line;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** Whether a clone from `$host` (known_hosts spelling) can verify the server. */
    public static function pins(User $user, string $host): bool
    {
        return self::isShipped($host) || isset(self::stored($user)['known_hosts'][$host]);
    }

    /**
     * `host` or `host:port` as known_hosts names it: `[host]:port` off port 22.
     *
     * @throws ProblemException when it is not a host name
     */
    public static function knownHostsName(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $port = 22;
        if (preg_match('/^(.+):(\d{1,5})$/', $raw, $m) === 1) {
            [$raw, $port] = [$m[1], (int) $m[2]];
        }
        if (preg_match(self::HOST_PATTERN, $raw) !== 1 || $port < 1 || $port > 65535) {
            throw ProblemException::one('host', 'host_invalid', "'{$raw}' is not a host name.", [
                'expected' => 'a host name, optionally with :port',
                'examples' => ['git.example.com', 'git.example.com:2222'],
            ]);
        }

        return $port === 22 ? $raw : "[{$raw}]:{$port}";
    }

    /** `SHA256:…` of a public key or known_hosts line, as ssh-keygen -l prints it. */
    public static function fingerprint(string $keyLine): string
    {
        $blob = '';
        foreach (preg_split('/\s+/', trim($keyLine)) ?: [] as $part) {
            if (str_starts_with($part, 'AAAA')) {
                $blob = (string) base64_decode($part, true);
                break;
            }
        }

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * @param array{public_key: string, fingerprint: string, known_hosts: array<string, list<string>>} $key
     * @return array<string, mixed>
     */
    private static function view(array $key): array
    {
        $hosts = array_map(static fn (string $h): array => ['host' => $h, 'pinned' => 'published'], self::SHIPPED_HOSTS);
        foreach ($key['known_hosts'] as $host => $lines) {
            $hosts[] = [
                'host' => $host,
                'pinned' => 'scanned',
                'fingerprints' => array_map(self::fingerprint(...), (array) $lines),
            ];
        }

        return [
            'public_key' => $key['public_key'],
            'fingerprint' => $key['fingerprint'],
            'hosts' => $hosts,
        ];
    }

    /**
     * @return array{private_key: string, public_key: string, fingerprint: string, known_hosts: array<string, list<string>>}
     */
    private function generate(string $comment): array
    {
        $dir = sys_get_temp_dir() . '/pa-deploy-key-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException('Could not create a directory for the deploy key.');
        }
        try {
            $this->system->exec(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $comment, '-f', $dir . '/key']);
            $private = (string) @file_get_contents($dir . '/key');
            $public = trim((string) @file_get_contents($dir . '/key.pub'));
        } finally {
            @unlink($dir . '/key');
            @unlink($dir . '/key.pub');
            @rmdir($dir);
        }
        if ($private === '' || $public === '') {
            throw new \RuntimeException('ssh-keygen produced no key.');
        }

        return [
            'private_key' => $private,
            'public_key' => $public,
            'fingerprint' => self::fingerprint($public),
            'known_hosts' => [],
        ];
    }

    /**
     * The host's keys, as known_hosts lines, read once and then trusted.
     *
     * @return list<string>
     */
    private function scan(string $host): array
    {
        [$name, $port] = preg_match('/^\[(.+)\]:(\d+)$/', $host, $m) === 1 ? [$m[1], $m[2]] : [$host, '22'];
        try {
            $output = $this->system->exec(['ssh-keyscan', '-T', '10', '-p', $port, '-t', 'ed25519,ecdsa,rsa', $name]);
        } catch (\Throwable) {
            $output = '';
        }

        $lines = self::keyLines($output, $host);
        if ($lines === []) {
            throw ProblemException::one('host', 'host_unreachable',
                "Could not read the SSH host keys of {$host}: nothing answered on port {$port}.", [
                    'expected' => 'a git host that answers SSH',
                ]);
        }

        return $lines;
    }

    /**
     * ssh-keyscan names the host the way it was asked; each line is pinned the way ssh will look it up.
     *
     * @return list<string>
     */
    private static function keyLines(string $output, string $host): array
    {
        $lines = [];
        foreach (explode("\n", $output) as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) >= 3 && !str_starts_with($parts[0], '#') && str_starts_with($parts[2], 'AAAA')) {
                $lines[] = $host . ' ' . $parts[1] . ' ' . $parts[2];
            }
        }

        return $lines;
    }

    private static function isShipped(string $host): bool
    {
        return in_array($host, self::SHIPPED_HOSTS, true);
    }

    private static function shippedFile(): string
    {
        return dirname(__DIR__, 3) . '/resources/git/known_hosts';
    }
}
