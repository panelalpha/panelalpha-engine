<?php

namespace App\Lib\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\PlatformCommand;

/**
 * The last line of the entrypoint: the server replaces the shell as PID 1, so Docker's stop signal reaches the
 * application instead of a shell that waits out the ten-second kill timer.
 */
final class ServeCommand
{
    /**
     * Shell constructs that do work before handing over carry their own `exec`; prefixing one onto `if` or `{` is a
     * syntax error.
     *
     * @var list<string>
     */
    private const SELF_EXECUTING = ['if ', 'for ', 'while ', 'case ', 'exec ', '{ '];

    /**
     * `exec` starting any statement: the recipe hands over itself after a guard such as Rust's `[ -x … ] || exit 1;`,
     * which a prefixed `exec` would replace with `[`, restart-looping silently (Komodo).
     */
    private const EXECS_ITSELF = '/(^|[;&|]\s*)exec\s/';

    /**
     * A top-level `&&`, `||`, `;` or pipe: `exec` binds to the first command and would drop the rest, as the Python
     * fallback's `mkdir … && python -m http.server` did.
     */
    private const HAS_CHAIN = '/(\&\&|\|\||;|(?<!\|)\|(?!\|))/';

    private readonly string $run;

    /**
     * @param  array<string, string>  $overrides  command id => resolved command
     */
    public function __construct(private readonly PlatformCommand $command, array $overrides = [])
    {
        $this->run = WorkingDirectory::wrapForExec(
            $overrides[$command->id] ?? $command->run,
            $command->workdir
        );
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return [
            'pa_step start '.ShellQuote::of($this->command->id),
            $this->execPrefix().$this->run,
        ];
    }

    private function execPrefix(): string
    {
        $run = ltrim($this->run);
        foreach (self::SELF_EXECUTING as $keyword) {
            if (str_starts_with($run, $keyword)) {
                return '';
            }
        }
        $outer = self::outerCommand($run);
        if (preg_match(self::EXECS_ITSELF, $outer) === 1) {
            return '';
        }
        if (preg_match(self::HAS_CHAIN, $outer) === 1) {
            return '';
        }

        return 'exec ';
    }

    /**
     * The command without its quoted text: the workdir wrapper's `sh -c 'cd … && exec node server.js'` exec belongs
     * to the inner shell, and the outer `sh` still has to be exec'd.
     */
    private static function outerCommand(string $run): string
    {
        return (string) preg_replace('/\'[^\']*\'|"[^"]*"/', '', $run);
    }
}
