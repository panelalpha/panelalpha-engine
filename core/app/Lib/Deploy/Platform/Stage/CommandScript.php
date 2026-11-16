<?php

namespace App\Lib\Deploy\Platform\Stage;

use App\Lib\Deploy\Platform\PlatformCommand;

/**
 * One manifest command as the lines that run it: what it announces, how it
 * is wrapped, and what happens when it fails.
 */
final class CommandScript
{
    private readonly string $run;

    /**
     * @param  array<string, string>  $overrides  command id => the command as
     *                                            resolved for this project, replacing the manifest's default
     */
    public function __construct(
        private readonly PlatformCommand $command,
        private readonly string $stage,
        array $overrides = []
    ) {
        $this->run = WorkingDirectory::wrap(
            $overrides[$command->id] ?? $command->run,
            $command->workdir
        );
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_filter([
            $this->descriptionLine(),
            'pa_step '.$this->stage.' '.ShellQuote::of($this->command->id),
            $this->runLine(),
        ]));
    }

    /** Prefixes every line, so a folded description cannot land in the entrypoint as bare shell. */
    private function descriptionLine(): ?string
    {
        return $this->command->description === null
            ? null
            : '# '.str_replace("\n", "\n# ", $this->command->description);
    }

    /**
     * An optional step must not take the boot down, but it must still be visible, or a
     * half-configured container reaches production looking healthy.
     */
    private function runLine(): string
    {
        $run = $this->timed($this->run);

        return $this->command->optional
            ? $run.' || pa_skip '.$this->stage.' '.ShellQuote::of($this->command->id)
            : $run;
    }

    /**
     * `--foreground` so a timeout kills the command rather than only the
     * timeout process, and the app never outlives the step that hung.
     */
    private function timed(string $run): string
    {
        if ($this->command->timeout === null) {
            return $run;
        }

        return 'timeout --foreground '.$this->command->timeout.' sh -c '.ShellQuote::of($run);
    }
}
