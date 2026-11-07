<?php

namespace App\Lib\Deploy\Inspect\Report;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;

/**
 * The app config that applies to a project (a `.panelalpha/` directory or an older single file) and where
 * it came from; the repository's copy wins, and the locator decides, so inspect and deploy agree.
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
