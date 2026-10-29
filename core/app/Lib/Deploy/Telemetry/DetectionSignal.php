<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Strategies;

/**
 * The event a deploy raises when no recipe claimed the project: Railpack means a
 * toolchain recognised it, fallback means nothing did. Fires at detection, so a
 * deploy that then fails or is cancelled still counts the gap.
 */
final class DetectionSignal
{
    /** No platform manifest claimed the project; Railpack was handed it. */
    public const RAILPACK = 'detection-railpack';

    /** Nothing claimed or recognised the project at all. */
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
                'detail' => 'No recipe matched; built by Railpack'
                    .self::recognisedSuffix(self::recognisedRuntimes($projectDir)),
            ];
        }

        if ($strategy === Strategies::FALLBACK) {
            return [
                'signal' => self::FALLBACK,
                'detail' => 'No recipe matched; label: '.$label,
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
