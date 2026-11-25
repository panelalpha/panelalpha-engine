<?php

namespace App\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;

/**
 * One recipe's start: the result it returned, or what it threw. A cancel is never an attempt's outcome.
 */
final class RecipeAttempt
{
    /** The result keys {@see DeploymentWorkflow} reads the failure from; every other key says what is serving. */
    private const OUTPUT_KEYS = ['stdout', 'stderr', 'exit_code'];

    /**
     * @param  ?array{stdout: string, stderr: string, exit_code: ?int}  $result
     */
    private function __construct(
        private readonly ?array $result,
        private readonly ?\Exception $exception,
    ) {}

    /**
     * @param  \Closure(): array{stdout: string, stderr: string, exit_code: ?int}  $start
     *
     * @throws DeployCancelledException
     */
    public static function run(\Closure $start): self
    {
        try {
            return new self($start(), null);
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (\Exception $e) {
            return new self(null, $e);
        }
    }

    public function failed(): bool
    {
        return $this->exception !== null || ($this->result['exit_code'] ?? null) !== 0;
    }

    /** What the start threw; null when it returned. */
    public function exception(): ?\Exception
    {
        return $this->exception;
    }

    /** @param  class-string<\Exception>  $class */
    public function threw(string $class): bool
    {
        return $this->exception instanceof $class;
    }

    public function output(): string
    {
        if ($this->exception !== null) {
            return $this->exception->getMessage();
        }

        return (string) (($this->result['stderr'] ?? '') ?: ($this->result['stdout'] ?? ''));
    }

    /** The explainer's rule for a failed attempt; null when it started or no rule explains it. */
    public function rule(): ?string
    {
        if (! $this->failed()) {
            return null;
        }
        $rule = DeployFailureExplainer::match($this->output())['rule'] ?? null;

        return is_string($rule) ? $rule : null;
    }

    /** The explainer's rule, or the output's last line: a build prints its error last, after the steps that worked. */
    public function reason(): string
    {
        $output = $this->output();
        $rule = DeployFailureExplainer::match($output)['rule'] ?? null;
        if (is_string($rule)) {
            return $rule;
        }
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $line = trim((string) end($lines));

        return $line === '' ? 'no output' : mb_strimwidth($line, 0, 160, '…');
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     *
     * @throws \Exception
     */
    public function result(): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result ?? ['stdout' => '', 'stderr' => '', 'exit_code' => 1];
    }

    /** @return ?array<string, mixed> what the start returned, the keys saying what serves now included */
    public function resultOrNull(): ?array
    {
        return $this->result;
    }

    /**
     * This attempt's failure, with what $last says about the version serving now, since cleanup follows that.
     *
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     *
     * @throws \Exception
     */
    public function reported(self $last): array
    {
        if ($this->exception !== null || $last->result === null || $last === $this) {
            return $this->result();
        }

        return array_merge(
            array_diff_key($last->result, array_flip(self::OUTPUT_KEYS)),
            array_intersect_key($this->result ?? [], array_flip(self::OUTPUT_KEYS))
        );
    }
}
