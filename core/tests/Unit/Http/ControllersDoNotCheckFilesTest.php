<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * A controller that asks PHP whether a path exists asks as the engine's own
 * user, while the operation it guards runs elsewhere: as the account, through
 * the confinement check, or as root. The two disagreed. The layer doing the
 * operation answers, and the controller maps that to a status.
 */
class ControllersDoNotCheckFilesTest extends TestCase
{
    private const CHECKS = [
        'file_exists', 'is_file', 'is_dir', 'is_link', 'is_readable', 'is_writable', 'is_writeable',
        'is_executable', 'realpath', 'filesize', 'filemtime', 'fileperms', 'fileowner', 'filetype',
        'stat', 'lstat', 'readlink', 'scandir', 'glob',
    ];

    public function test_no_controller_calls_a_php_filesystem_check(): void
    {
        $root = dirname(__DIR__, 3) . '/app/Http/Controllers';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        $hits = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach ($this->calls((string) file_get_contents($file->getPathname())) as [$name, $line]) {
                $hits[] = substr($file->getPathname(), strlen($root) + 1) . ":{$line} {$name}()";
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }

    public function test_the_scan_finds_a_check_and_ignores_methods_of_the_same_name(): void
    {
        $code = '<?php if (!\file_exists($p) && !is_link($p)) {} $files->stat($p); FileManager::glob(); function stat() {}';

        $this->assertSame([['file_exists', 1], ['is_link', 1]], $this->calls($code));
    }

    /** @return list<array{0: string, 1: int}> global function calls to one of CHECKS */
    private function calls(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        $calls = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            $previous = $tokens[$i - 1] ?? null;
            if (
                !in_array($name, self::CHECKS, true)
                || ($tokens[$i + 1] ?? null) !== '('
                || (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true))
            ) {
                continue;
            }
            $calls[] = [$name, $token[2]];
        }

        return $calls;
    }
}
