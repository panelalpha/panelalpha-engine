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

    /** The app ran nothing when a redeploy started: a cancel must leave it stopped again. */
    public const STOPPED = 'stopped';

    /** The app's containers could not be read when a redeploy started: it may be live, so a cancel removes nothing. */
    public const UNREAD = 'unread';

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
     * @param  array<string, mixed>  $entry
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
        return DeployLogPaths::userDir($username).'/'.self::FILE;
    }

    /**
     * Accounts with something left to settle.
     *
     * @return list<string>
     */
    public static function usernames(): array
    {
        $names = [];
        foreach (glob(DeployLogPaths::base().'/*/'.self::FILE) ?: [] as $file) {
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
        $this->withLock(function () use ($key, $value): void {
            $this->write([$key => $value] + $this->all());
        });
    }

    public function forget(string $key): void
    {
        $this->withLock(function () use ($key): void {
            $state = $this->all();
            unset($state[$key]);
            $this->write($state);
        });
    }

    /**
     * Read-modify-write under a flock on a sibling file: not every writer holds the deploy lock
     * ({@see CheckoutAside::moveAside()}), and two renames would drop each other's keys.
     * Without a lock file it writes anyway rather than fail a deploy.
     *
     * @param  callable(): void  $change
     */
    private function withLock(callable $change): void
    {
        $path = self::path($this->username);
        self::ensureDirectory($path);
        $lockPath = $path.'.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            $change();

            return;
        }

        try {
            @flock($handle, LOCK_EX);
            $change();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     *
     * @throws \RuntimeException so a record written before an irreversible move is known to have landed
     */
    private function write(array $state): void
    {
        $path = self::path($this->username);
        if ($state === []) {
            @unlink($path);

            return;
        }
        self::ensureDirectory($path);
        $tmp = $path.'.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES)) === false || ! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException("Could not write the generation state of {$this->username}.");
        }
    }

    private static function ensureDirectory(string $path): void
    {
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
    }
}
