<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Constraint shapes the main requirement table does not reach: stray separators, open hyphen ranges, and floors above the catalogue.
 */
class PhpRuntimeConstraintEdgesTest extends TestCase
{
    /** @return array<string, array{?string, ?string, array{0: string, 1: string, 2: string, 3: string}}> */
    public static function composerFiles(): array
    {
        return [
            'a trailing or' => ['{"require":{"php":"^8.2 ||"}}', '{"packages":[]}', ['php', '8.2', '^8.2 ||', 'composer.json require.php']],
            'a trailing comma' => ['{"require":{"php":">=8.2,"}}', '{"packages":[]}', ['php', '8.2', '>=8.2,', 'composer.json require.php']],
            'a hyphen range up to a major' => ['{"require":{"php":"8 - 9"}}', '{"packages":[]}', ['php', '8.1', '8 - 9', 'composer.json require.php']],
            'a floor above a patch, above the catalogue' => ['{"require":{"php":">9.1.2"}}', '{"packages":[]}', ['php', '9.1', '>9.1.2', 'composer.json require.php']],
            'a floor above a minor, above the catalogue' => ['{"require":{"php":">9.1"}}', '{"packages":[]}', ['php', '9.1', '>9.1', 'composer.json require.php']],
            'a floor above a major, above the catalogue' => ['{"require":{"php":">9.0"}}', '{"packages":[]}', ['php', '9.0', '>9.0', 'composer.json require.php']],
            'a tilde floor above the catalogue' => ['{"require":{"php":"~9.2"}}', '{"packages":[]}', ['php', '9.2', '~9.2', 'composer.json require.php']],
        ];
    }

    /** @param  array{0: string, 1: string, 2: string, 3: string}  $expected */
    #[DataProvider('composerFiles')]
    public function test_the_requirement_is_pinned(?string $composerJson, ?string $composerLock, array $expected): void
    {
        $requirement = PhpRuntime::requirementFor($composerJson, $composerLock);

        $this->assertSame($expected, [$requirement->id, $requirement->version, $requirement->constraint, $requirement->source]);
    }

    public function test_a_bare_stability_flag_sets_no_floor_beside_a_locked_one(): void
    {
        $requirement = PhpRuntime::requirementFor('{"require":{"php":"@dev"}}', '{"packages":[{"name":"a/b","require":{"php":"^9.1"}}]}');

        $this->assertSame(['9.1', '@dev, ^9.1', 'composer.lock, via a locked dependency'], [$requirement->version, $requirement->constraint, $requirement->source]);
    }

    public function test_the_supported_versions_are_the_catalogues_minors(): void
    {
        $this->assertSame(PhpRuntime::minors(), (new PhpRuntime)->supportedVersions());
        $this->assertContains(PhpRuntime::defaultMinor(), (new PhpRuntime)->supportedVersions());
    }
}
