<?php

namespace App\Console\Commands\System;

use App\System;
use Illuminate\Console\Command;

/**
 * Clear the Docker Hub proxy's cache by restarting it.
 *
 * The proxy expires its own entries (`proxy.ttl`, with `storage.delete.enabled`
 * so expiry frees the bytes rather than only running), which covers the layer
 * blobs. It cannot cover its own residue: `OnManifestExpire` deletes the
 * manifest link and never calls the vacuum, so the manifest blob and its tag
 * links stay on disk for good. Kilobytes per digest, forever.
 *
 * The store is a tmpfs, so a restart is what fully clears it -- blobs,
 * manifests, tag links and scheduler state alike -- and it needs no shell and
 * no `rm`.
 *
 * Nothing schedules this, deliberately. A restart takes the proxy away for a
 * couple of seconds, and while an account daemon should fall through to
 * Docker Hub on a connection refusal, that has not been tested against a pull
 * in flight and a customer's install is not the place to find out. The
 * residue this clears is ~28KB per manifest digest against a 512MB tmpfs that
 * empties itself whenever the stack restarts, so leaving it is cheap. Run it
 * by hand when the host is quiet, or if the store ever does grow.
 *
 * `registry garbage-collect` is the obvious alternative and is worse on both
 * counts. It does not finish -- measured, it deletes the manifest blobs but
 * leaves the tag links and scheduler-state.json, which it has no mechanism
 * for. And its mark phase reported "0 blobs marked" against a proxy store,
 * because there are no _manifests/revisions to walk, which makes every blob
 * present eligible for deletion including one being written for a pull in
 * flight. That is why upstream says to run it only with the registry
 * read-only or stopped -- and if it must be stopped, this is that, minus the
 * collector.
 */
class TrimRegistryProxy extends Command
{
    /** Also named in AccountTemplate's daemon.json and docker-compose.yml. */
    private const CONTAINER = 'panelalpha-registry-proxy';

    protected $signature = 'system:registry-proxy:trim
        {--dry-run : Report what would be cleared and exit}';

    protected $description = "Clear the Docker Hub proxy's cache store";

    public function handle(): int
    {
        $system = new System();

        if (!$this->isRunning($system)) {
            $this->info('registry-proxy is not running, nothing to clear.');

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->info('Would restart ' . self::CONTAINER . ', emptying its tmpfs store.');

            return 0;
        }

        $system->exec(self::clearArgv(), [], 120);
        $this->info('Restarted ' . self::CONTAINER . '; cache store is empty.');

        return 0;
    }

    /**
     * A missing container is the normal state on an engine without the
     * profile, so it is not an error.
     */
    private function isRunning(System $system): bool
    {
        try {
            $out = $system->exec(
                ['sudo', 'docker', 'inspect', '-f', '{{.State.Running}}', self::CONTAINER],
                [],
                30
            );
        } catch (\Throwable $e) {
            return false;
        }

        return trim($out) === 'true';
    }

    /** Plain argv: nothing here is handed to a shell on either side. */
    public static function clearArgv(): array
    {
        return ['sudo', 'docker', 'restart', self::CONTAINER];
    }
}
