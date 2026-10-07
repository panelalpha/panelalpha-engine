<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Detect\PhpSources;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A composer.json that is only Packagist metadata: an explicit library that
 * requires no package, in a project with no PHP of its own to serve. SVG-edit
 * (a Vite SPA) ships one, and the php platform built it and served a 403.
 */
final class ComposerMetadataOnlyProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'composer-metadata-only';
    }

    public function evaluate(ProjectContext $context): bool
    {
        $composer = json_decode($context->contents('composer.json') ?? '', true);
        if (!is_array($composer) || ($composer['type'] ?? null) !== 'library') {
            return false;
        }
        $require = $composer['require'] ?? [];
        if (!is_array($require)) {
            return false;
        }
        // php, ext-*, lib-* are platform constraints; a package name has a vendor.
        foreach (array_keys($require) as $name) {
            if (str_contains((string) $name, '/')) {
                return false;
            }
        }

        return !PhpSources::present($context->projectDir);
    }
}
