<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

/**
 * The command that starts the app, as a JSON argv array for `CMD`. The Gemfile
 * names the server; the project's layout settles how to call it.
 */
final class RubyServer
{
    public const PORT = 3000;

    private const PUMA_CONFIG = 'config/puma.rb';

    private const UNICORN_CONFIG = 'config/unicorn.rb';

    public function __construct(private readonly RubyApp $app, private readonly int $port = self::PORT) {}

    public static function command(RubyApp $app, int $port = self::PORT): string
    {
        return (new self($app, $port))->render();
    }

    public function render(): string
    {
        foreach ($this->candidates() as $candidate) {
            $argv = $candidate();
            if ($argv !== null) {
                return self::argv($argv);
            }
        }

        return self::argv($this->procfileWeb() ?? $this->rackupShell());
    }

    /**
     * @return list<callable(): (list<string>|null)>
     */
    private function candidates(): array
    {
        return [
            $this->falcon(...),
            $this->puma(...),
            $this->unicorn(...),
            $this->railsServer(...),
            $this->rackup(...),
        ];
    }

    /** @return list<string>|null */
    private function falcon(): ?array
    {
        return $this->requires('falcon') ? ['bundle', 'exec', 'falcon', 'host'] : null;
    }

    /**
     * `-C config/puma.rb` is a Rails convention; a Rack app has none and puma
     * exits if told to read a file that is not there.
     *
     * @return list<string>|null
     */
    private function puma(): ?array
    {
        if (! $this->requires('puma')) {
            return null;
        }

        return $this->app->hasConfigFile(self::PUMA_CONFIG)
            ? ['bundle', 'exec', 'puma', '-C', self::PUMA_CONFIG]
            : ['bundle', 'exec', 'puma', '-b', "tcp://0.0.0.0:{$this->port}"];
    }

    /** @return list<string>|null */
    private function unicorn(): ?array
    {
        return $this->requires('unicorn')
            ? ['bundle', 'exec', 'unicorn', '-c', self::UNICORN_CONFIG]
            : null;
    }

    /** @return list<string>|null */
    private function railsServer(): ?array
    {
        return $this->app->hasConfigFile('config/application.rb')
            ? ['bundle', 'exec', 'rails', 'server', '-b', '0.0.0.0', '-p', (string) $this->port]
            : null;
    }

    /** @return list<string>|null */
    private function rackup(): ?array
    {
        return $this->app->isRackup()
            ? ['bundle', 'exec', 'rackup', 'config.ru', '-o', '0.0.0.0', '-p', (string) $this->port]
            : null;
    }

    /** @return list<string>|null */
    private function procfileWeb(): ?array
    {
        $web = $this->app->webCommand();

        return $web === null ? null : ['sh', '-c', $web];
    }

    /** @return list<string> */
    private function rackupShell(): array
    {
        return ['sh', '-c', "bundle exec rackup config.ru -o 0.0.0.0 -p {$this->port}"];
    }

    /**
     * Whether the server gem will be in the image: built with
     * BUNDLE_WITHOUT="development:test", so Redmine's `group :test` puma was
     * picked here and the container exited 127 on `bundler: command not found:
     * puma`.
     */
    private function requires(string $gem): bool
    {
        return $this->app->gemfile()->requiresAtRuntime($gem);
    }

    /**
     * @param  list<string>  $argv
     */
    private static function argv(array $argv): string
    {
        return (string) json_encode($argv, JSON_UNESCAPED_SLASHES);
    }
}
