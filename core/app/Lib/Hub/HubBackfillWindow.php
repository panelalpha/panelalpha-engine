<?php

namespace App\Lib\Hub;

use Illuminate\Support\Carbon;

/**
 * A history window the Hub asked for. Its `from`, `to` and `step` go back on every upload exactly as the Hub wrote
 * them: it accepts uploads only for a window it handed out.
 */
final class HubBackfillWindow
{
    public const STEPS = [60, 3600];

    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $step,
    ) {}

    public static function from(mixed $window): ?self
    {
        if (! is_array($window) || ! is_string($window['from'] ?? null) || ! is_string($window['to'] ?? null)
            || ! in_array($window['step'] ?? null, self::STEPS, true)) {
            return null;
        }
        try {
            $from = Carbon::parse($window['from']);
            $to = Carbon::parse($window['to']);
        } catch (\Throwable) {
            return null;
        }

        return $to->gt($from) ? new self($window['from'], $window['to'], $window['step']) : null;
    }

    public function start(): Carbon
    {
        return Carbon::parse($this->from)->setTimezone('UTC');
    }

    public function end(): Carbon
    {
        return Carbon::parse($this->to)->setTimezone('UTC');
    }

    /** @return array{from: string, to: string, step: int} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'step' => $this->step];
    }
}
