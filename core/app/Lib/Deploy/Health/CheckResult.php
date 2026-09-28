<?php

namespace App\Lib\Deploy\Health;

/**
 * One check, asked and answered.
 *
 * Passing results are kept as well as failing ones: a report saying only what
 * is wrong cannot be told apart from one where nothing was asked.
 */
final class CheckResult
{
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /** The guard on the check said it does not apply to this project. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param array<string, mixed>|null $evidence
     */
    private function __construct(
        public readonly HealthCheck $check,
        public readonly string $status,
        public readonly ?string $title,
        public readonly ?string $detail,
        public readonly ?string $fix,
        public readonly ?array $evidence
    ) {
    }

    /**
     * A check that held has nothing to say.
     *
     * Not the check's own `message`: those are written as the sentence for a
     * wrong answer -- "The application is running but failing" -- and attaching
     * one to a pass states the opposite of what happened.
     */
    public static function pass(HealthCheck $check): self
    {
        return new self($check, self::STATUS_PASS, null, null, null, null);
    }

    public static function skipped(HealthCheck $check): self
    {
        return new self($check, self::STATUS_SKIPPED, null, null, null, null);
    }

    /**
     * The manifest's `check_skip` said this check does not apply, and why.
     * Reported, not dropped: a skip nobody can see is a check nobody runs.
     */
    public static function notApplicable(HealthCheck $check, string $reason, ?string $coveredBy): self
    {
        return new self(
            $check,
            self::STATUS_SKIPPED,
            'Not applicable: the application\'s manifest skips this check.',
            $reason,
            null,
            ['declared_by' => 'check_skip', 'covered_by' => $coveredBy]
        );
    }

    /**
     * @param array<string, mixed> $evidence
     */
    public static function fail(
        HealthCheck $check,
        string $title,
        ?string $detail,
        ?string $fix,
        array $evidence
    ): self {
        return new self($check, self::STATUS_FAIL, $title, $detail, $fix, $evidence);
    }

    public function failed(): bool
    {
        return $this->status === self::STATUS_FAIL;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->check->id,
            'group' => $this->check->group,
            'status' => $this->status,
            'severity' => $this->check->severity,
            'title' => $this->title,
            'detail' => $this->detail,
            'fix' => $this->fix,
            'evidence' => $this->evidence,
        ];
    }

    /** One line for a deploy log. Only a failure has one. */
    public function describe(): string
    {
        $line = 'Check ' . $this->check->reference() . ': ' . ($this->title ?? 'passed');

        return $this->detail === null ? $line : $line . ' ' . $this->detail;
    }
}
