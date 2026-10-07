<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A Procfile that names a web process.
 *
 * `{"file": "Procfile"}` would be wrong: a Procfile declaring only
 * `worker:` or `release:` gives no way to start a server, and claiming the
 * project would produce a container that builds and then exits. The web line
 * has to be parsed out to know whether it exists.
 *
 * Contributes the command, so a manifest can serve it without repeating it.
 */
final class ProcfileWebProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'procfile-web';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        $command = self::webCommand($context->projectDir);

        return $command === null ? false : ['procfile_web' => $command];
    }

    public static function webCommand(string $projectDir): ?string
    {
        $procfile = self::readProjectFile($projectDir, 'Procfile');
        if ($procfile === null) {
            return null;
        }
        if (preg_match('/^\s*web\s*:\s*(.+)$/mi', $procfile, $matches) !== 1) {
            return null;
        }
        $command = trim($matches[1]);

        return $command !== '' ? $command : null;
    }

    /**
     * The Procfile's other processes, name => command: a worker, a clock, a
     * `release` run before the app starts. Lower-cased names, as compose
     * service names; the first line for a name wins.
     *
     * @return array<string, string>
     */
    public static function otherProcesses(string $procfile): array
    {
        $processes = [];
        foreach (preg_split('/\R/', $procfile) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z0-9][A-Za-z0-9_-]*)\s*:\s*(\S.*?)\s*$/', $line, $m) !== 1) {
                continue;
            }
            $name = strtolower($m[1]);
            if ($name !== 'web' && !isset($processes[$name])) {
                $processes[$name] = $m[2];
            }
        }

        return $processes;
    }

    private static function readProjectFile(string $projectDir, string $name): ?string
    {
        $projectDir = rtrim($projectDir, '/');
        $contents = @file_get_contents($projectDir . '/' . $name);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
