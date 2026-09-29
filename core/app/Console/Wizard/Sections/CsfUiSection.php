<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Support\CsfUi;
use App\System;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

/** CSF's web UI on or off. See {@see CsfUi}. */
class CsfUiSection implements Section
{
    use KeepsAReceipt;

    private CsfUi $ui;

    public function __construct(?CsfUi $ui = null)
    {
        $this->ui = $ui ?? new CsfUi(new System());
    }

    public static function key(): string
    {
        return 'csf-ui';
    }

    public static function label(): string
    {
        return 'Firewall UI — CSF\'s web interface on port ' . CsfUi::PORT;
    }

    public static function hint(): string
    {
        return 'Off by default: it runs as a second lfd process.';
    }

    public function run(bool $dryRun): int
    {
        if (!$this->ui->installed()) {
            Screen::draw(self::label(), 'CSF is not installed on this server.');
            pause('Press enter to go back...');

            return 0;
        }

        while (true) {
            $on = $this->ui->enabled();
            $this->header($on);

            switch ((string) select(
                label: 'What would you like to do?',
                options: [
                    'toggle' => $on ? 'Turn it off' : 'Turn it on',
                    'check' => 'Check it again',
                    'back' => 'Back',
                ],
                default: 'toggle',
            )) {
                case 'toggle':
                    $this->toggle(!$on, $dryRun);
                    break;

                case 'check':
                    break;

                default:
                    return 0;
            }
        }
    }

    private function header(bool $on): void
    {
        $lines = ['UI:        ' . ($on ? 'on, port ' . CsfUi::PORT : 'off')];
        if ($on !== $this->ui->configured()) {
            $lines[] = 'csf.conf and .env disagree; the next update applies what .env says (' . CsfUi::ENV . ').';
        }

        Screen::draw(self::label(), implode("\n", $lines));
    }

    private function toggle(bool $on, bool $dryRun): void
    {
        if (!confirm(label: $on ? 'Turn the CSF UI on?' : 'Turn the CSF UI off?', default: false)) {
            return;
        }

        if ($dryRun) {
            note(sprintf('Dry run: %s would become %d and lfd would restart.', CsfUi::ENV, $on ? 1 : 0), 'warning');
            pause('Press enter to carry on...');

            return;
        }

        try {
            $this->ui->set($on);
        } catch (Throwable $e) {
            warning('Could not change it: ' . trim($e->getMessage()));
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt[] = $on ? 'CSF UI turned on.' : 'CSF UI turned off.';
        note(end($this->receipt), 'info');
        if ($on) {
            note(sprintf(
                "https://<server>:%d, user %s, password %s\nOnly addresses in /etc/csf/ui/ui.allow can reach it.",
                CsfUi::PORT,
                CsfUi::USERNAME,
                $this->ui->password() ?: '(not set: run scripts/csf.sh --install)'
            ));
        }
        pause('Press enter to carry on...');
    }
}
