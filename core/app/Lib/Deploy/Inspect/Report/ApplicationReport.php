<?php

namespace App\Lib\Deploy\Inspect\Report;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformCandidates;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Strategies;
use InvalidArgumentException;

/**
 * What the engine would do with this directory: the platform it matched, the
 * toolchain that implies, the commands it would run, and whether it could deploy
 * the project at all.
 */
final class ApplicationReport
{
    private readonly string $projectDir;

    private ?string $issue;

    /**
     * @param array<string, mixed> $decision
     * @param ?string $issue a reason detection itself already rejected the
     *        project, which outranks anything found here
     */
    public function __construct(
        string $projectDir,
        private readonly ProjectContext $context,
        private readonly array $decision,
        private readonly ?string $appConfigOrigin,
        private readonly ?AppConfig $appConfig,
        ?string $issue = null,
        private readonly ?DeployPlan $plan = null,
        private readonly ?string $recipe = null
    ) {
        $this->projectDir = rtrim($projectDir, '/');
        $this->issue = $issue;
    }

    /**
     * The decision for a project whose deployment could not be worked out.
     *
     * @return array<string, mixed>
     */
    public static function undeployable(): array
    {
        return [
            'strategy' => Strategies::FALLBACK,
            'label' => 'Unknown',
            'compose_path' => null,
            'dockerfile' => null,
            'port_hint' => null,
            'runtime' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $this->issue ??= $this->deployabilityIssue();
        $composePath = $this->decision['compose_path'] ?? null;

        return [
            'strategy' => (string) $this->decision['strategy'],
            'platform' => $this->declared('platform'),
            'label' => (string) $this->decision['label'],
            'runtime' => $this->decision['runtime'] ?? null,
            'image' => $this->declared('image'),
            'package_manager' => $this->packageManager(),
            'output_directory' => $this->decision['output_directory'] ?? null,
            'dockerfile' => $this->decision['dockerfile'] ?? null,
            // Which variant of a multi-variant Dockerfile this deploy builds. Kimai selects
            // between an FPM and an Apache base with `ARG BASE`; without it the default
            // target is the FPM variant, whose 9000 is FastCGI and cannot be proxied.
            'build_args' => $this->buildArgs(),
            // Whether the deploy provisions a database. Kimai's entrypoint hard-requires
            // DATABASE_URL and blocks in a retry loop without one, so an inspection
            // reporting `services: []` said nothing about a deploy that cannot answer.
            'database' => $this->declared('database'),
            'compose_file' => is_string($composePath) ? $this->relative($composePath) : null,
            'static_index' => $this->decision['static_index'] ?? null,
            'app_config' => $this->appConfigOrigin,
            // Which recipe will deploy this is `platform` above; this is which recipes
            // could, best first, so a caller choosing one has the choice in front of it.
            'candidates' => $this->candidates(),
            'deployable' => $this->issue === null,
            'issue' => $this->issue,
            'toolchain' => $this->toolchain(),
            'commands' => [
                'install' => self::commandOrNull($this->decision['install_command'] ?? null),
                'build' => self::commandOrNull($this->decision['build_command'] ?? null),
                'start' => self::commandOrNull($this->decision['start_command'] ?? null),
            ],
            'stages' => StageSchedule::of(
                $this->context,
                $this->decision,
                $this->appConfig,
                $this->plan,
                $this->recipe
            ),
        ];
    }

    /**
     * Every recipe that could deploy this project, best first. A broken manifest is
     * an empty list rather than a 500: detection has already run, so whatever the
     * report says about the deploy is still true.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(): array
    {
        try {
            return PlatformCandidates::forContext($this->context);
        } catch (ManifestException) {
            return [];
        }
    }

    private function deployabilityIssue(): ?string
    {
        try {
            DeployabilityCheck::assert($this->decision, $this->projectDir);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        // A start command serving the "did not start" page means the runtime found nothing
        // to run; reporting that as deployable puts the refusal in the command text and
        // `true` in the field callers branch on.
        $start = $this->decision['start_command'] ?? null;
        if (is_string($start) && str_contains($start, PythonRuntime::PLACEHOLDER_DIR)) {
            return 'No start command could be worked out. A wsgi.py or asgi.py needs a server '
                . 'to run it -- declare gunicorn or uvicorn. Otherwise name the entry point in a '
                . 'panelalpha.yaml, declare one [project.scripts] entry, or add app.py, main.py '
                . 'or server.py (or a single script with an `if __name__ == "__main__":` guard).';
        }

        return null;
    }

    /**
     * The toolchain the project needs, at the versions its own files ask for.
     * `source` is what makes this worth returning: "php 8.3" is a fact nobody can
     * check, "php 8.3 (from composer.json require.php)" is one they can read.
     *
     * @return list<array<string, string>>
     */
    private function toolchain(): array
    {
        $requirements = $this->decision['requirements'] ?? null;
        if (!is_array($requirements)) {
            return [];
        }

        $described = [];
        foreach ($requirements as $requirement) {
            if ($requirement instanceof Requirement) {
                $described[] = [
                    'id' => $requirement->id,
                    'version' => $requirement->version,
                    'constraint' => $requirement->constraint,
                    'source' => $requirement->source,
                    'role' => $requirement->role,
                ];
            }
        }

        return $described;
    }

    /**
     * The Dockerfile ARG values this deploy would pass, as a map. Empty rather than
     * absent when the platform sets none, so a caller need not distinguish "no
     * args" from "field missing".
     *
     * @return array<string, string>
     */
    private function buildArgs(): array
    {
        $declared = $this->decision['build_args'] ?? null;
        if (!is_array($declared)) {
            return [];
        }

        $args = [];
        foreach ($declared as $name => $value) {
            if (is_string($name) && $name !== '' && (is_string($value) || is_int($value))) {
                $args[$name] = (string) $value;
            }
        }

        return $args;
    }

    private function packageManager(): ?string
    {
        $declared = $this->declared('package_manager');
        if ($declared !== null && $declared !== '') {
            return $declared;
        }
        if (!$this->context->hasFile('package.json')) {
            return null;
        }

        return JsPackageManager::detectPackageManager($this->context->files, $this->context->package() ?? []);
    }

    /** A field the decision set, as a string, or null when it said nothing. */
    private function declared(string $key): ?string
    {
        return isset($this->decision[$key]) ? (string) $this->decision[$key] : null;
    }

    private function relative(string $path): string
    {
        $prefix = $this->projectDir . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private static function commandOrNull(mixed $command): ?string
    {
        return is_string($command) && trim($command) !== '' ? $command : null;
    }
}
