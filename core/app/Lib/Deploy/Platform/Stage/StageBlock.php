<?php

namespace App\Lib\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Template\Template;

/**
 * One phase of the entrypoint: the block that runs only when this boot is
 * that phase.
 *
 * `prepend` is for what only the engine knows — whether this account got a
 * MySQL sidecar, and therefore whether the migration has to wait for it.
 * `extra` is a project's own `setup` script: it runs once, on the first
 * deploy, and its failure is not the deploy's failure.
 */
final class StageBlock
{
    /**
     * @param  list<PlatformCommand>  $commands
     * @param  array<string, string>  $overrides
     * @param  array<string, string>  $prepend  id => command, run first
     * @param  array<string, string>  $extra  id => command, run last and optional
     * @param  bool  $overridden  the deploy request spoke for this stage, so
     *                            these commands are its commands and not the platform's
     */
    public function __construct(
        private readonly string $stage,
        private readonly array $commands,
        private readonly array $overrides = [],
        private readonly array $prepend = [],
        private readonly array $extra = [],
        private readonly bool $overridden = false
    ) {}

    /**
     * An overridden stage is never empty, even with nothing in it. "The
     * request emptied this stage" and "this platform has no such stage" are
     * different facts, and only the log can tell an operator which one
     * explains the migration that did not run.
     */
    public function isEmpty(): bool
    {
        return ! $this->overridden
            && $this->commands === []
            && $this->prepend === []
            && $this->extra === [];
    }

    public function render(): string
    {
        return rtrim(Template::named('script/entrypoint-stage')->render([
            'stage' => $this->stage,
            'commands' => $this->lines(),
        ]), "\n");
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $lines = [];
        if ($this->overridden) {
            $lines[] = 'pa_step '.$this->stage.' '.ShellQuote::of(
                $this->commands === []
                    ? 'replaced by the deploy request (no commands)'
                    : 'replaced by the deploy request'
            );
        }
        foreach ($this->prepend as $id => $run) {
            $lines = array_merge($lines, $this->announced((string) $id, $run));
        }
        foreach ($this->commands as $command) {
            $lines = array_merge($lines, (new CommandScript($command, $this->stage, $this->overrides))->lines());
        }
        foreach ($this->extra as $id => $run) {
            $lines = array_merge($lines, $this->announced((string) $id, $run, true));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function announced(string $id, string $run, bool $optional = false): array
    {
        $quoted = ShellQuote::of($id);

        return [
            'pa_step '.$this->stage.' '.$quoted,
            $optional ? $run.' || pa_skip '.$this->stage.' '.$quoted : $run,
        ];
    }
}
