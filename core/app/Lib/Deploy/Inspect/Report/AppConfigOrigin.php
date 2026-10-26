<?php

namespace App\Lib\Deploy\Inspect\Report;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;

/**
 * The app config that applies to a project, and where it came from.
 *
 * Hosting instructions for one repository — a `.panelalpha/` directory, or
 * one of the older single files — shipped by the project or written by the
 * engine for a project that ships none. The repository's own copy wins, and
 * the locator is what decides, so an inspection and a deploy cannot pick
 * different app configs.
 */
final class AppConfigOrigin
{
    /**
     * @return array{0: ?AppConfig, 1: ?string}
     */
    public static function find(string $projectDir, ?string $repoUrl): array
    {
        return AppConfigLocator::find(new LocalAppConfigSource, $projectDir, $repoUrl);
    }
}
