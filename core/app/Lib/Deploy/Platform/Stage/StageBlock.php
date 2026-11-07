<?php

namespace App\Lib\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Template\Template;

/**
 * One phase of the entrypoint, run only when this boot is that phase: `prepend` holds what only the
 * engine knows (waiting for MySQL), `extra` a project's optional first-deploy `setup` script.
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
     * An overridden stage is never empty, even with nothing in it, so the log tells an emptied
     * stage from one the platform does not have.
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
