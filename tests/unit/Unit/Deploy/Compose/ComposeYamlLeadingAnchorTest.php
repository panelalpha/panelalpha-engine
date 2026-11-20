<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeYaml;
use PHPUnit\Framework\TestCase;

/** An anchor alone on its line with no key above it has nothing to move onto, so the file stays unreadable. */
class ComposeYamlLeadingAnchorTest extends TestCase
{
    public function test_an_anchor_on_the_first_line_is_not_rescued(): void
    {
        $this->assertNull(ComposeYaml::parse("&base\nservices:\n  app:\n    image: acme/app\n"));
    }

    public function test_an_anchor_below_only_comments_is_not_rescued(): void
    {
        $this->assertNull(ComposeYaml::parse("# shared settings\n\n&base\nservices:\n  app:\n    image: acme/app\n"));
    }
}
