<?php

namespace App\Lib\Deploy\Platform;

/**
 * One command from a platform manifest, with its stages; an object, not a joined shell string, so each can be skipped, made
 * optional, guarded or named when it fails, which a `&&` blob cannot do. No Laravel dependencies, so unit-testable.
 */
final class PlatformCommand
{
    /** Build layer that runs before the source is copied in (lockfile installs). */
    public const ROLE_DEPENDENCIES = 'dependencies';

    /** Build layer that runs after the source is copied in (bundlers, caches). */
    public const ROLE_ASSETS = 'assets';

    /** Runtime commands belong to no build layer. */
    public const ROLE_NONE = 'none';

    /**
     * @param  list<string>  $stages  one or more of {@see PlatformStage}
     * @param  array<string, mixed>|null  $when  predicate gating this command,
     *                                           evaluated against the project by {@see PlatformMatcher}
     */
    private function __construct(
        public readonly string $id,
        public readonly array $stages,
        public readonly string $run,
        public readonly bool $optional,
        public readonly bool $serve,
        public readonly ?array $when,
        public readonly ?string $description,
        public readonly ?int $timeout,
        public readonly ?string $workdir,
        public readonly string $role,
        public readonly bool $before = false
    ) {}

    /**
     * Commands in the order they run: those marked `before` first, each group in declared order. An app config's commands follow
     * the platform's otherwise, which is wrong for a licence check or a config file the build is about to read.
     *
     * @param  list<self>  $commands
     * @return list<self>
     */
    public static function ordered(array $commands): array
    {
        $before = [];
        $after = [];
        foreach ($commands as $command) {
            if ($command->before) {
                $before[] = $command;
            } else {
                $after[] = $command;
            }
        }

        return array_merge($before, $after);
    }

    /**
     * @param  array<string, mixed>  $raw  one entry of a manifest's `commands`
     *
     * @throws ManifestException on anything the schema does not allow
     */
    public static function fromArray(array $raw, string $manifestId, int $index): self
    {
        $where = "{$manifestId}.commands[{$index}]";

        $run = $raw['run'] ?? null;
        if (! is_string($run) || trim($run) === '') {
            throw new ManifestException("{$where}: 'run' must be a non-empty string");
        }

        $stages = self::readStages($raw, $where);
        $id = self::readId($raw, $where);
        $serve = self::readServe($raw, $stages, $where);
        [$when, $timeout, $workdir, $description] = self::readSettings($raw, $where);
        $role = self::readRole($raw, $stages, $where);

        return new self(
            is_string($id) ? trim($id) : self::deriveId($run, $index),
            $stages,
            trim($run),
            (bool) ($raw['optional'] ?? false),
            $serve,
            is_array($when) ? $when : null,
            $description,
            is_int($timeout) ? $timeout : null,
            is_string($workdir) ? trim($workdir) : null,
            $role,
            (bool) ($raw['before'] ?? false)
        );
    }

    /** @param  array<string, mixed>  $raw */
    private static function readId(array $raw, string $where): ?string
    {
        $id = $raw['id'] ?? null;
        if ($id !== null && (! is_string($id) || trim($id) === '')) {
            throw new ManifestException("{$where}: 'id' must be a non-empty string when present");
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $stages
     */
    private static function readServe(array $raw, array $stages, string $where): bool
    {
        $serve = (bool) ($raw['serve'] ?? false);
        if ($serve && $stages !== [PlatformStage::START]) {
            throw new ManifestException(
                "{$where}: a 'serve' command must belong to the start stage and no other"
            );
        }

        return $serve;
    }

    /**
     * `when`, `timeout`, `workdir` and `description`, checked in that order.
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: ?array<string, mixed>, 1: ?int, 2: ?string, 3: ?string}
     */
    private static function readSettings(array $raw, string $where): array
    {
        $when = $raw['when'] ?? null;
        if ($when !== null && ! is_array($when)) {
            throw new ManifestException("{$where}: 'when' must be an object");
        }

        $timeout = $raw['timeout'] ?? null;
        if ($timeout !== null && (! is_int($timeout) || $timeout <= 0)) {
            throw new ManifestException("{$where}: 'timeout' must be a positive integer of seconds");
        }

        $workdir = $raw['workdir'] ?? null;
        if ($workdir !== null && (! is_string($workdir) || trim($workdir) === '')) {
            throw new ManifestException("{$where}: 'workdir' must be a non-empty string when present");
        }

        $description = $raw['description'] ?? null;
        if ($description !== null && ! is_string($description)) {
            throw new ManifestException("{$where}: 'description' must be a string when present");
        }

        return [$when, $timeout, $workdir, $description];
    }

    /**
     * Which build layer a build-stage command belongs to: DEPENDENCIES before the source is copied in, so BuildKit reuses it until
     * the lockfile changes; ASSETS after, as it needs the source. Runtime stages have no layers, so are always NONE.
     *
     * @param  list<string>  $stages
     */
    private static function readRole(array $raw, array $stages, string $where): string
    {
        $declared = $raw['role'] ?? null;
        $isBuild = in_array(PlatformStage::BUILD, $stages, true);

        if ($declared === null) {
            return $isBuild ? self::ROLE_ASSETS : self::ROLE_NONE;
        }
        if (! is_string($declared) || ! in_array($declared, [self::ROLE_DEPENDENCIES, self::ROLE_ASSETS], true)) {
            $valid = self::ROLE_DEPENDENCIES.', '.self::ROLE_ASSETS;
            throw new ManifestException("{$where}: 'role' must be one of {$valid}");
        }
        if (! $isBuild) {
            throw new ManifestException("{$where}: 'role' only applies to build-stage commands");
        }

        return $declared;
    }

    /**
     * `stage` accepts a single name or a list — `["install", "upgrade"]` is
     * how a migration says "whenever the code changed, never on a restart".
     *
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private static function readStages(array $raw, string $where): array
    {
        $declared = $raw['stage'] ?? $raw['stages'] ?? null;
        if (is_string($declared)) {
            $declared = [$declared];
        }
        if (! is_array($declared) || $declared === []) {
            throw new ManifestException("{$where}: 'stage' must be a stage name or a non-empty list of them");
        }

        $stages = [];
        foreach ($declared as $stage) {
            if (! is_string($stage) || ! PlatformStage::isValid($stage)) {
                $valid = implode(', ', PlatformStage::ALL);
                throw new ManifestException("{$where}: unknown stage '".(is_string($stage) ? $stage : gettype($stage))."'; expected one of {$valid}");
            }
            if (! in_array($stage, $stages, true)) {
                $stages[] = $stage;
            }
        }

        return $stages;
    }

    /**
     * A readable id for a command that did not declare one, so deploy logs
     * and failure reports can name the step that broke.
     */
    private static function deriveId(string $run, int $index): string
    {
        $words = preg_split('/\s+/', trim($run)) ?: [];
        $parts = [];
        foreach ($words as $word) {
            if (str_starts_with($word, '-') || str_contains($word, '=') || str_contains($word, '/')) {
                continue;
            }
            $parts[] = preg_replace('/[^a-z0-9]+/i', '-', $word) ?? '';
            if (count($parts) === 3) {
                break;
            }
        }
        $slug = strtolower(trim(implode('-', array_filter($parts)), '-'));

        return $slug !== '' ? $slug : 'step-'.($index + 1);
    }

    public function runsIn(string $stage): bool
    {
        return in_array($stage, $this->stages, true);
    }

    /** `optional: true` has to survive being joined into one shell line. */
    public function tolerantRun(string $run): string
    {
        return $this->optional ? '{ '.$run.'; } || true' : $run;
    }
}
