<?php

namespace App\Lib\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\Strategies;

/**
 * What the inspection a create runs before cloning found: a verdict on the
 * repository, or why it was not inspected. It never refuses a create; it is
 * reported in the create response and at the top of the deploy log.
 */
final class CreateInspection
{
    public const DEPLOYABLE = 'deployable';

    public const NOT_DEPLOYABLE = 'not_deployable';

    public const PLACEHOLDER = 'placeholder';

    public const NO_START_COMMAND = 'no_start_command';

    private function __construct(
        public readonly ?string $verdict,
        public readonly ?string $strategy = null,
        public readonly ?string $label = null,
        public readonly ?string $reason = null,
        public readonly ?string $suggestion = null,
        public readonly ?string $skipped = null,
    ) {
    }

    /** Not inspected, and why: said once in the deploy log, nowhere else. */
    public static function skipped(string $why): self
    {
        return new self(null, skipped: $why);
    }

    /**
     * The verdict on an AppInspector report.
     *
     * @param array<string, mixed> $report
     */
    public static function fromReport(array $report): self
    {
        $application = is_array($report['application'] ?? null) ? $report['application'] : [];
        $strategy = (string) ($application['strategy'] ?? Strategies::FALLBACK);
        $label = (string) ($application['label'] ?? $strategy);
        $issue = $application['issue'] ?? null;

        if (($application['deployable'] ?? true) === false || (is_string($issue) && $issue !== '')) {
            return new self(
                self::NOT_DEPLOYABLE,
                $strategy,
                $label,
                is_string($issue) && $issue !== '' ? $issue : 'Detection could not work out how to deploy it.',
                'Run source_inspect (POST /source/inspect) on the repository to see what detection found, '
                    . 'then choose a recipe from application.candidates or add a panelalpha.yaml that says '
                    . 'how to build and start it.'
            );
        }

        if ($strategy === Strategies::FALLBACK) {
            return new self(
                self::PLACEHOLDER,
                $strategy,
                $label,
                'No recipe and no runtime recognises it, so the deploy would serve the '
                    . '"Project not configured" placeholder page instead of an application.',
                'Add a Dockerfile, a compose file or a panelalpha.yaml that says how to build and start it.'
            );
        }

        if ($strategy === Strategies::RAILPACK && self::nodePackageWithoutStart($report)) {
            return new self(
                self::NO_START_COMMAND,
                $strategy,
                $label,
                'package.json has no start script, so nothing says how to run it as a server; '
                    . 'a package without one is usually a library, which builds but serves nothing.',
                'Add a start script that runs the server to package.json, or a panelalpha.yaml that '
                    . 'names the start command.'
            );
        }

        return new self(self::DEPLOYABLE, $strategy, $label);
    }

    /**
     * Railpack starts a Node project with its start script; a root package.json
     * without one is the shape of a library (lodash, express).
     *
     * @param array<string, mixed> $report
     */
    private static function nodePackageWithoutStart(array $report): bool
    {
        $packages = $report['metadata']['packages'] ?? null;
        if (!is_array($packages)) {
            return false;
        }
        foreach ($packages as $package) {
            if (is_array($package)
                && ($package['ecosystem'] ?? null) === 'node'
                && ($package['file'] ?? null) === 'package.json'
            ) {
                $scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];

                return !in_array('start', $scripts, true);
            }
        }

        return false;
    }

    public function warns(): bool
    {
        return $this->verdict !== null && $this->verdict !== self::DEPLOYABLE;
    }

    /**
     * The `inspection` field of a create response: the strategy alone when it
     * deploys, the verdict with its reason and suggestion when it may not,
     * null when nothing was inspected.
     *
     * @return ?array<string, string|null>
     */
    public function toResponse(): ?array
    {
        if ($this->verdict === null) {
            return null;
        }
        if (!$this->warns()) {
            return ['verdict' => $this->verdict, 'strategy' => $this->strategy];
        }

        return [
            'verdict' => $this->verdict,
            'strategy' => $this->strategy,
            'reason' => $this->reason,
            'suggestion' => $this->suggestion,
        ];
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
        if (!$this->warns()) {
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
        ];
    }

    /**
     * @param ?array<string, mixed> $data
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
        );
    }
}
