<?php

namespace Tests\Unit\Apis;

use PHPUnit\Framework\TestCase;

/**
 * Every collaborator under `.../Project/Dind/` that takes the Dind facade must
 * import it.
 *
 * The namespace is a trap. These classes live in
 * `App\System\Project\Dind`, and the class they are handed is
 * `App\System\Project\Dind` - the parent namespace, not a member
 * of this one. An unimported `Dind` type-hint therefore resolves to
 * `...\Dind\Dind`, which does not exist, and PHP raises a TypeError only when
 * the method is actually called.
 *
 * That is why it needs a test rather than review: the file parses, static
 * analysis of an unresolvable hint is easy to miss, and nothing fails until a
 * real deploy reaches that collaborator. ProjectFiles shipped without the
 * import and every PHP-strategy deploy - Laravel, Grav, Matomo, WordPress -
 * died with a 500 that named no file the reader would think to look in.
 * SystemAppConfigSource had already done the same thing once.
 */
class DindCollaboratorTypeHintsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../../core/app/System/Project/Dind';

    private const FACADE = 'App\System\Project\Dind';

    /**
     * @return list<string> absolute paths
     */
    private function collaborators(): array
    {
        $files = glob(self::DIR . '/*.php') ?: [];
        sort($files);

        return $files;
    }

    public function test_the_directory_is_where_this_test_thinks_it_is(): void
    {
        // A moved directory would otherwise turn this suite green by testing
        // nothing at all.
        $this->assertNotEmpty($this->collaborators(), self::DIR . ' has no classes');
    }

    public function test_every_class_hinting_the_facade_imports_it(): void
    {
        $missing = [];
        foreach ($this->collaborators() as $path) {
            $source = (string) file_get_contents($path);
            if (!$this->hintsTheFacade($source)) {
                continue;
            }
            if (!str_contains($source, 'use ' . self::FACADE . ';')) {
                $missing[] = basename($path);
            }
        }

        $this->assertSame([], $missing, 'these hint Dind without importing it');
    }

    public function test_the_facade_they_hint_actually_exists(): void
    {
        // The other half of the trap: the import is only correct because the
        // parent-namespace class is the real one.
        $this->assertTrue(class_exists(self::FACADE));
        $this->assertFalse(class_exists(self::FACADE . '\Dind'));
    }

    /**
     * A `Dind` type-hint on a property, parameter or return - but not the
     * word appearing in a namespace, a docblock or another identifier.
     */
    private function hintsTheFacade(string $source): bool
    {
        return preg_match('/(?:\(|,\s*|(?:private|public|protected)\s+(?:readonly\s+)?|:\s*)\??Dind\s*[\$\)\{]/', $source) === 1;
    }
}
