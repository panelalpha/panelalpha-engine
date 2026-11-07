<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP requirement composer files resolve to, every field pinned.
 */
class PhpRuntimeRequirementTest extends TestCase
{
    public static function composerFiles(): array
    {
        return [
            'nothing' => [null, null, ['php', '8.3', '', 'engine default']],
            'empty manifest' => ['{}', null, ['php', '8.3', '', 'engine default']],
            'caret without a lock' => ['{"require":{"php":"^8.1"}}', null, ['php', '8.3', '^8.1', 'composer.json require.php']],
            'caret with an empty lock' => ['{"require":{"php":"^8.1"}}', '{"packages":[]}', ['php', '8.1', '^8.1', 'composer.json require.php']],
            'a locked package raises it' => ['{"require":{"php":">=7.0"}}', '{"packages":[{"name":"a/b","require":{"php":">=8.2"}}]}', ['php', '8.2', '>=7.0, >=8.2', 'composer.json require.php, narrowed by composer.lock']],
            'garbage constraint' => ['{"require":{"php":"garbage"}}', null, ['php', '8.3', 'garbage', 'composer.json require.php']],
            'too old' => ['{"require":{"php":"^7.2"}}', '{"packages":[]}', ['php', '8.3', '^7.2', 'composer.json require.php']],
            'too new' => ['{"require":{"php":"^9.0"}}', '{"packages":[]}', ['php', '9.0', '^9.0', 'composer.json require.php']],
            'locked overrides' => ['{"require":{"php":"^8.1"}}', '{"platform-overrides":{"php":"8.2.7"},"packages":[]}', ['php', '8.2', '^8.1', 'composer.lock platform']],
            'locked platform outside the catalogue' => ['{"require":{"php":">=7.1"}}', '{"platform":{"php":"7.2"},"packages":[]}', ['php', '8.1', '>=7.1', 'composer.json require.php']],
            'contradicted lock' => ['{"require":{"php":">=8.2"}}', '{"platform-overrides":{"php":"8.2"},"packages":[{"name":"x/y","require":{"php":"~8.4.0"}}]}', ['php', '8.4', '>=8.2, ~8.4.0', 'composer.json require.php, narrowed by composer.lock']],
            'only a lock' => [null, '{"packages":[{"name":"x/y","require":{"php":"^8.2"}}]}', ['php', '8.2', '^8.2', 'composer.lock, via a locked dependency']],
            'wildcard minor' => ['{"require":{"php":"8.2.*"}}', '{"packages":[]}', ['php', '8.2', '8.2.*', 'composer.json require.php']],
            'tilde minor' => ['{"require":{"php":"~8.2"}}', '{"packages":[]}', ['php', '8.2', '~8.2', 'composer.json require.php']],
            'tilde patch' => ['{"require":{"php":"~8.2.1"}}', '{"packages":[]}', ['php', '8.2', '~8.2.1', 'composer.json require.php']],
            'caret patch' => ['{"require":{"php":"^8.2.3"}}', '{"packages":[]}', ['php', '8.2', '^8.2.3', 'composer.json require.php']],
            'bare minor' => ['{"require":{"php":"8.2"}}', '{"packages":[]}', ['php', '8.2', '8.2', 'composer.json require.php']],
            'double equals' => ['{"require":{"php":"==8.4"}}', '{"packages":[]}', ['php', '8.4', '==8.4', 'composer.json require.php']],
            'not equal' => ['{"require":{"php":"!=8.1"}}', '{"packages":[]}', ['php', '8.1', '!=8.1', 'composer.json require.php']],
            'bounded' => ['{"require":{"php":">8.1 <8.4"}}', '{"packages":[]}', ['php', '8.1', '>8.1 <8.4', 'composer.json require.php']],
            'hyphen range' => ['{"require":{"php":"8.2 - 8.3"}}', '{"packages":[]}', ['php', '8.2', '8.2 - 8.3', 'composer.json require.php']],
            'major wildcard' => ['{"require":{"php":"8.x"}}', '{"packages":[]}', ['php', '8.1', '8.x', 'composer.json require.php']],
            'star' => ['{"require":{"php":"*"}}', '{"packages":[]}', ['php', '8.1', '*', 'composer.json require.php']],
            'or branches' => ['{"require":{"php":"<7 || >=8.4"}}', '{"packages":[]}', ['php', '8.4', '<7 || >=8.4', 'composer.json require.php']],
            'stability flag' => ['{"require":{"php":"^8.2@dev"}}', '{"packages":[]}', ['php', '8.2', '^8.2@dev', 'composer.json require.php']],
            'stability flag after a space' => ['{"require":{"php":"^8.2 @dev"}}', '{"packages":[]}', ['php', '8.2', '^8.2 @dev', 'composer.json require.php']],
            'stability flag on a wildcard' => ['{"require":{"php":"8.1.*@dev"}}', null, ['php', '8.1', '8.1.*@dev', 'composer.json require.php']],
            'stability flag on a tilde patch' => ['{"require":{"php":"~8.1.0@beta"}}', null, ['php', '8.1', '~8.1.0@beta', 'composer.json require.php']],
            'stability flag above the catalogue' => ['{"require":{"php":"9.1.*@dev"}}', '{"packages":[]}', ['php', '9.1', '9.1.*@dev', 'composer.json require.php']],
            'v prefix' => ['{"require":{"php":"v8.4"}}', '{"packages":[]}', ['php', '8.4', 'v8.4', 'composer.json require.php']],
            'comma and' => ['{"require":{"php":">= 8.2, < 8.4"}}', '{"packages":[]}', ['php', '8.3', '>= 8.2, < 8.4', 'composer.json require.php']],
            'x alone' => ['{"require":{"php":"x"}}', '{"packages":[]}', ['php', '8.1', 'x', 'composer.json require.php']],
            'single pipe' => ['{"require":{"php":"^7.4|^8.0"}}', '{"packages":[]}', ['php', '8.1', '^7.4|^8.0', 'composer.json require.php']],
            'partly unrecognised' => ['{"require":{"php":">=8.1 foo"}}', '{"packages":[]}', ['php', '8.3', '>=8.1 foo', 'composer.json require.php']],
            'greater than a major' => ['{"require":{"php":">8"}}', '{"packages":[]}', ['php', '8.1', '>8', 'composer.json require.php']],
            'greater than a minor without a lock' => ['{"require":{"php":">8.4"}}', null, ['php', '8.4', '>8.4', 'composer.json require.php']],
        ];
    }

    /** @param array{0: string, 1: string, 2: string, 3: string} $expected */
    #[DataProvider('composerFiles')]
    public function test_the_requirement_is_pinned(?string $composerJson, ?string $composerLock, array $expected): void
    {
        $requirement = PhpRuntime::requirementFor($composerJson, $composerLock);

        $this->assertSame($expected, [$requirement->id, $requirement->version, $requirement->constraint, $requirement->source]);
    }
}
