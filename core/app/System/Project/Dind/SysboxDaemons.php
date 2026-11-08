<?php

namespace App\System\Project\Dind;

use App\System;
use App\System\Privileged;

/**
 * sysbox-fs and sysbox-mgr on the engine host, which every sysbox account container needs: with either stopped no
 * account starts, and a build in one already running fails on its first mount, minutes into the deploy.
 */
final class SysboxDaemons
{
    /** Leads the failure of a deploy refused because a daemon is stopped; the explainer rule keys on it. */
    public const NOT_RUNNING = 'sysbox is not running on the engine host';

    private const PROBE_TIMEOUT_SECONDS = 30;

    /** States of a unit that is down and not on its way up. */
    private const STOPPED = ['inactive', 'failed'];

    public function __construct(
        private System $system,
    ) {}

    /**
     * One host probe before a deploy reaches a sysbox account. A host that does not answer, or a daemon systemd does not
     * know, lets the deploy go on: the probe only saves the minutes a build would take to fail, it decides nothing else.
     *
     * @throws \RuntimeException
     */
    public function refuseWhenStopped(): void
    {
        if (! AccountRuntime::isSysbox()) {
            return;
        }
        $stopped = $this->stopped();
        if ($stopped === []) {
            return;
        }

        throw new \RuntimeException(self::NOT_RUNNING.' ('.self::described($stopped).')');
    }

    /**
     * Each daemon systemd has loaded, by name: its state and when it last became active, as systemd prints that time;
     * empty when the host did not answer.
     *
     * @return array<string, array{state: string, since: string}>
     */
    public function loaded(): array
    {
        $daemons = [];
        foreach ($this->units() as $unit) {
            if (($unit['LoadState'] ?? '') === 'loaded') {
                $daemons[(string) preg_replace('/\.service$/', '', $unit['Id'])] = [
                    'state' => $unit['ActiveState'] ?? '',
                    'since' => $unit['ActiveEnterTimestamp'] ?? '',
                ];
            }
        }

        return $daemons;
    }

    /**
     * The daemons systemd has loaded and reports down, with their state; empty when none is or the host did not answer.
     *
     * @return array<string, string>
     */
    private function stopped(): array
    {
        $stopped = [];
        foreach ($this->loaded() as $daemon => $unit) {
            if (in_array($unit['state'], self::STOPPED, true)) {
                $stopped[$daemon] = $unit['state'];
            }
        }

        return $stopped;
    }

    /**
     * `systemctl show`'s blocks of `Key=value`, one per unit, in no fixed key order.
     *
     * @return list<array<string, string>>
     */
    private function units(): array
    {
        try {
            $show = $this->system->runProcessOnHost(Privileged::probes()->sysboxStates(), [], self::PROBE_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return [];
        }
        if (! $show->isSuccessful()) {
            return [];
        }

        $units = [];
        foreach (preg_split('/\R[ \t]*\R/', trim($show->getOutput())) ?: [] as $block) {
            $unit = self::properties($block);
            if (($unit['Id'] ?? '') !== '') {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /** @return array<string, string> */
    private static function properties(string $block): array
    {
        $properties = [];
        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            $pair = explode('=', trim($line), 2);
            if (count($pair) === 2) {
                $properties[$pair[0]] = $pair[1];
            }
        }

        return $properties;
    }

    /** @param  array<string, string>  $stopped */
    private static function described(array $stopped): string
    {
        return implode(', ', array_map(
            static fn (string $daemon, string $state): string => "{$daemon} {$state}",
            array_keys($stopped),
            $stopped,
        ));
    }
}
