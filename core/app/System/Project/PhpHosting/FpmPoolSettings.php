<?php

namespace App\System\Project\PhpHosting;

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
            $updatedSettings = [];
            $confFile = "{$projectDir}/php/{$phpVersion}/fpm/pool.d/www.conf";
            $conf = $system->exec(['sudo', 'cat', $confFile]);
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
            $newConf = implode("\n", $newLines);
            $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');
            file_put_contents($tmpFile, $newConf);
            $process = $system->runProcess(['sudo', 'cp', $tmpFile, $confFile]);
            unlink($tmpFile);
            if ($process->getExitCode() !== 0) {
                throw new \Exception("Could not update '{$confFile}': ".($process->getErrorOutput() ?: $process->getOutput()));
            }
        }
    }
}
