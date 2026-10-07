<?php

namespace App\System\Project\PhpHosting\Services;

/**
 * A long-running process of a PHP hosting account, as a stack declares it.
 * Which init runs it, and how, is the {@see ServiceManager}'s business.
 */
final class Service
{
    /**
     * @param string $command runs the process in the foreground
     * @param array<string, string> $daemonStart an init script (file => shell)
     *        that starts it as a daemon at boot instead, for an init that
     *        does not keep it in the foreground
     */
    public function __construct(
        public readonly string $name,
        public readonly string $command,
        public readonly array $daemonStart = [],
    ) {
    }
}
