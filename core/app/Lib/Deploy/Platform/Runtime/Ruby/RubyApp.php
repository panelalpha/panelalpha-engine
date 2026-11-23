<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A Ruby checkout and the questions every Ruby generator asks it; deployable only as Rails, with a
 * `config.ru`, or with a Procfile web process, since a Gemfile alone gives no start command.
 */
final class RubyApp
{
    private const RAILS_MARKER = 'config/application.rb';

    private const RACKUP = 'config.ru';

    private const RAILS_GEMS = ['rails', 'railties'];

    private const RAILS_APPLICATION = 'Rails::Application';

    private readonly Gemfile $gemfile;

    private readonly Procfile $procfile;

    public function __construct(public readonly ProjectContext $project)
    {
        $this->gemfile = Gemfile::of($project);
        $this->procfile = Procfile::of($project);
    }

    /**
     * @param  array<string, true>  $files  lowercase basename => true
     */
    public static function at(string $projectDir, array $files = []): self
    {
        return new self(ProjectContext::make($projectDir, $files));
    }

    public function gemfile(): Gemfile
    {
        return $this->gemfile;
    }

    /** Rails by what it loads, not by layout: Sinatra and Hanami keep a config/application.rb too. */
    public function isRails(): bool
    {
        return $this->project->hasFile('gemfile')
            && $this->project->isFile(self::RAILS_MARKER)
            && ($this->dependsOnRails() || $this->definesRailsApplication());
    }

    private function dependsOnRails(): bool
    {
        return $this->gemfile->requiresAny(self::RAILS_GEMS) || $this->gemfile->locks('railties');
    }

    private function definesRailsApplication(): bool
    {
        return str_contains($this->project->contents(self::RAILS_MARKER) ?? '', self::RAILS_APPLICATION);
    }

    public function isRackup(): bool
    {
        return $this->project->isFile(self::RACKUP);
    }

    public function isDeployable(): bool
    {
        return $this->project->hasFile('gemfile')
            && ($this->isRails() || $this->project->hasFile(self::RACKUP) || $this->webCommand() !== null);
    }

    public function hasLockfile(): bool
    {
        return $this->project->isFile(Gemfile::LOCKFILE);
    }

    public function webCommand(): ?string
    {
        return $this->procfile->webCommand();
    }

    public function hasConfigFile(string $relative): bool
    {
        return $this->project->isFile($relative);
    }
}
