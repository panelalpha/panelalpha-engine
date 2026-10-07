<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A Next.js static export (`output: "export"`, or the old `next export`),
 * read from the app's own directory: the repository root, or the workspace
 * `next-workspace` found. A monorepo keeps its next.config in the workspace,
 * and reading only the root sent every such export to `next start`.
 * Yields the same fields as `next-workspace`.
 */
final class NextExportProbe implements PlatformProbe
{
    private const EXPORT_CONFIG = '/output\s*:\s*[\'"]export[\'"]/';

    public function id(): string
    {
        return 'next-export';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        $data = (new NextWorkspaceProbe())->evaluate($context);
        if ($data === false || $data === true) {
            return $data;
        }

        $relative = trim((string) ($data['workspace_relative'] ?? ''), '/');
        $dir = $relative === '' ? $context->projectDir : rtrim($context->projectDir, '/') . '/' . $relative;

        $config = ProjectContext::firstConfigContents($dir, 'next.config');
        if (is_string($config) && preg_match(self::EXPORT_CONFIG, $config) === 1) {
            return $data;
        }
        $package = @file_get_contents($dir . '/package.json');

        return is_string($package) && str_contains($package, 'next export') ? $data : false;
    }
}
