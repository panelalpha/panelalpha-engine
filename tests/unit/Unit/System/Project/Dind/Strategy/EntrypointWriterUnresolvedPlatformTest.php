<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\PlatformRegistry;
use App\System\Project\Dind\Strategy\EntrypointWriter;
use PHPUnit\Framework\TestCase;

/** A platform that detection named and the registry cannot find is said out loud. */
class EntrypointWriterUnresolvedPlatformTest extends TestCase
{
    public function test_a_named_platform_that_does_not_resolve_is_reported(): void
    {
        $warning = EntrypointWriter::unresolvedPlatformWarning(['platform' => 'wintercms'], null);

        $this->assertNotNull($warning);
        $this->assertStringContainsString("'wintercms'", $warning);
        $this->assertStringContainsString('will not run', $warning);
    }

    public function test_a_resolved_platform_or_none_at_all_says_nothing(): void
    {
        $this->assertNull(EntrypointWriter::unresolvedPlatformWarning(
            ['platform' => 'php'],
            PlatformRegistry::find('php')
        ));
        // Railpack and fallback decisions name no platform: nothing was lost.
        $this->assertNull(EntrypointWriter::unresolvedPlatformWarning(['platform' => null], null));
        $this->assertNull(EntrypointWriter::unresolvedPlatformWarning([], null));
    }
}
