<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase as ApplicationTestCase;

/**
 * The Unit suite tests code only; a test that boots the application belongs in tests/unit/Integration.
 */
class UnitSuiteBoundaryTest extends TestCase
{
    public function test_no_unit_test_boots_the_application(): void
    {
        $booting = array_values(array_filter(
            $this->unitTestClasses(),
            fn (string $class) => is_subclass_of($class, ApplicationTestCase::class),
        ));

        $this->assertSame([], $booting, 'Move these to tests/unit/Integration');
    }

    public function test_the_suite_is_found(): void
    {
        $this->assertContains(self::class, $this->unitTestClasses());
    }

    /**
     * @return list<string>
     */
    private function unitTestClasses(): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(__DIR__) + 1, -strlen('.php'));
            $classes[] = __NAMESPACE__.'\\'.str_replace('/', '\\', $relative);
        }
        sort($classes);

        return $classes;
    }
}
