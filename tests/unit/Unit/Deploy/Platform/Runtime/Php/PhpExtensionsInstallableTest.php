<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\PhpExtensions;
use PHPUnit\Framework\TestCase;

/**
 * Only extension names are considered for installing; anything else in the list is ignored.
 */
class PhpExtensionsInstallableTest extends TestCase
{
    public function test_entries_that_are_not_names_are_ignored(): void
    {
        $this->assertSame(['imagick'], PhpExtensions::installable([5, null, ['x'], 'Imagick', 'imagick']));
    }
}
