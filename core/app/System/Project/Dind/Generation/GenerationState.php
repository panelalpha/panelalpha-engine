<?php

namespace App\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Deploy\DeployLog\ProcessIdentity;

/**
 * What a redeploy put beside the running app and has not taken away yet. Next
 * to the deploy lock, so a sweep tells a live deploy from one that died.
 */
final class GenerationState
{
    public const NEXT = 'next';

    public const CHECKOUT = 'checkout';

    public const IMAGES = 'images';

    public const ROUTES = 'routes';

    private const FILE = 'generation.json';

    /** How long the sweep leaves an entry to the live process that wrote it. */
    public const OWNER_GRACE_SECONDS = 600;

    public function __construct(private readonly string $username)
    {
        DeployLogPaths::assertSafeName($username, 'username for deploy generation');
    }

    /** @return array{pid: int, start: ?string, at: int} */
    public static function owner(): array
    {
        $pid = (int) getmypid();

        return ['pid' => $pid, 'start' => ProcessIdentity::startTime($pid), 'at' => time()];
    }

    /**
     * Whether another process still running wrote $entry a moment ago: a
     * checkout moved aside before a pull takes the deploy lock.
     *
     * @param array<string, mixed> $entry
     */
    public static function ownedElsewhere(array $entry, ?int $now = null): bool
    {
        $owner = is_array($entry['owner'] ?? null) ? $entry['owner'] : null;
        if ($owner === null || ($owner['pid'] ?? null) === (int) getmypid()) {
            return false;
        }
        if (($now ?? time()) - (int) ($owner['at'] ?? 0) > self::OWNER_GRACE_SECONDS) {
            return false;
        }

        return ProcessIdentity::isStillRunning($owner['pid'] ?? null, $owner['start'] ?? null);
    }

    public static function path(string $username): string
    {
        return DeployLogPaths::userDir($username) . '/' . self::FILE;
    }

    /**
     * Accounts with something left to settle.
     *
     * @return list<string>
     */
    public static function usernames(): array
    {
        $names = [];
        foreach (glob(DeployLogPaths::base() . '/*/' . self::FILE) ?: [] as $file) {
            $names[] = basename(dirname($file));
        }

        return $names;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $raw = @file_get_contents(self::path($this->username));
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return ?array<string, mixed> */
    public function get(string $key): ?array
    {
        $value = $this->all()[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $value */
    public function put(string $key, array $value): void
    {
        $this->write([$key => $value] + $this->all());
    }

    public function forget(string $key): void
    {
        $state = $this->all();
        unset($state[$key]);
        $this->write($state);
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        $path = self::path($this->username);
        if ($state === []) {
            @unlink($path);

            return;
        }
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES));
        rename($tmp, $path);
    }
}
