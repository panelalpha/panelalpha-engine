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
 * Every command the matched platform declares, by stage: the whole schedule behind {@see ApplicationReport}'s
 * three commands, upgrade-only migrate and run-once seed included. Empty for a project no manifest claimed.
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
        $hit = $this->matchedPlatform();
        if ($hit === null) {
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
     * A recipe id nothing answers to is already the application's `issue`, so it is not thrown again here:
     * that would turn a finding into a 500 on the endpoint whose job is to report findings.
     *
     * @return array{manifest: PlatformManifest, decision: array<string, mixed>}|null
     */
    private function matchedPlatform(): ?array
    {
        try {
            $hit = PlatformSelector::forContext($this->context, null, $this->recipe);
        } catch (ManifestException) {
            $hit = null;
        }

        return $hit;
    }

    /**
     * With no manifest there is no schedule to predict, except the stages a deploy plan spoke for,
     * which run whatever detection concluded.
     *
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
     * Resolved by the deploy's own code, so the report shows the sequence the container will follow:
     * app config commands beside the manifest's, and a deploy plan's replacing the stage outright.
     *
     * @param  PlatformManifest  $manifest
     * @return list<PlatformCommand>
     */
    private function commandsFor($manifest, string $stage): array
    {
        return StageResolver::commandsFor($stage, $manifest, $this->appConfig, $this->plan, $this->context);
    }

    /**
     * `source` says which of the three possible speakers a command came from; `request` also means
     * the rest of the stage is gone.
     *
     * @return array<string, mixed>
     */
    private function describe(PlatformCommand $command, string $stage): array
    {
        return [
            'id' => $command->id,
            'run' => $this->resolvedRun($command),
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
     * A command's text as it will actually run: a `{{js.install}}` placeholder shows what the decision
     * resolved it to for this project's lockfile.
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
