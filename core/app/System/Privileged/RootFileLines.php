<?php

namespace App\System\Privileged;

/** File commands with a glob, as shell lines. The broker expands the glob in the directory it opened. */
final class RootFileLines
{
    private const AREA = 'lines';

    /** Fails when $dir holds no `*.sh`. */
    public function removeScripts(string $dir): string
    {
        return $this->line(__FUNCTION__, $dir);
    }

    public function removeScriptsIfAny(string $dir): string
    {
        return $this->line(__FUNCTION__, $dir);
    }

    /** Removes `proxy-rule-*.conf`. None there is not a failure. */
    public function removeProxyRuleConfigsIfAny(string $dir): string
    {
        return $this->line(__FUNCTION__, $dir);
    }

    /** Dot entries too. $dir itself stays. */
    public function emptyDirectory(string $dir): string
    {
        return $this->line(__FUNCTION__, $dir);
    }

    private function line(string $action, string $dir): string
    {
        return Root::actionLine(self::AREA, $action, [Arguments::path($dir)]);
    }
}
