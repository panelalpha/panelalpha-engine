<?php

namespace App\Lib\Deploy\Platform;

use App\Support\JobBudget;

/**
 * The commands one deploy was told to run, per stage: the request's `stages`
 * field. A stage named here replaces that stage outright, in the order given;
 * `[]` runs nothing in it. An absent stage keeps the platform's defaults, so
 * `{"upgrade": []}` and omitting `upgrade` mean opposite things. Nothing is
 * stored — a deploy that sends no plan gets the defaults.
 *
 * Two things the request cannot remove, because only the engine knows them:
 * the commands it prepends to a stage (waiting for the account's MySQL sidecar
 * before a migration), and whether this boot is `install` or `upgrade`.
 */
final class DeployPlan
{
    /** Commands one stage may carry. Past this it is a script, not a plan. */
    public const MAX_COMMANDS_PER_STAGE = 50;

    /** Characters of one command line. */
    public const MAX_RUN_LENGTH = 4096;

    /** Upper bound on a per-command timeout, in seconds: below the job budget. */
    public const MAX_TIMEOUT = JobBudget::COMMAND_SECONDS;

    /**
     * @param array<string, list<PlatformCommand>> $stages stage => commands,
     *        holding only the stages the request actually named
     */
    private function __construct(private readonly array $stages)
    {
    }

    /**
     * @param mixed $raw the `stages` field as it arrived
     * @throws ManifestException on anything this cannot turn into commands
     */
    public static function fromArray(mixed $raw): ?self
    {
        if ($raw === null || $raw === []) {
            return null;
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new ManifestException(
                "'stages' must be an object keyed by stage name, e.g. {\"upgrade\": [...]}"
            );
        }

        $stages = [];
        foreach ($raw as $stage => $commands) {
            $stage = is_string($stage) ? strtolower(trim($stage)) : '';
            if (!PlatformStage::isValid($stage)) {
                throw new ManifestException(
                    "stages: '{$stage}' is not a stage; expected one of "
                    . implode(', ', PlatformStage::ALL)
                );
            }

            $stages[$stage] = self::readStage($stage, $commands);
        }

        return $stages === [] ? null : new self($stages);
    }

    /**
     * @return list<PlatformCommand>
     * @throws ManifestException
     */
    private static function readStage(string $stage, mixed $commands): array
    {
        self::assertCommandList($stage, $commands);

        $parsed = [];
        $seen = [];
        foreach ($commands as $index => $command) {
            $where = "stages.{$stage}[{$index}]";
            $parsed[$index] = self::readCommand($stage, $index, $command, $where);
            $id = $parsed[$index]->id;
            if (isset($seen[$id])) {
                throw new ManifestException("{$where}: '{$id}' is already used by another command in this stage");
            }
            $seen[$id] = true;
        }
        self::assertAtMostOneServe($stage, $parsed);

        return array_values($parsed);
    }

    /** @throws ManifestException */
    private static function assertCommandList(string $stage, mixed $commands): void
    {
        if (!is_array($commands) || !array_is_list($commands)) {
            throw new ManifestException(
                "stages.{$stage}: must be a list of commands, or [] to run nothing in this stage"
            );
        }
        if (count($commands) > self::MAX_COMMANDS_PER_STAGE) {
            throw new ManifestException(
                "stages.{$stage}: at most " . self::MAX_COMMANDS_PER_STAGE . ' commands'
            );
        }
    }

    /**
     * One command, its stage taken from the key it was written under so it cannot
     * claim another. Parsed under the id `stages.<stage>` so its errors name the stage.
     *
     * @throws ManifestException
     */
    private static function readCommand(string $stage, int $index, mixed $command, string $where): PlatformCommand
    {
        if (!is_array($command)) {
            throw new ManifestException("{$where}: must be an object with a 'run'");
        }

        $command['stage'] = $stage;
        self::assertBounds($command, $where);

        return PlatformCommand::fromArray($command, "stages.{$stage}", $index);
    }

    /**
     * Two serve commands would race for one port. None is allowed: an emptied
     * start stage means the container runs nothing.
     *
     * @param array<int, PlatformCommand> $commands
     * @throws ManifestException
     */
    private static function assertAtMostOneServe(string $stage, array $commands): void
    {
        $serves = count(array_filter($commands, static fn (PlatformCommand $command): bool => $command->serve));
        if ($serves > 1) {
            throw new ManifestException("stages.{$stage}: only one command may be the serve command");
        }
    }

    /**
     * Bounds the manifest parser does not check, because a manifest is written
     * by us and a plan arrives over HTTP.
     *
     * @param array<string, mixed> $command
     * @throws ManifestException
     */
    private static function assertBounds(array $command, string $where): void
    {
        $run = $command['run'] ?? null;
        if (is_string($run) && strlen($run) > self::MAX_RUN_LENGTH) {
            throw new ManifestException(
                "{$where}: 'run' is longer than " . self::MAX_RUN_LENGTH . ' characters'
            );
        }

        $timeout = $command['timeout'] ?? null;
        if (is_int($timeout) && $timeout > self::MAX_TIMEOUT) {
            throw new ManifestException(
                "{$where}: 'timeout' may not exceed " . self::MAX_TIMEOUT . ' seconds'
            );
        }

        $id = $command['id'] ?? null;
        if (is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', trim($id)) !== 1) {
            throw new ManifestException(
                "{$where}: 'id' must be lowercase letters, digits and dashes, at most 64 characters"
            );
        }
    }

    /**
     * Did the request speak for this stage at all? Not
     * `commandsFor($stage) !== []`: a stage the request emptied and one it
     * never mentioned return the same list and mean opposite things.
     */
    public function definesStage(string $stage): bool
    {
        return array_key_exists($stage, $this->stages);
    }

    /**
     * @return list<PlatformCommand>
     */
    public function commandsFor(string $stage): array
    {
        return $this->stages[$stage] ?? [];
    }

    /**
     * The stages this plan speaks for, in deploy order — it is read in a log line.
     *
     * @return list<string>
     */
    public function stages(): array
    {
        return array_values(array_filter(
            PlatformStage::ALL,
            fn (string $stage): bool => $this->definesStage($stage)
        ));
    }

    /**
     * Rewrite the decision's `install_command` / `build_command` for a build
     * stage that runs as `RUN` layers, not from the entrypoint.
     *
     * `role` decides which string a command lands in — `dependencies` above the
     * source copy so BuildKit can cache it, `assets` below.
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    public function applyToDecision(array $decision): array
    {
        if (!$this->definesStage(PlatformStage::BUILD)) {
            return $decision;
        }

        $dependencies = [];
        $assets = [];
        foreach ($this->commandsFor(PlatformStage::BUILD) as $command) {
            if ($command->role === PlatformCommand::ROLE_DEPENDENCIES) {
                $dependencies[] = $command->run;
            } else {
                $assets[] = $command->run;
            }
        }

        $decision['install_command'] = implode(' && ', $dependencies);
        $decision['build_command'] = implode(' && ', $assets);

        return $decision;
    }

    /**
     * The plan as it arrived, for a log line and for echoing back.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->stages() as $stage) {
            $out[$stage] = array_map(
                static fn (PlatformCommand $c): array => array_filter([
                    'id' => $c->id,
                    'run' => $c->run,
                    'optional' => $c->optional ?: null,
                    'serve' => $c->serve ?: null,
                    'timeout' => $c->timeout,
                    'workdir' => $c->workdir,
                ], static fn (mixed $v): bool => $v !== null),
                $this->commandsFor($stage)
            );
        }

        return $out;
    }
}
