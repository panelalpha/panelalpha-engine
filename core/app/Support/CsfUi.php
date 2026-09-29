<?php

namespace App\Support;

use App\System;

/**
 * CSF's web UI, on port 2012: a second lfd process, off unless `CSF_UI=1` is in
 * the engine's compose `.env`. scripts/csf.sh reads that key on every install
 * and update; this flips it, and the live csf.conf with it, without re-running
 * the installer, which rebuilds the firewall.
 */
final class CsfUi
{
    public const ENV = 'CSF_UI';
    public const PORT = 2012;
    public const USERNAME = 'panelalpha';

    private const CONF = '/etc/csf/csf.conf';

    public function __construct(private readonly System $system)
    {
    }

    public function installed(): bool
    {
        return $this->system->runProcessOnHost(['test', '-f', self::CONF])->isSuccessful();
    }

    /** What `.env` asks for. */
    public function configured(): bool
    {
        return $this->env()->get(self::ENV) === '1';
    }

    /** Whether csf.conf has it on, which is what lfd starts from. */
    public function enabled(): bool
    {
        return $this->system->csf()->uiEnabled();
    }

    /** Write the choice to `.env` and csf.conf, then restart lfd so it starts or stops the UI. */
    public function set(bool $on): void
    {
        $this->env()->set([self::ENV => $on ? '1' : '0']);
        $this->system->execOnHost(['sed', '-i', 's/^UI = ".*/UI = "' . ($on ? '1' : '0') . '"/', self::CONF]);
        $this->system->execOnHost(['systemctl', 'restart', 'lfd']);
    }

    /** The password scripts/csf.sh generated, empty when it never ran. */
    public function password(): string
    {
        return (string) $this->env()->get('CSF_UI_PASSWORD');
    }

    private function env(): EnvFile
    {
        return new EnvFile($this->system->engineDirPath() . '/.env');
    }
}
