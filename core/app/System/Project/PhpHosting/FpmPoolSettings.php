<?php

namespace App\System\Project\PhpHosting;

use App\System;
use App\System\Privileged;
use App\System\Privileged\Exchange;
use App\System\Project\PhpHosting;
use Illuminate\Support\Str;

trait FpmPoolSettings
{
    public function applyPhpFpmSettings(PhpHosting $project): void
    {
        $system = $project->system();
        $settings = $project->userModel()->getPhpFpmPoolSettings();
        $phpVersions = $system->php()->listAvailablePhpVersions();
        $projectDir = $system->projectDirPath($project->username());
        foreach ($phpVersions as $phpVersion) {
            $webserver = $system->webserver()->getCurrentWebserver();
            if ($webserver === 'nginx-proxy') {
                $settings['listen'] = "/run/php/php{$phpVersion}-fpm.sock";
            }
            $confFile = "{$projectDir}/php/{$phpVersion}/fpm/pool.d/www.conf";
            $conf = $system->exec(Privileged::files()->read($confFile));
            $this->installPoolConf($system, $confFile, $this->poolConfWith($conf, $settings));
        }
    }

    /**
     * $conf with each setting's line, commented out or not, replaced, and the settings it lacks appended.
     *
     * @param  array<string, string>  $settings
     */
    private function poolConfWith(string $conf, array $settings): string
    {
        $updatedSettings = [];
        $lines = explode("\n", $conf);
        $newLines = [];
        foreach ($lines as $line) {
            foreach ($settings as $name => $value) {
                if (Str::startsWith($line, ["{$name} = ", ";{$name} = "])) {
                    $newLines[] = "{$name} = {$value}";
                    $updatedSettings[] = $name;

                    continue 2;
                }
            }
            $newLines[] = $line;
        }
        foreach ($settings as $name => $value) {
            if (in_array($name, $updatedSettings)) {
                continue;
            }
            $newLines[] = "{$name} = {$value}";
        }

        return implode("\n", $newLines);
    }

    private function installPoolConf(System $system, string $confFile, string $newConf): void
    {
        $tmpFile = Exchange::fileHolding('tmp_', $newConf);
        $process = $system->runProcess(Privileged::files()->copy($tmpFile, $confFile));
        unlink($tmpFile);
        if ($process->getExitCode() !== 0) {
            throw new \Exception("Could not update '{$confFile}': ".($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}
