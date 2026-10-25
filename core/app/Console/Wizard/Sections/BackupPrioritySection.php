<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Lib\Backup\ArchivePriority;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * How hard a backup or a restore may push while it runs. See
 * {@see ArchivePriority} for what the two numbers mean and where they are kept.
 */
class BackupPrioritySection implements Section
{
    use KeepsAReceipt;

    public static function key(): string
    {
        return 'backups';
    }

    public static function label(): string
    {
        return 'Backups — how hard a backup or restore may push against your sites';
    }

    public static function hint(): string
    {
        return 'The CPU and disk priority the backup and restore archives run at.';
    }

    public function run(bool $dryRun): int
    {
        ['nice' => $storedNice, 'ionice_class' => $storedIonice] = ArchivePriority::configured()->toArray();

        $this->header($storedNice, $storedIonice);

        $nice = $this->askNice($storedNice);
        $ionice = $this->askIonice($storedIonice);

        if ($nice === $storedNice && $ionice === $storedIonice) {
            note('Nothing changed.');
            pause('Press enter to carry on...');

            return 0;
        }

        $this->store($nice, $ionice, $dryRun);
        pause('Press enter to carry on...');

        return 0;
    }

    private function header(?int $nice, ?int $ionice): void
    {
        Screen::draw(self::label(), sprintf(
            "CPU priority:  %s\nDisk priority: %s",
            self::describeNice($nice),
            self::describeIonice($ionice)
        ));
        note('An archive at the server\'s own priority competes with every site on it. Leaving both empty keeps it that way.');
    }

    private function askNice(?int $stored): ?int
    {
        $answer = self::answer(text(
            label: 'CPU priority (nice)',
            default: $stored === null ? '' : (string) $stored,
            placeholder: 'empty — the server\'s own priority',
            validate: self::niceProblem(...),
            hint: sprintf(
                'A number from %d to %d: the higher it is, the longer a large backup takes and the less it takes from your sites.',
                ArchivePriority::NICE_MIN,
                ArchivePriority::NICE_MAX
            ),
        ));

        return $answer === '' ? null : (int) $answer;
    }

    private function askIonice(?int $stored): ?int
    {
        $answer = self::answer(text(
            label: 'Disk priority (ionice class)',
            default: $stored === null ? '' : (string) $stored,
            placeholder: 'empty — the server\'s own priority',
            validate: self::ioniceProblem(...),
            hint: sprintf(
                '%d best-effort, %d idle: idle gives a backup the disk only when nothing else wants it.',
                ArchivePriority::IONICE_BEST_EFFORT,
                ArchivePriority::IONICE_IDLE
            ),
        ));

        return $answer === '' ? null : (int) $answer;
    }

    private function store(?int $nice, ?int $ionice, bool $dryRun): void
    {
        $description = sprintf('Backup archive priority set to %s, %s.', self::describeNice($nice), self::describeIonice($ionice));

        if ($dryRun) {
            note('Dry run: '.lcfirst($description), 'warning');

            return;
        }

        try {
            ArchivePriority::apply($nice, $ionice);
        } catch (Throwable $e) {
            warning($e->getMessage());

            return;
        }

        $this->receipt[] = $description;
        note($description.' The next backup or restore runs at it.', 'info');
    }

    /** "off" says empty without clearing a field that already shows a number. */
    private static function answer(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['', 'off', 'none'], true) ? '' : $value;
    }

    private static function niceProblem(string $value): ?string
    {
        return self::problem($value, static fn (?int $number): ?string => ArchivePriority::problem($number, null));
    }

    private static function ioniceProblem(string $value): ?string
    {
        return self::problem($value, static fn (?int $number): ?string => ArchivePriority::problem(null, $number));
    }

    /** @param callable(?int): ?string $check */
    private static function problem(string $value, callable $check): ?string
    {
        $answer = self::answer($value);

        if ($answer === '') {
            return null;
        }

        return ctype_digit($answer) ? $check((int) $answer) : 'Enter a whole number, or "off".';
    }

    private static function describeNice(?int $nice): string
    {
        return $nice === null ? 'the server\'s own' : 'nice '.$nice;
    }

    private static function describeIonice(?int $ionice): string
    {
        return match ($ionice) {
            ArchivePriority::IONICE_BEST_EFFORT => 'best-effort',
            ArchivePriority::IONICE_IDLE => 'idle',
            default => 'the server\'s own',
        };
    }
}
