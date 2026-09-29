<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Lib\Host\HostMemoryProbe;
use App\Support\SitesDbCaches;
use App\System;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/** sites-db's MariaDB cache sizes. See {@see SitesDbCaches}. */
class SitesDbSection implements Section
{
    use KeepsAReceipt;

    private SitesDbCaches $caches;

    public function __construct(?SitesDbCaches $caches = null)
    {
        $this->caches = $caches ?? new SitesDbCaches(new System());
    }

    public static function key(): string
    {
        return 'sites-db';
    }

    public static function label(): string
    {
        return 'Site database — MariaDB cache sizes';
    }

    public static function hint(): string
    {
        return 'Memory the database for hosted PHP sites keeps for its caches.';
    }

    public function run(bool $dryRun): int
    {
        while (true) {
            $configured = $this->caches->configured();
            $this->header($configured, $this->caches->running());

            $options = [];
            foreach (SitesDbCaches::CACHES as $key => $cache) {
                $options[$key] = "{$cache['label']} — {$configured[$key]}";
            }
            $options['check'] = 'Check it again';
            $options['back'] = 'Back';

            $choice = (string) select(
                label: 'What would you like to change?',
                options: $options,
                hint: 'Larger caches make big site databases faster, and hold that memory even when idle.',
            );
            if ($choice === 'back') {
                return 0;
            }
            if ($choice !== 'check') {
                $this->setSize($choice, $configured[$choice], $dryRun);
            }
        }
    }

    /**
     * @param array<string, string> $configured
     * @param ?array<string, int> $running
     */
    private function header(array $configured, ?array $running): void
    {
        $lines = [$running === null ? 'sites-db is not running; changes apply when it starts.' : 'Configured    Running'];
        foreach (SitesDbCaches::CACHES as $key => $cache) {
            $lines[] = sprintf('%-20s %-8s%s', $cache['label'], $configured[$key], $running === null ? '' : $running[$key] . 'M');
        }

        Screen::draw(self::label(), implode("\n", $lines));
    }

    private function setSize(string $key, string $current, bool $dryRun): void
    {
        $cache = SitesDbCaches::CACHES[$key];
        Screen::draw(self::label() . '  ·  ' . $cache['label']);
        note("A size like 32M or 1G. The engine's default is {$cache['default']}; MariaDB's own is 128M.");

        $hostMb = HostMemoryProbe::current()->totalMb;
        $size = strtoupper(trim(text(
            label: $cache['label'],
            default: $current,
            validate: fn (string $v): ?string => SitesDbCaches::badSize($key, $v, $hostMb),
        )));
        if ($size === strtoupper($current)) {
            return;
        }

        if ($dryRun) {
            note("Dry run: {$key} would become {$size}, and sites-db would be recreated.", 'warning');
            pause('Press enter to carry on...');

            return;
        }

        if ($this->caches->running() !== null
            && !confirm('sites-db restarts to apply it: hosted sites lose their database for a few seconds. Go ahead?', default: true)) {
            return;
        }

        try {
            $result = $this->caches->apply([$key => $size]);
        } catch (Throwable $e) {
            warning('Could not apply it: ' . trim($e->getMessage()));
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt[] = "{$cache['label']} set to {$size}.";
        note(end($this->receipt) . ($result['recreated'] ? ' sites-db was recreated with it.' : ' It applies when sites-db next starts.'), 'info');
        pause('Press enter to carry on...');
    }
}
