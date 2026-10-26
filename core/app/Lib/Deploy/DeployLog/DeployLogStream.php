<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * Process-local NDJSON tee for streamed deploy responses
 * (`X-Deploy-Stream: ndjson`).
 *
 * Static on purpose: Dind and friends bind their own logger instances through
 * {@see DeployLogger::current()}, but every line written during this request
 * has to reach the same HTTP stream.
 */
final class DeployLogStream
{
    /** @var callable|null */
    private static $emitter = null;

    public static function to(callable $emitter): void
    {
        self::$emitter = $emitter;
    }

    public static function stop(): void
    {
        self::$emitter = null;
    }

    /**
     * Best-effort: the log files stay the source of truth, so a broken stream
     * must never break a deploy.
     *
     * @param  array<string, mixed>  $frame
     */
    public static function emit(array $frame): void
    {
        if (self::$emitter === null) {
            return;
        }

        try {
            (self::$emitter)($frame);
        } catch (\Throwable) {
        }
    }
}
