<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ShortVolume;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each expected source is what `docker compose config` (Compose v5.6.0, the
 * version the accounts run) printed for the entry: null where it printed an
 * anonymous volume, a target with no source.
 */
class ShortVolumeTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function entries(): array
    {
        return [
            'container path' => ['/anon', null],
            'database path' => ['/var/lib/mysql', null],
            'trailing slash' => ['/anon/', null],
            'relative path' => ['./rel', null],
            'home path' => ['~/x', null],
            'bare name' => ['relname', null],
            'dot' => ['.', null],
            'dot slash' => ['./', null],
            'dot dot slash' => ['../', null],
            'two characters' => ['/:', null],
            'comma' => ['/a,b', null],
            'drive path' => ['C:\x', null],
            'drive path with a slash' => ['C:/x', null],
            'drive letter then path' => ['a:/etc', null],
            'non-ASCII drive letter' => ['é:/x', null],
            'UNC path' => ['\\\\server\share', null],
            'open brace' => ['/x{', null],
            'braces' => ['{/x}', null],
            'bind' => ['/a:/b', '/a'],
            'bind with mode' => ['/x:/y:ro', '/x'],
            'mode only' => ['/anon:ro', '/anon'],
            'read-write only' => ['/anon:rw', '/anon'],
            'named volume' => ['relname:/data', 'relname'],
            'space before the colon' => ['/x :/y', '/x '],
            'drive bind' => ['c:\x:/y', 'c:\x'],
            'UNC bind' => ['\\\\server\share:/y', '\\\\server\share'],
            'non-ASCII drive bind' => ['é:/../../../etc:/y', 'é:/../../../etc'],
            'colon inside braces' => ['/{/../etc:/x}', '/{/../etc'],
            'colon inside brackets' => ['/[/../etc:/x]', '/[/../etc'],
            'relative colon inside braces' => ['../../../{:/x}', '../../../{'],
            'braces spanning the target' => ['/{/../etc:/../../home/u/project/x}:/t', '/{/../etc'],
        ];
    }

    #[DataProvider('entries')]
    public function test_the_source_is_the_one_compose_reads(string $entry, ?string $source): void
    {
        $this->assertSame($source, ShortVolume::source($entry));
    }

    public function test_a_windows_drive_or_unc_source_is_a_windows_path(): void
    {
        foreach (['C:\x', 'c:/x', 'é:/../etc', '\\\\server\share'] as $source) {
            $this->assertTrue(ShortVolume::isWindowsPath($source), $source);
        }
        foreach (['/x', './x', 'name', '~/x', '\x'] as $source) {
            $this->assertFalse(ShortVolume::isWindowsPath($source), $source);
        }
    }
}
