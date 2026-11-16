<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Detect\StaticEntry;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * The HTML entry point the nginx root is pointed at: `index.html` may sit at the root or under
 * `public/`, `dist/`, `build/` or `site/`, which the Static manifest cannot say.
 */
final class StaticEntryProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'static-entry';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        $entry = StaticEntry::find($context->projectDir, $context->files);

        return $entry === null ? false : ['static_index' => $entry];
    }
}
