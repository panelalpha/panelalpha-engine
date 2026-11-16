<?php

namespace App\System\Privileged;

/** Compose commands as quoted shell lines, for callers that chain them. */
final class RootComposeLines
{
    private const AREA = 'lines';

    public function __construct(private readonly string $file) {}

    public function pull(): string
    {
        return $this->compose('composePull', []);
    }

    public function build(): string
    {
        return $this->compose('composeBuild', []);
    }

    /** @param  list<string>  $values */
    private function compose(string $action, array $values): string
    {
        return Root::actionLine(self::AREA, $action, [Arguments::path($this->file), ...$values]);
    }
}
