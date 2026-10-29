<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * The `web:` line of a Procfile — a project naming its own start command.
 */
final class Procfile
{
    public const FILENAME = 'Procfile';

    private const WEB_LINE = '/^\s*web\s*:\s*(.+)$/mi';

    private function __construct(private readonly ?string $contents) {}

    public static function of(ProjectContext $project): self
    {
        return new self($project->contents(self::FILENAME));
    }

    public function webCommand(): ?string
    {
        if ($this->contents === null || preg_match(self::WEB_LINE, $this->contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) ?: null;
    }
}
