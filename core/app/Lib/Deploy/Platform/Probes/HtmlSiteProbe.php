<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Detect\HtmlSite;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A site made only of documents whose front page is not called `index`; contributes the document
 * that answers `/`, worked out by {@see HtmlSite} rather than read off a filename.
 */
final class HtmlSiteProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'html-site';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        $entry = HtmlSite::entry($context->projectDir);

        return $entry === null ? false : ['static_index' => $entry];
    }
}
