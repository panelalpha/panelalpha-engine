<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * The Process a faked host would have returned, without starting anything.
 *
 * Symfony's Process throws from getOutput() until it has been run, so a double
 * that wants to answer for a command has to override the four accessors. This
 * is that, once, instead of in every test file that needs it.
 */
final class FakeProcess extends Process
{
    private const ANSWERED = ['files.isFile', 'files.isDirectory', 'files.exists', 'files.existingRealPaths', 'files.listEntryTypes'];

    private function __construct(
        private readonly int $code,
        private readonly string $stdout,
        private readonly string $stderr,
    ) {
        parent::__construct(['true']);
    }

    public static function of(int $code, string $stdout = '', string $stderr = ''): self
    {
        return new self($code, $stdout, $stderr);
    }

    public static function ok(string $stdout = ''): self
    {
        return new self(0, $stdout, '');
    }

    public static function failed(string $stderr = '', int $code = 1): self
    {
        return new self($code, '', $stderr);
    }

    /**
     * What a host would answer: a root `test -f|-d|-e`, `realpath -e` or listing
     * of entry types is read from the real filesystem, and everything else succeeds.
     *
     * @param string|list<string> $cmd
     */
    public static function forCommand(string|array $cmd): self
    {
        if (! is_array($cmd) || ! in_array(LocalRoot::action($cmd), self::ANSWERED, true)) {
            return self::ok();
        }
        if (LocalRoot::action($cmd) === 'files.existingRealPaths') {
            return self::realPaths(LocalRoot::values($cmd));
        }
        if (LocalRoot::action($cmd) === 'files.listEntryTypes') {
            return self::entryTypes((string) (LocalRoot::values($cmd)[0] ?? ''));
        }
        $argv = LocalRoot::plain($cmd);
        if (($argv[0] ?? '') !== 'test') {
            return self::ok();
        }

        $exists = match ($argv[1] ?? '') {
            '-f' => is_file($argv[2] ?? ''),
            '-d' => is_dir($argv[2] ?? ''),
            '-e' => file_exists($argv[2] ?? ''),
            default => false,
        };

        return $exists ? self::ok() : self::failed();
    }

    /** find's `%y %f` record of each entry, NUL-terminated, without following a link. */
    private static function entryTypes(string $dir): self
    {
        if (is_link($dir) || ! is_dir($dir)) {
            return self::ok();
        }
        $records = '';
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            $path = $dir.'/'.$name;
            $records .= (is_link($path) ? 'l' : (is_dir($path) ? 'd' : 'f')).' '.$name."\0";
        }

        return self::ok($records);
    }

    /** @param  list<string>  $paths */
    private static function realPaths(array $paths): self
    {
        return self::ok(implode("\n", array_map(static fn (string $path): string => realpath($path) ?: $path, $paths))."\n");
    }

    public function isSuccessful(): bool
    {
        return $this->code === 0;
    }

    public function getExitCode(): ?int
    {
        return $this->code;
    }

    public function getOutput(): string
    {
        return $this->stdout;
    }

    public function getErrorOutput(): string
    {
        return $this->stderr;
    }
}
