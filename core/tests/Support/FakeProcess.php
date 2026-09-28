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
     * What a host would answer, for a test that must not run the command.
     *
     * `sudo test -f|-d PATH` is answered from the real filesystem, because
     * callers branch on its exit code and a blanket success would tell them
     * every file exists. Everything else simply succeeds: a test asserting on
     * what was run reads the recorded command, not the result.
     *
     * @param string|list<string> $cmd
     */
    public static function forCommand(string|array $cmd): self
    {
        $argv = is_array($cmd) ? array_values($cmd) : (preg_split('/\s+/', trim($cmd)) ?: []);

        if (($argv[0] ?? '') !== 'sudo' || ($argv[1] ?? '') !== 'test') {
            return self::ok();
        }

        $exists = match ($argv[2] ?? '') {
            '-f' => is_file($argv[3] ?? ''),
            '-d' => is_dir($argv[3] ?? ''),
            '-e' => file_exists($argv[3] ?? ''),
            default => false,
        };

        return $exists ? self::ok() : self::failed();
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
