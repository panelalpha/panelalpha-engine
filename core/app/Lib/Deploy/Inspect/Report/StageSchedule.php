<?php

namespace App\Lib\Deploy\Inspect\Report;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformSelector;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\StageResolver;

/**
 * Every command the matched platform declares, by the stage it runs in.
 *
 * The three-command projection in {@see ApplicationReport} is what the
 * Dockerfile generators consume; this is the whole schedule, including the
 * migrate that only runs on an upgrade and the seed that only runs once.
 * Empty for a project no manifest claimed — Railpack and the fallback have no
 * schedule to show.
 */
final class StageSchedule
{
    private const JS_PLACEHOLDER = '/^\{\{js\.([a-z]+)(?::.*)?\}\}$/s';

    /** @var array<string, string> placeholder name => decision field */
    private const PLACEHOLDER_FIELDS = [
        'install' => 'install_command',
        'build' => 'build_command',
        'start' => 'start_command',
    ];

    /**
     * @param  array<string, mixed>  $decision
     */
    public function __construct(
        private readonly ProjectContext $context,
        private readonly array $decision,
        private readonly ?AppConfig $appConfig = null,
        private readonly ?DeployPlan $plan = null,
        private readonly ?string $recipe = null
    ) {}

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, list<array<string, mixed>>>
     */
    public static function of(
        ProjectContext $context,
        array $decision,
        ?AppConfig $appConfig = null,
        ?DeployPlan $plan = null,
        ?string $recipe = null
    ): array {
        return (new self($context, $decision, $appConfig, $plan, $recipe))->build();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function build(): array
    {
        // A recipe id nothing answers to is already reported as the
        // application's `issue`; throwing again here would turn a finding into
        // a 500 on the endpoint whose whole job is to report findings.
        try {
            $hit = PlatformSelector::forContext($this->context, null, $this->recipe);
        } catch (ManifestException) {
            $hit = null;
        }
        if ($hit === null) {
            // No manifest claimed the project, so there is no schedule to
            // predict — except the stages a deploy plan spoke for, which run
            // regardless of what detection concluded.
            return $this->planOnly();
        }

        $stages = [];
        foreach (PlatformStage::ALL as $stage) {
            $commands = $this->commandsFor($hit['manifest'], $stage);
            if ($commands !== [] || StageResolver::isOverridden($stage, $this->plan)) {
                $stages[$stage] = array_map(
                    fn (PlatformCommand $c): array => $this->describe($c, $stage),
                    $commands
                );
            }
        }

        return $stages;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function planOnly(): array
    {
        $stages = [];
        foreach ($this->plan?->stages() ?? [] as $stage) {
            $stages[$stage] = array_map(
                fn (PlatformCommand $c): array => $this->describe($c, $stage),
                $this->plan?->commandsFor($stage) ?? []
            );
        }

        return $stages;
    }

    /**
     * @param  PlatformManifest  $manifest
     * @return list<PlatformCommand>
     */
    private function commandsFor($manifest, string $stage): array
    {
        // Resolved by the same code the deploy resolves it with, which is the
        // whole point: the app config's commands run alongside the manifest's, a
        // deploy plan replaces the stage outright, and the report has to say
        // so rather than describe a sequence the container will not follow.
        return StageResolver::commandsFor($stage, $manifest, $this->appConfig, $this->plan, $this->context);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(PlatformCommand $command, string $stage): array
    {
        return [
            'id' => $command->id,
            'run' => $this->resolvedRun($command),
            // Which of the three possible speakers this command came from, so
            // a reader can tell a platform default from something the request
            // asked for. `request` also means the rest of the stage is gone.
            'source' => StageResolver::sourceOf(
                $command,
                $stage,
                $this->plan,
                $this->appConfig?->commands($stage) ?? []
            ),
            'optional' => $command->optional,
            'serve' => $command->serve,
            'description' => $command->description,
        ];
    }

    /**
     * A command's text as it will actually run.
     *
     * A manifest writes `{{js.install}}` because the install line depends on
     * the project's lockfile, not on the platform. The decision already
     * carries what that resolved to, so the schedule shows the string the
     * build will run rather than the placeholder that produced it.
     */
    private function resolvedRun(PlatformCommand $command): string
    {
        $resolved = $this->decision['resolved_commands'] ?? [];
        if (is_array($resolved) && is_string($resolved[$command->id] ?? null)) {
            return $resolved[$command->id];
        }

        return $this->fromDecision($command->run) ?? $command->run;
    }

    private function fromDecision(string $run): ?string
    {
        if (preg_match(self::JS_PLACEHOLDER, trim($run), $matches) !== 1) {
            return null;
        }
        $field = self::PLACEHOLDER_FIELDS[$matches[1]] ?? null;
        $value = $field === null ? null : ($this->decision[$field] ?? null);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
