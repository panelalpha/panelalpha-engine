<?php

namespace App\Lib\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Inspect\Report\DeployVerdict;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Findings\Finding;

/**
 * What the inspection a create runs before cloning found: a verdict, or why it was not inspected.
 * It never refuses a create; it is reported in the create response and the deploy log.
 */
final class CreateInspection
{
    public const DEPLOYABLE = DeployVerdict::DEPLOYABLE;

    public const NOT_DEPLOYABLE = DeployVerdict::NOT_DEPLOYABLE;

    public const PLACEHOLDER = DeployVerdict::PLACEHOLDER;

    public const NO_START_COMMAND = DeployVerdict::NO_START_COMMAND;

    public const REPOSITORY_NOT_DEPLOYABLE = 'repository_not_deployable';

    private const START_SCRIPT_SUGGESTION = 'Add a start script that runs the server to package.json, or a '
        .'panelalpha.yaml that names the start command.';

    private function __construct(
        public readonly ?string $verdict,
        public readonly ?string $strategy = null,
        public readonly ?string $label = null,
        public readonly ?string $reason = null,
        public readonly ?string $suggestion = null,
        public readonly ?string $skipped = null,
        public readonly ?string $code = null,
    ) {}

    /** Not inspected, and why: said once in the deploy log, nowhere else. */
    public static function skipped(string $why): self
    {
        return new self(null, skipped: $why);
    }

    /**
     * The verdict an AppInspector report carries, with what to do about it.
     *
     * @param  array<string, mixed>  $report
     */
    public static function fromReport(array $report): self
    {
        $application = is_array($report['application'] ?? null) ? $report['application'] : [];
        $strategy = (string) ($application['strategy'] ?? Strategies::FALLBACK);
        $label = (string) ($application['label'] ?? $strategy);
        $issue = is_array($application['issue'] ?? null) ? Finding::fromStored($application['issue']) : null;

        return match ($application['verdict'] ?? null) {
            self::NOT_DEPLOYABLE => self::notDeployable($strategy, $label, $issue),
            self::PLACEHOLDER => self::placeholder($strategy, $label, $issue),
            self::NO_START_COMMAND => self::noStartCommand($strategy, $label),
            default => new self(self::DEPLOYABLE, $strategy, $label),
        };
    }

    private static function notDeployable(string $strategy, string $label, ?Finding $issue): self
    {
        return new self(
            self::NOT_DEPLOYABLE,
            $strategy,
            $label,
            $issue->message ?? 'Detection could not work out how to deploy it.',
            'Inspect the repository to see what detection found, then deploy it with a recipe from '
                .'application.candidates or add a panelalpha.yaml that says how to build and start it.',
            code: $issue->code ?? self::REPOSITORY_NOT_DEPLOYABLE,
        );
    }

    private static function placeholder(string $strategy, string $label, ?Finding $issue): self
    {
        return new self(
            self::PLACEHOLDER,
            $strategy,
            $label,
            $issue->message ?? DeployVerdict::UNRECOGNISED,
            $strategy === Strategies::RAILPACK
                ? self::START_SCRIPT_SUGGESTION
                : 'Add a Dockerfile, a compose file or a panelalpha.yaml that says how to build and start it.',
            code: $issue->code ?? DeployVerdict::CODE_UNRECOGNISED,
        );
    }

    private static function noStartCommand(string $strategy, string $label): self
    {
        return new self(
            self::NO_START_COMMAND, $strategy, $label, DeployVerdict::NO_START_SCRIPT, self::START_SCRIPT_SUGGESTION,
            code: DeployVerdict::CODE_NO_START_SCRIPT,
        );
    }

    public function warns(): bool
    {
        return $this->verdict !== null && $this->verdict !== self::DEPLOYABLE;
    }

    /**
     * The `inspection` field of a create response: the strategy when it deploys, reason and suggestion when not.
     *
     * @return ?array<string, string|null>
     */
    public function toResponse(): ?array
    {
        if ($this->verdict === null) {
            return null;
        }
        if (! $this->warns()) {
            return ['verdict' => $this->verdict, 'strategy' => $this->strategy];
        }

        return [
            'verdict' => $this->verdict,
            'strategy' => $this->strategy,
            'code' => $this->code,
            'reason' => $this->reason,
            'suggestion' => $this->suggestion,
        ];
    }

    /** What may stop it deploying, as a finding; null when nothing may. */
    public function finding(): ?Finding
    {
        return $this->warns() && $this->code !== null
            ? new Finding($this->code, trim("{$this->reason} {$this->suggestion}"), ['strategy' => $this->strategy])
            : null;
    }

    /** The one line the CLI prints when the repository may not deploy. */
    public function warning(): ?string
    {
        return $this->warns()
            ? "The repository may not deploy as it stands: {$this->reason} {$this->suggestion}"
            : null;
    }

    public function writeTo(DeployLogger $logger): void
    {
        if ($this->verdict === null) {
            $logger->info("Repository not inspected before the clone: {$this->skipped}");

            return;
        }
        if (! $this->warns()) {
            $logger->info("Repository inspected from its file list: deploys as {$this->label} ({$this->strategy}).");

            return;
        }

        $logger->warn("Repository inspected from its file list, and it may not deploy: {$this->reason}");
        $logger->warn("Suggestion: {$this->suggestion}");
        $logger->warn('The deploy goes ahead regardless.');
    }

    /**
     * For the queued deploy, which writes the log in another process.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'strategy' => $this->strategy,
            'label' => $this->label,
            'reason' => $this->reason,
            'suggestion' => $this->suggestion,
            'skipped' => $this->skipped,
            'code' => $this->code,
        ];
    }

    /**
     * @param  ?array<string, mixed>  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }
        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            $string('verdict'),
            $string('strategy'),
            $string('label'),
            $string('reason'),
            $string('suggestion'),
            $string('skipped') ?? ($string('verdict') === null ? 'no verdict was recorded.' : null),
            $string('code'),
        );
    }
}
