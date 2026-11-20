<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Support\RuntimeImageCatalogFallbackFixtures;

/**
 * What a bad config does.
 *
 * The rule the whole design rests on: a host edits this file, and nothing it
 * can write may stop a deploy. Every shape below is one an operator plausibly
 * produces — a half-finished edit, a stray tab, a default they forgot to add
 * to the version list — and every one has to end with a project still
 * resolving to a version and still running on the official image for it.
 *
 * The images the engine *builds* are a different matter: they are named from
 * this file and nowhere else, so a bad edit leaves a runtime with no shared
 * base and every account on it installing its own extensions. Slower, and
 * still a deploy.
 *
 * These are the branches the shipped config cannot reach, because the shipped
 * config is the good case. Without them the fallback path is code nothing ever
 * runs until a customer runs it.
 *
 * @see \Tests\Unit\Deploy\Platform\Runtime\RuntimeImageCatalogTest for the
 *      rules the real shipped config has to satisfy.
 */
class RuntimeImageCatalogFallbackTest extends TestCase
{
    use RuntimeImageCatalogFallbackFixtures;

    public function test_a_missing_file_falls_back(): void
    {
        RuntimeImageCatalog::useConfig('/nonexistent/images.yaml');

        $this->assertPhpStillDeploysWithoutABase('a host with no config file still deploys, without a shared base');
    }
}
