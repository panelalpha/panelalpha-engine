<?php

namespace App\Lib\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\PlatformCommand;

/**
 * The last line of the entrypoint: the server replaces the shell as PID 1, so
 * Docker's stop signal reaches the application instead of a shell that would
 * ignore it and wait out the ten-second kill timer on every deploy.
 */
final class ServeCommand
{
    /**
     * Shell constructs that do work before handing over — Apache needs its
     * document root exported, and a PHP project may or may not have a
     * `public/` — carry their own `exec` at the end. Prefixing `exec` onto
     * `if` or `{` is a syntax error.
     *
     * @var list<string>
     */
    private const SELF_EXECUTING = ['if ', 'for ', 'while ', 'case ', 'exec ', '{ '];

    /**
     * `exec` at the start of a statement anywhere in the command: the recipe
     * hands over itself, after doing something first.
     *
     * The Rust and Go serve commands open with a guard --
     * `[ -x ./target/release/app ] || { echo "…"; exit 1; }; exec ./target/release/app`
     * -- so that a workspace whose binary is not where the recipe expected it
     * says so. Prefixed with `exec`, the shell is replaced by `[` and the
     * guard's message and exit code go with it: the container ends silently
     * and restart-loops with nothing in the log but `start: serve`, which is
     * the exact failure the guard was written to prevent. Komodo, whose
     * workspace builds `core` and `km` and no `app`.
     */
    private const EXECS_ITSELF = '/(^|[;&|]\s*)exec\s/';

    /** A top-level `&&`, `||`, `;` or pipe: more than one command to run. */
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
        // Quoted text is not the outer command: the working-directory wrapper
        // is `sh -c 'cd … && exec node server.js'`, and that exec belongs to
        // the inner shell -- the outer `sh` still has to be exec'd.
        $outer = (string) preg_replace('/\'[^\']*\'|"[^"]*"/', '', $run);
        if (preg_match(self::EXECS_ITSELF, $outer) === 1) {
            return '';
        }
        // `exec` binds to the first simple command, so prefixing a chain runs
        // its first word and throws the rest away. The Python last-resort
        // server is `mkdir … && printf … > index.html && python -m http.server`:
        // prefixed, the shell became `mkdir`, exited 0, and the account
        // restart-looped with nothing in the log but `start: serve` -- the
        // failure this class was written to prevent, arriving by a new road.
        if (preg_match(self::HAS_CHAIN, $outer) === 1) {
            return '';
        }

        return 'exec ';
    }
}
