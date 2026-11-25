<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\ScriptChain;

/**
 * A Next.js static export (`output: "export"` for every build, or `next export` run by the `build` script), read
 * from the workspace `next-workspace` found, since a monorepo keeps its next.config there; yields the same fields.
 */
final class NextExportProbe implements PlatformProbe
{
    private const NEXT_EXPORT = '/\bnext\s+export\b/';

    public function id(): string
    {
        return 'next-export';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        $data = (new NextWorkspaceProbe)->evaluate($context);
        if ($data === false || $data === true) {
            return $data;
        }

        $relative = trim((string) ($data['workspace_relative'] ?? ''), '/');
        $dir = $relative === '' ? $context->projectDir : rtrim($context->projectDir, '/').'/'.$relative;

        return self::configExports($dir) || self::buildRunsNextExport($dir) ? $data : false;
    }

    private static function configExports(string $dir): bool
    {
        $config = NextExportConfig::read($dir);

        return $config !== null && NextExportConfig::exportsUnconditionally($config);
    }

    private static function buildRunsNextExport(string $dir): bool
    {
        $package = json_decode((string) AccountHomeRead::contents($dir.'/package.json'), true);
        $scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];
        $script = static fn (string $name): string => is_string($scripts[$name] ?? null) ? trim($scripts[$name]) : '';
        $build = $script('build');

        return $build !== '' && ScriptChain::mentions($build, self::NEXT_EXPORT, $script);
    }
}
