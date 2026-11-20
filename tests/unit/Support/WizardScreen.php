<?php

namespace Tests\Support;

use App\System\Privileged\Root;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use ReflectionProperty;

/**
 * Drives a `pae configure` screen with faked keys against a throwaway `.env`,
 * and answers root broker requests with a script that records them.
 *
 * Any artisan call earlier in the process switches prompts to their fallback,
 * which answers with defaults and loops a menu forever, so it is switched off
 * here; fake() leaves prompts interactive, so that is undone on the way out.
 */
trait WizardScreen
{
    private string $wizardDir = '';

    protected function startWizardScreen(string $env = "APP_KEY=base64:dGVzdA==\n"): void
    {
        $this->promptsAnswerKeys();

        $this->wizardDir = sys_get_temp_dir().'/wizard-screen-'.bin2hex(random_bytes(4));
        mkdir($this->wizardDir);
        file_put_contents($this->wizardDir.'/.env', $env);
        $this->app->useEnvironmentPath($this->wizardDir);
        $this->app->loadEnvironmentFrom('.env');
    }

    /** Undo the fallback an artisan call switches on, such as the one that migrates a test database. */
    protected function promptsAnswerKeys(): void
    {
        (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    }

    protected function stopWizardScreen(): void
    {
        Prompt::interactive(false);
        Root::answerWith(null);

        if ($this->wizardDir !== '' && is_dir($this->wizardDir)) {
            array_map('unlink', array_filter(glob($this->wizardDir.'/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
            @rmdir($this->wizardDir);
        }
    }

    protected function envPath(): string
    {
        return $this->wizardDir.'/.env';
    }

    protected function envContents(): string
    {
        return (string) file_get_contents($this->envPath());
    }

    /**
     * Point root broker requests at a script that logs its arguments, one
     * request a line, prints $output and exits with $exit.
     */
    protected function brokerAnswers(string $output = '', int $exit = 0): void
    {
        $script = $this->wizardDir.'/broker';
        file_put_contents($script, sprintf(
            "#!/bin/sh\necho \"\$*\" >> %s\nprintf '%%s' %s\nexit %d\n",
            escapeshellarg($this->wizardDir.'/broker.log'),
            escapeshellarg($output),
            $exit,
        ));
        chmod($script, 0755);
        Root::answerWith($script);
    }

    /** @return list<string> every broker request so far, as `area.action -- values` */
    protected function brokerRequests(): array
    {
        $log = $this->wizardDir.'/broker.log';

        return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
    }

    /** Fake the terminal with these key presses, wide enough that no option is cut short. */
    protected function keys(array $keys): void
    {
        Prompt::fake($keys);
        Prompt::terminal()->shouldReceive('cols')->andReturn(240);
    }

    /**
     * The keys that choose row $row of a select, counted from 0, whatever row it opened on.
     *
     * @return list<string>
     */
    protected static function row(int $row): array
    {
        return [Key::CTRL_A, ...array_fill(0, $row, Key::DOWN), Key::ENTER];
    }

    /** The keys that erase $replacing, the field's default, then type $value and submit it. */
    protected static function typed(string $value, string $replacing = ''): string
    {
        return str_repeat(Key::BACKSPACE, mb_strlen($replacing)).$value.Key::ENTER;
    }

    /** What the screen showed, colours stripped and every run of whitespace one space, so wrapping does not matter. */
    protected function screen(): string
    {
        return (string) preg_replace('/[\s│┌┐└┘─]+/u', ' ', Prompt::strippedContent());
    }

    protected function assertScreenShows(string $text): void
    {
        $this->assertStringContainsString((string) preg_replace('/\s+/', ' ', $text), $this->screen());
    }

    protected function assertScreenDoesNotShow(string $text): void
    {
        $this->assertStringNotContainsString((string) preg_replace('/\s+/', ' ', $text), $this->screen());
    }
}
