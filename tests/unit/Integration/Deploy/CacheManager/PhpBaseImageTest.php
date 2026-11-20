<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use PHPUnit\Framework\TestCase;

class PhpBaseImageTest extends TestCase
{
    /**
     * The date is what the config declares, and bumping it is what an operator
     * does after changing the recipe. Nothing detects a recipe change for
     * them, so the least this can do is guarantee the bump lands in the tag.
     */
    public function test_bumping_the_configured_date_changes_the_tag(): void
    {
        $before = (string) PhpBaseImage::tag('php:8.3-apache-bookworm');
        $config = tempnam(sys_get_temp_dir(), 'runtime-images') . '.yaml';

        try {
            file_put_contents($config, <<<'YAML'
            runtimes:
              php:
                default: "8.3"
                versions: ["8.3"]
                image:
                  from: "php:{version}-apache-bookworm"
                  build:
                    repository: panelalpha/php
                    stub: dockerfile/php-base
                    recipe: "2031-01-02"
            YAML);
            RuntimeImageCatalog::useConfig($config);

            $this->assertSame('20310102', PhpBaseImage::fingerprint());
            $this->assertSame(
                'panelalpha/php:8.3-apache-bookworm-pa20310102',
                PhpBaseImage::tag('php:8.3-apache-bookworm')
            );
        } finally {
            RuntimeImageCatalog::useConfig(null);
            @unlink($config);
        }

        $this->assertSame($before, PhpBaseImage::tag('php:8.3-apache-bookworm'));
    }

    /**
     * A date that is not one leaves the runtime with no tag at all, rather
     * than a tag stamped with something that is not a date.
     *
     * There is nothing compiled in to land on any more: a fallback would name
     * an image the catalogue does not describe, and the deploy would build it,
     * fail to recognise it, and fetch it from a registry that never had it.
     * No shared base is slower; a base nobody can identify is a dead deploy.
     */
    public function test_an_unparseable_date_leaves_no_tag_at_all(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'runtime-images') . '.yaml';

        try {
            file_put_contents($config, <<<'YAML'
            runtimes:
              php:
                default: "8.3"
                versions: ["8.3"]
                image:
                  from: "php:{version}-apache-bookworm"
                  build:
                    repository: panelalpha/php
                    stub: dockerfile/php-base
                    recipe: "yesterday"
            YAML);
            RuntimeImageCatalog::useConfig($config);

            $this->assertNull(PhpBaseImage::fingerprint());
            $this->assertNull(PhpBaseImage::tag('php:8.3-apache-bookworm'));
        } finally {
            RuntimeImageCatalog::useConfig(null);
            @unlink($config);
        }
    }
}
