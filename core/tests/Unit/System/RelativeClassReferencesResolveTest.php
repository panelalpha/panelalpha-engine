<?php

namespace Tests\Unit\System;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every `new Foo\Bar(...)` written relative to its file's namespace resolves
 * to a class that exists.
 *
 * The `App\Lib\Apis\System` -> `App\System` move left two of these behind and
 * neither was caught: an unqualified `use` is checked by nothing, and a
 * relative `new` is checked by PHP only on the line that runs it. One of them
 * (`Dind::importProjectArchive()` reaching for `Source\Files`) sat on a path
 * no test covers, so it would have surfaced as a fatal on a customer's
 * archive import.
 *
 * This is the cheap check that would have caught it: resolve the reference
 * the way PHP will, and ask whether anything is there.
 */
class RelativeClassReferencesResolveTest extends TestCase
{
    /** `new Foo\Bar(` -- qualified, no leading backslash: the relative form. */
    private const RELATIVE_NEW = '/new\s+([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)\s*\(/';

    public function test_every_relative_new_in_app_resolves(): void
    {
        $root = base_path('app');
        $unresolved = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                continue;
            }

            $aliases = self::aliases($source);
            preg_match_all(self::RELATIVE_NEW, $source, $matches);

            foreach (array_unique($matches[1]) as $reference) {
                $fqcn = self::resolve(trim($namespace[1]), $aliases, $reference);

                if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn)) {
                    continue;
                }

                $unresolved[] = str_replace($root, 'app', $file->getPathname())
                    . ": new {$reference} -> {$fqcn}";
            }
        }

        $this->assertSame([], $unresolved, "A relative class reference resolves to nothing:\n"
            . implode("\n", $unresolved));
    }

    /**
     * The imports in force, by the name they are referred to under. An
     * import on the *first* segment re-roots the whole reference, which is
     * what `use ...\Source\Files as SourceFiles` does.
     *
     * @return array<string, string> lowercased local name => fully qualified
     */
    private static function aliases(string $source): array
    {
        preg_match_all('/^use\s+([^;]+);/m', $source, $uses);

        $aliases = [];
        foreach ($uses[1] as $use) {
            $use = trim($use);
            // `use function` / `use const` do not bring a class into scope.
            if (str_starts_with($use, 'function ') || str_starts_with($use, 'const ')) {
                continue;
            }

            if (preg_match('/^(.+?)\s+as\s+(\S+)$/i', $use, $aliased) === 1) {
                $aliases[strtolower(trim($aliased[2]))] = trim($aliased[1]);
                continue;
            }

            $segments = explode('\\', $use);
            $aliases[strtolower((string) end($segments))] = $use;
        }

        return $aliases;
    }

    /** @param array<string, string> $aliases */
    private static function resolve(string $namespace, array $aliases, string $reference): string
    {
        $head = explode('\\', $reference)[0];
        $imported = $aliases[strtolower($head)] ?? null;

        return $imported === null
            ? $namespace . '\\' . $reference
            : $imported . substr($reference, strlen($head));
    }
}
