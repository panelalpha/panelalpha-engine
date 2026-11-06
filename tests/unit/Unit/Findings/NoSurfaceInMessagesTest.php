<?php

namespace Tests\Unit\Findings;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A sentence built outside the CLI, the OpenAPI docs and the MCP layer reaches every surface, so it may not name a
 * `pae` command, an MCP tool or a REST call: those are hints ({@see \App\Lib\Findings\Hints\Hints}).
 */
class NoSurfaceInMessagesTest extends TestCase
{
    private const APP = __DIR__.'/../../../../core/app';

    private const SURFACES = ['/Console/', '/OpenApi/', '/Mcp/', '/Lib/Findings/Hints/'];

    /** Writes a shell script an operator reads on the host. */
    private const NOT_A_MESSAGE = ['Support/QueueWorkers.php'];

    /** Refusals of what only an HTTP caller can send, such as a header: naming the HTTP call is the answer. */
    private const HTTP_ONLY = [
        'Http/Controllers/UserController.php' => 1,
    ];

    public function test_no_new_sentence_names_a_surface(): void
    {
        $found = $this->scan();
        ksort($found);

        $this->assertSame(self::HTTP_ONLY, array_map('count', $found), $this->describe($found));
    }

    /**
     * @return array<string, list<string>>
     */
    private function scan(): array
    {
        $app = (string) realpath(self::APP);
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app)) as $file) {
            $relative = substr($file->getPathname(), strlen($app) + 1);
            if (! str_ends_with($relative, '.php') || $this->isExempt('/'.$relative)) {
                continue;
            }
            foreach ($this->messagesIn($file->getPathname(), str_starts_with($relative, 'Http/Controllers/')) as $message) {
                if ($this->namesASurface($message)) {
                    $found[$relative][] = $message;
                }
            }
        }

        return $found;
    }

    private function isExempt(string $relative): bool
    {
        foreach (self::SURFACES as $surface) {
            if (str_contains($relative, $surface)) {
                return true;
            }
        }

        return in_array(ltrim($relative, '/'), self::NOT_A_MESSAGE, true);
    }

    /**
     * String literals, leaving out attributes (OpenAPI), Log:: arguments, and a controller's constants (OpenAPI text).
     *
     * @return list<string>
     */
    private function messagesIn(string $path, bool $controller): array
    {
        $messages = [];
        $attributeDepth = 0;
        $inConstant = false;
        $recent = [];
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            if ($id === T_ATTRIBUTE) {
                $attributeDepth = 1;

                continue;
            }
            if ($attributeDepth > 0) {
                $attributeDepth += ($text === '[' ? 1 : 0) - ($text === ']' ? 1 : 0);

                continue;
            }
            $inConstant = $controller && ($id === T_CONST || ($inConstant && $text !== ';'));
            $isString = $id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE;
            if ($isString && ! $inConstant && ! $this->isLogArgument($recent)) {
                $messages[] = $text;
            }
            $recent = array_slice([...$recent, $text], -4);
        }

        return $messages;
    }

    /**
     * @param  list<string>  $recent  the four tokens before the string
     */
    private function isLogArgument(array $recent): bool
    {
        return count($recent) === 4
            && $recent[0] === 'Log' && $recent[1] === '::' && $recent[3] === '('
            && in_array($recent[2], ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true);
    }

    private function namesASurface(string $message): bool
    {
        $tools = implode('|', array_map(static fn (string $tool): string => preg_quote($tool, '/'), require self::APP.'/Mcp/tool-names.php'));
        $routeKey = preg_match('/^[\'"](GET|POST|PUT|PATCH|DELETE) \/\S*[\'"]$/', $message) === 1;

        return preg_match('/`pae(-artisan)? |\bpae(-artisan)? [a-z]+:[a-z]/', $message) === 1
            || preg_match('/`[a-z]+(:[a-z][a-z0-9-]*)+[ `]/', $message) === 1
            || (str_contains($message, ' ') && preg_match("/(?<![\\w.\\/-])({$tools})(?![\\w\\/-])/", $message) === 1)
            || (! $routeKey && preg_match('/\b(GET|POST|PUT|PATCH|DELETE) \/[a-z]|\/projects\/\{/', $message) === 1);
    }

    /**
     * @param  array<string, list<string>>  $found
     */
    private function describe(array $found): string
    {
        $lines = [];
        foreach ($found as $file => $messages) {
            foreach ($messages as $message) {
                $lines[] = $file.': '.substr(trim($message), 0, 120);
            }
        }

        return "Name the surface in a hint instead. Found:\n".implode("\n", $lines);
    }
}
