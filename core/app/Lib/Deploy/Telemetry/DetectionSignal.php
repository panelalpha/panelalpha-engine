<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Strategies;

/**
 * The event a deploy raises at detection when no recipe claimed the project, naming the toolchains that recognised
 * it, so a deploy that then fails or is cancelled still counts the gap.
 */
final class DetectionSignal
{
    /** No platform manifest claimed the project, and the request handed it to Railpack. */
    public const RAILPACK = 'detection-railpack';

    /** No platform manifest claimed the project. */
    public const FALLBACK = 'detection-fallback';

    /**
     * The signal a detection decision deserves, or null when a recipe did its job.
     *
     * @param  array<string, mixed>  $decision  as DetectProjectStrategy::detect() returns it
     * @return array{signal: string, detail: string}|null
     */
    public static function forDecision(array $decision, string $projectDir): ?array
    {
        $strategy = is_string($decision['strategy'] ?? null) ? $decision['strategy'] : '';
        $label = is_string($decision['label'] ?? null) ? $decision['label'] : 'Unknown';

        if ($strategy === Strategies::RAILPACK) {
            return [
                'signal' => self::RAILPACK,
                'detail' => 'Built by Railpack, as the request chose'
                    .self::recognisedSuffix(self::recognisedRuntimes($projectDir)),
            ];
        }

        if ($strategy === Strategies::FALLBACK) {
            return [
                'signal' => self::FALLBACK,
                'detail' => 'No recipe matched; label: '.$label
                    .self::recognisedSuffix(self::recognisedRuntimes($projectDir)),
            ];
        }

        return null;
    }

    /**
     * @param  list<string>  $runtimes
     */
    private static function recognisedSuffix(array $runtimes): string
    {
        return $runtimes === []
            ? ''
            : '; recognised by '.implode(', ', $runtimes);
    }

    /**
     * Which toolchains read a manifest here. Wrapped: a telemetry fault may never
     * fail a deploy, so an unreadable directory costs the detail line only.
     *
     * @return list<string>
     */
    private static function recognisedRuntimes(string $projectDir): array
    {
        try {
            return RuntimeRegistry::recognisedBy(ProjectContext::at($projectDir));
        } catch (\Throwable) {
            return [];
        }
    }
}
