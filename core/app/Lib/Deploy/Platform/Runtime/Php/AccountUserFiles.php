<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use Illuminate\Contracts\Cache\Repository;

/**
 * /etc/passwd and /etc/group for a PHP app on our shared base image, with an
 * `app` entry for the account uid the container runs as.
 *
 * Without one, posix_getpwuid() returns false and apps that resolve their
 * process user by name (Passbolt) die. The image's own files are read on the
 * host once per tag; an image that is not ours is left alone.
 */
final class AccountUserFiles
{
    public const USER_NAME = 'app';

    private const CACHE_PREFIX = 'php-base-account-files:';

    /**
     * @param \Closure(list<string>): string $runOnHost
     */
    public function __construct(
        private readonly \Closure $runOnHost,
        private readonly Repository $cache
    ) {
    }

    /**
     * @return array{passwd: string, group: string}|null null when nothing has to be mounted
     */
    public function for(string $image, int $uid, int $gid): ?array
    {
        $files = $this->imageFiles($image);
        if ($files === null) {
            return null;
        }
        $passwd = self::passwdWith($files['passwd'], $uid, $gid);
        $group = self::groupWith($files['group'], $gid);

        return $passwd === null && $group === null
            ? null
            : ['passwd' => $passwd ?? $files['passwd'], 'group' => $group ?? $files['group']];
    }

    /**
     * The image's passwd with the account line appended; null when the uid has one already.
     */
    public static function passwdWith(string $passwd, int $uid, int $gid): ?string
    {
        if (self::hasId($passwd, $uid)) {
            return null;
        }

        return self::withLine($passwd, self::USER_NAME . ":x:{$uid}:{$gid}:" . self::USER_NAME . ':/app:/usr/sbin/nologin');
    }

    public static function groupWith(string $group, int $gid): ?string
    {
        if (self::hasId($group, $gid)) {
            return null;
        }

        return self::withLine($group, self::USER_NAME . ":x:{$gid}:");
    }

    /**
     * Compose volume entries, relative to the run file the files sit next to.
     *
     * @return list<string>
     */
    public static function volumes(): array
    {
        return [
            './' . EngineArtifacts::RUN_PASSWD . ':/etc/passwd:ro',
            './' . EngineArtifacts::RUN_GROUP . ':/etc/group:ro',
        ];
    }

    /**
     * @return array{passwd: string, group: string}|null
     */
    private function imageFiles(string $image): ?array
    {
        if (!ImageTransfer::isSafeImageRef($image)) {
            return null;
        }
        $key = self::CACHE_PREFIX . $image;
        $cached = $this->cache->get($key);
        if (is_array($cached) && is_string($cached['passwd'] ?? null) && is_string($cached['group'] ?? null)) {
            return ['passwd' => $cached['passwd'], 'group' => $cached['group']];
        }

        try {
            $files = ['passwd' => $this->cat($image, '/etc/passwd'), 'group' => $this->cat($image, '/etc/group')];
        } catch (\Exception $e) {
            return null;
        }
        // An image with no root line is not one we can extend safely.
        if (!self::hasId($files['passwd'], 0) || !self::hasId($files['group'], 0)) {
            return null;
        }
        $this->cache->forever($key, $files);

        return $files;
    }

    private function cat(string $image, string $path): string
    {
        // --pull never: only the host's own copy of our image is read, never a registry's.
        return ($this->runOnHost)([
            'sudo', 'docker', 'run', '--rm', '--pull', 'never', '--network', 'none',
            '--entrypoint', 'cat', $image, $path,
        ]);
    }

    private static function hasId(string $file, int $id): bool
    {
        foreach (preg_split('/\r?\n/', $file) ?: [] as $line) {
            $fields = explode(':', $line);
            if (count($fields) >= 3 && $fields[2] === (string) $id) {
                return true;
            }
        }

        return false;
    }

    private static function withLine(string $file, string $line): string
    {
        $file = rtrim($file, "\n");

        return ($file === '' ? '' : $file . "\n") . $line . "\n";
    }
}
