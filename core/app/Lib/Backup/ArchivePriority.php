<?php

namespace App\Lib\Backup;

use App\Models\Setting;
use App\System\Privileged;
use RuntimeException;
use Throwable;

/**
 * How hard the backup and restore archives may push: the niceness and the IO
 * class their `tar` runs at, kept in the engine's settings table. An unset value
 * means the archive runs at the engine's own priority, which is what every
 * install has until an operator asks for less.
 */
final class ArchivePriority
{
    public const SETTING_NICE = 'backup_nice';

    public const SETTING_IONICE_CLASS = 'backup_ionice_class';

    /** The scheduler's own range: 0 leaves it as it was, 19 waits as long as anything may. */
    public const NICE_MIN = 0;

    public const NICE_MAX = 19;

    /**
     * `ionice` classes that lower an archive's share of the disk rather than
     * raise it: 2 best-effort, 3 idle. Class 1 is realtime and is not offered.
     */
    public const IONICE_BEST_EFFORT = 2;

    public const IONICE_IDLE = 3;

    public const IONICE_CLASSES = [self::IONICE_BEST_EFFORT, self::IONICE_IDLE];

    public function __construct(
        private readonly ?int $nice = null,
        private readonly ?int $ioniceClass = null,
    ) {}

    /**
     * What the settings say. A value outside its range is ignored rather than
     * obeyed: unreadable is the engine's own priority, never a surprise.
     */
    public static function configured(): self
    {
        return new self(
            self::niceOf(self::stored(self::SETTING_NICE)),
            self::ioniceClassOf(self::stored(self::SETTING_IONICE_CLASS)),
        );
    }

    /**
     * Store both values and return them; a null one switches that half off again.
     *
     * @throws RuntimeException when a value is out of range
     */
    public static function apply(?int $nice, ?int $ioniceClass): self
    {
        $problem = self::problem($nice, $ioniceClass);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        self::store(self::SETTING_NICE, $nice);
        self::store(self::SETTING_IONICE_CLASS, $ioniceClass);

        return new self($nice, $ioniceClass);
    }

    /** Why these two cannot be stored, or null when they can. */
    public static function problem(?int $nice, ?int $ioniceClass): ?string
    {
        if ($nice !== null && ($nice < self::NICE_MIN || $nice > self::NICE_MAX)) {
            return sprintf('Niceness must be between %d and %d, or empty for no change.', self::NICE_MIN, self::NICE_MAX);
        }

        if ($ioniceClass !== null && ! in_array($ioniceClass, self::IONICE_CLASSES, true)) {
            return sprintf('The IO class must be %s, or empty for no IO class.', implode(' or ', self::IONICE_CLASSES));
        }

        return null;
    }

    /**
     * What goes before the archive program, empty when nothing is set.
     *
     * @return list<string>
     */
    public function prefix(): array
    {
        return [...$this->niceCommand(), ...$this->ioniceCommand()];
    }

    /**
     * The root broker's request to archive $entry under $dir: tar runs in the broker, so the priority is one of its
     * values rather than a prefix here. With nothing set it is the plain createArchive request.
     *
     * @param  array<int, string>  $excluded
     * @return list<string>
     */
    public function createArchive(string $dir, string $archive, string $entry, array $excluded = []): array
    {
        $files = Privileged::hostFiles();
        if ($this->nice === null && $this->ioniceClass === null) {
            return $files->createArchive($dir, $archive, $entry, $excluded);
        }

        return $files->createArchiveAtPriority($dir, $archive, $this->nice, $this->ioniceClass, $entry, $excluded);
    }

    /** @return array{nice: ?int, ionice_class: ?int} */
    public function toArray(): array
    {
        return ['nice' => $this->nice, 'ionice_class' => $this->ioniceClass];
    }

    /** @return list<string> */
    private function niceCommand(): array
    {
        return $this->nice === null ? [] : ['nice', '-n', (string) $this->nice];
    }

    /** @return list<string> */
    private function ioniceCommand(): array
    {
        return $this->ioniceClass === null ? [] : ['ionice', '-c', (string) $this->ioniceClass];
    }

    /** A settings table that cannot be read leaves a backup at the engine's own priority rather than failing it. */
    private static function stored(string $name): ?string
    {
        try {
            return Setting::get($name);
        } catch (Throwable) {
            return null;
        }
    }

    private static function store(string $name, ?int $value): void
    {
        if ($value === null) {
            Setting::forget($name);

            return;
        }

        Setting::set($name, (string) $value);
    }

    private static function niceOf(?string $value): ?int
    {
        return self::wholeNumber($value, self::NICE_MIN, self::NICE_MAX);
    }

    private static function ioniceClassOf(?string $value): ?int
    {
        $class = self::wholeNumber($value, self::IONICE_CLASSES[0], self::IONICE_CLASSES[1]);

        return $class !== null && in_array($class, self::IONICE_CLASSES, true) ? $class : null;
    }

    private static function wholeNumber(?string $value, int $min, int $max): ?int
    {
        if ($value === null || ! ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }
}
