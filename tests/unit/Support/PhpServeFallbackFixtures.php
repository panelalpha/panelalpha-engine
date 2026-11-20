<?php

namespace Tests\Support;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Template\TemplateLoader;

trait PhpServeFallbackFixtures
{
    private function script(): string
    {
        return TemplateLoader::asset(PhpBaseImage::SERVE_ASSET);
    }
}
