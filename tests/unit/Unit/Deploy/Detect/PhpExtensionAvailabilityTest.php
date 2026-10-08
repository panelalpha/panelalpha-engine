<?php

namespace Tests\Unit\Deploy\Detect;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Detect\PhpExtensionAvailability;
use PHPUnit\Framework\TestCase;

/**
 * Extensions a project needs that its image will not have.
 *
 * ownCloud is the case: `source_inspect` reported `deployable: true` while
 * holding both halves of the answer -- the project's `ext-*` and the image it
 * had resolved itself -- and the deploy then failed in the dependency stage on
 * "requires PHP extension ext-memcached … but it is missing from your system".
 * Two deploys were destroyed finding out what one comparison could say.
 */
class PhpExtensionAvailabilityTest extends TestCase
{
    private static function composer(array $require): string
    {
        return (string) json_encode(['require' => $require]);
    }

    public function test_a_project_with_no_composer_json_is_not_judged(): void
    {
        $this->assertSame([], PhpExtensionAvailability::missing(null));
        $this->assertSame([], PhpExtensionAvailability::missing(''));
        $this->assertNull(PhpExtensionAvailability::issue(null));
    }

    public function test_an_extension_in_the_baked_set_is_available(): void
    {
        // Whatever the shipped list holds, the first entry is in the image.
        $baked = PhpBaseImage::EXTENSIONS[0];

        $this->assertSame(
            [],
            PhpExtensionAvailability::missing(self::composer(['ext-' . $baked => '*']))
        );
    }

    /** memcached is in the baked set now, which is what unblocked ownCloud. */
    public function test_memcached_is_available(): void
    {
        $this->assertSame(
            [],
            PhpExtensionAvailability::missing(self::composer(['ext-memcached' => '*']))
        );
        $this->assertNull(PhpExtensionAvailability::issue(self::composer(['ext-memcached' => '*'])));
    }

    /** Built into the php image; Composer declares them and nobody installs them. */
    public function test_a_bundled_extension_is_available(): void
    {
        $this->assertSame(
            [],
            PhpExtensionAvailability::missing(self::composer([
                'ext-json' => '*',
                'ext-mbstring' => '*',
                'ext-openssl' => '*',
            ]))
        );
    }

    /**
     * A handful of unusual extensions still get a variant base built for them,
     * so they are available and must not be reported.
     */
    public function test_a_few_unusual_extensions_are_baked_as_a_variant(): void
    {
        $this->assertSame(
            [],
            PhpExtensionAvailability::missing(self::composer(['ext-solr' => '*', 'ext-yaml' => '*']))
        );
    }

    /**
     * Past MAX_BAKED_EXTRAS the engine bakes *none* of them -- not the first
     * four. With no per-project Dockerfile left to compile the rest, every one
     * is absent from the running site, and that is the case worth refusing.
     */
    public function test_too_many_unusual_extensions_are_none_of_them_baked(): void
    {
        $many = [];
        foreach (['solr', 'yaml', 'rdkafka', 'oci8', 'snmp', 'tidy'] as $name) {
            $many['ext-' . $name] = '*';
        }

        $missing = PhpExtensionAvailability::missing(self::composer($many));

        $this->assertGreaterThan(PhpBaseImage::MAX_BAKED_EXTRAS, count($missing));
        $this->assertContains('solr', $missing);
        $this->assertContains('tidy', $missing);
    }

    public function test_the_issue_names_the_extension(): void
    {
        $many = [];
        foreach (['solr', 'yaml', 'rdkafka', 'oci8', 'snmp', 'tidy'] as $name) {
            $many['ext-' . $name] = '*';
        }

        $issue = PhpExtensionAvailability::issue(self::composer($many));

        $this->assertNotNull($issue);
        $this->assertStringContainsString('ext-solr', $issue);
        $this->assertStringContainsString('not in the image', $issue);
    }

    public function test_malformed_composer_json_is_not_judged(): void
    {
        $this->assertSame([], PhpExtensionAvailability::missing('{not json'));
        $this->assertSame([], PhpExtensionAvailability::missing('[]'));
    }
}
