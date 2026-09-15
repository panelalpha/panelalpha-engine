<?php

namespace App\Lib\Deploy\Dind;

use Illuminate\Support\Facades\Log;

/**
 * One host build at a time, whatever the queue is doing.
 *
 * A host build is the one part of a deploy that competes for the *host's* RAM.
 * {@see \App\Lib\Deploy\Compose\ServiceLimits::hostBuildMemoryMb()} gives each
 * one a third of MemTotal and hands the runtime a heap cap at 70% of that --
 * numbers chosen so a single build can be big enough for the ones that need it
 * (Chamilo 2.x's Encore pass wants ~3.5 GB of heap and OOMs below it).
 *
 * That sizing assumed one build at a time, which is what a single
 * queue worker used to guarantee. It is 8 now (QUEUE_WORKERS), and `DeployLock` does not
 * help: it is explicitly one deploy per *account*, so eight accounts build
 * together by design. Eight builds each entitled to a third of the host is 1.4x
 * to 2.8x its RAM -- and the heap cap is not a ceiling that shrinks demand, it
 * is an instruction to grow, so they will try. What the kernel does then is
 * pick a victim by badness, which can be another tenant's container or the
 * engine's own.
 *
 * So the build serialises and the rest of the deploy does not. Cloning,
 * `compose up`, migrations and health checks still run eight wide, which is
 * where the batch speedup actually comes from -- a deploy mostly waits on a
 * container rather than on the host.
 *
 * **Fails open.** If the slot cannot be taken within {@see WAIT_SECONDS} the
 * build runs anyway, with a line in the log saying so. A queued build is better
 * than an OOM-killed host; a build blocked for ever behind a stuck lock is
 * worse than either, and that is the failure this refuses to introduce.
 */
final class HostBuildSlot
{
    /**
     * Long enough for a real build to finish ahead of this one -- the PHP and
     * Node host builds cap at 1800s and 3600s -- without ever being a deadline
     * a deploy dies on, because passing it is not fatal.
     */
    private const WAIT_SECONDS = 1800;

    private const POLL_MICROSECONDS = 250000;

    private const LOCK_FILE = 'host-build.lock';

    /**
     * Run `$build` with the host's build slot held, releasing it either way.
     *
     * @template T
     * @param callable(): T $build
     * @param ?callable(): void $onWait called once when the slot is busy, so a
     *        deploy log can say why it is standing still
     * @return T
     */
    public static function run(callable $build, ?callable $onWait = null): mixed
    {
        $handle = self::acquire($onWait);

        try {
            return $build();
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * @param ?callable(): void $onWait
     * @return resource|null the held lock, or null when the build proceeds unslotted
     */
    private static function acquire(?callable $onWait)
    {
        $handle = @fopen(storage_path(self::LOCK_FILE), 'c');
        if ($handle === false) {
            // Nowhere to put the lock is not a reason to refuse to build.
            Log::warning('Host build slot unavailable; building without it.');

            return null;
        }

        $deadline = microtime(true) + self::WAIT_SECONDS;
        $waited = false;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (!$waited) {
                $waited = true;
                if ($onWait !== null) {
                    $onWait();
                }
            }
            if (microtime(true) >= $deadline) {
                fclose($handle);
                Log::warning('Host build slot still held after ' . self::WAIT_SECONDS . 's; building anyway.');

                return null;
            }
            usleep(self::POLL_MICROSECONDS);
        }

        return $handle;
    }
}
