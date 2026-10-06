<?php

namespace App\Lib\Deploy\DeployLog;

use App\Lib\Deploy\SafeName;

/**
 *   storage/logs/deploy/{username}/{deployId}.log   JSON lines, one per entry
 *   storage/logs/deploy/{username}/latest.json      status pointer
 *   storage/logs/deploy/{username}/.deploy.lock     one deploy at a time
 *   storage/logs/deploy/{username}/.queue.lock      one request queueing or starting one
 */
final class DeployLogPaths
{
    public const LOG_EXTENSION = '.log';

    private const LATEST_FILE = 'latest.json';

    private const LOCK_FILE = '.deploy.lock';

    private const QUEUE_LOCK_FILE = '.queue.lock';

    public function __construct(public readonly string $username)
    {
        // Every path here is built from the username, so it is validated once, here.
        self::assertSafeName($username, 'username for deploy log');
    }

    public static function base(): string
    {
        return storage_path('logs/deploy');
    }

    public static function userDir(string $username): string
    {
        return (new self($username))->directory();
    }

    public static function assertSafeName(string $name, string $subject): void
    {
        SafeName::assert($name, $subject);
    }

    public function directory(): string
    {
        return self::base() . '/' . $this->username;
    }

    public function log(string $deployId): string
    {
        return $this->directory() . '/' . $deployId . self::LOG_EXTENSION;
    }

    public function latest(): string
    {
        return $this->directory() . '/' . self::LATEST_FILE;
    }

    public function lock(): string
    {
        return $this->directory() . '/' . self::LOCK_FILE;
    }

    public function queueLock(): string
    {
        return $this->directory() . '/' . self::QUEUE_LOCK_FILE;
    }

    /**
     * @return list<string> absolute paths, newest id last
     */
    public function logFiles(): array
    {
        return glob($this->directory() . '/*' . self::LOG_EXTENSION) ?: [];
    }
}
