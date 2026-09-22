<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Support\QueueWorkers;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * How many `queue:work` processes core's own supervisord runs.
 *
 * One queue serves everything async on this engine -- deploys, backups,
 * staging -- so this is the only knob for how much of it runs at once.
 * Applies live via `supervisorctl`, no container recreate; see
 * {@see QueueWorkers} for why that works here and did not through the
 * container's own environment.
 */
class QueueSection implements Section
{
    use KeepsAReceipt;

    public static function key(): string
    {
        return 'queue';
    }

    public static function label(): string
    {
        return 'Queue — how many deploys, backups or staging jobs run at once';
    }

    public static function hint(): string
    {
        return 'The number of queue:work processes core runs.';
    }

    public function run(bool $dryRun): int
    {
        while (true) {
            $configured = QueueWorkers::configured();
            $running = QueueWorkers::running();

            $this->header($configured, $running);

            switch ((string) select(
                label: 'What would you like to change?',
                options: [
                    'count' => 'Queue workers — how many run at once',
                    'check' => 'Check it again',
                    'back' => 'Back',
                ],
                default: 'count',
                hint: 'One process handles one job at a time; deploys, backups and staging all share this queue.',
            )) {
                case 'count':
                    $this->setCount($configured, $dryRun);
                    break;

                case 'check':
                    break;

                default:
                    return 0;
            }
        }
    }

    private function header(int $configured, ?int $running): void
    {
        $lines = [
            sprintf('Configured: %d', $configured),
            sprintf('Running:    %s', $running === null ? '(supervisord not reachable)' : (string) $running),
        ];

        if ($running !== null && $running !== $configured) {
            $lines[] = 'These differ -- something outside this wizard changed one of them since.';
        }

        Screen::draw(self::label(), implode("\n", $lines));
    }

    private function setCount(int $current, bool $dryRun): void
    {
        Screen::draw(self::label() . '  ·  Queue workers');
        note(sprintf(
            'A number from %d to %d. Deploys, backups and staging jobs all wait in this one '
            . 'queue, so this is how many of them the engine works on at once.',
            QueueWorkers::MIN,
            QueueWorkers::MAX
        ));

        $count = (int) trim(text(
            label: 'Queue workers',
            default: (string) $current,
            validate: fn (string $v): ?string => $this->badInput($v),
        ));

        if ($count === $current) {
            return;
        }

        if ($dryRun) {
            note(sprintf('Dry run: QUEUE_WORKERS would become %d.', $count), 'warning');
            pause('Press enter to carry on...');

            return;
        }

        try {
            QueueWorkers::apply($count);
        } catch (Throwable $e) {
            warning($e->getMessage());
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt[] = sprintf('Queue workers set to %d.', $count);
        note(end($this->receipt), 'info');

        $running = QueueWorkers::running();
        note($running === null
            ? 'Written, but supervisord could not be reached to confirm it live. It takes effect on its next read regardless.'
            : sprintf('Applied live -- %d workers running now.', $running));
        pause('Press enter to carry on...');
    }

    private function badInput(string $value): ?string
    {
        $value = trim($value);

        if (!ctype_digit($value)) {
            return 'Enter a whole number.';
        }

        return QueueWorkers::badCount((int) $value);
    }
}
