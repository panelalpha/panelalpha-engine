<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * The JDKs a failed Java build says it would build on, read from its own output: javac refusing a release
 * newer than itself or one it no longer supports, a Maven enforcer's Java range, or a compiler plugin (an old
 * Lombok) that cannot load on a JDK newer than the one the project declares.
 */
final class JdkRefusal
{
    private const NEWER_RELEASE = '/release version (\d+) not supported|invalid target release: (\d+)/i';

    private const DROPPED_RELEASE = '/(?:Source|Target) option (?:1\.)?(\d+) is no longer supported/i';

    private const ENFORCED_RANGE = '/Detected JDK \S+ is version \S+ which is not in the allowed range ([\[(])\s*([\d._]*)\s*,\s*([\d._]*)\s*([\])])/';

    private const ENFORCED_MINIMUM = '/Detected JDK \S+ is version \S+ which is not in the allowed range ([\d._]+)\b/';

    private const ENFORCER_FAILED = '/RequireJavaVersion failed/';

    private const JAVAC_INTERNALS = '/Fatal error compiling: java\.lang\.(?:ExceptionInInitializerError|NoSuchFieldError|IllegalAccessError|NoClassDefFoundError)\b[^\n]*(?:com\.sun\.tools\.javac|lombok)/';

    /**
     * The JDK range to build on instead, or null when the failure says nothing about the JDK.
     *
     * @return array{min: int, max: int, newest: bool}|null `max` excluded; `newest` prefers the highest inside
     */
    public static function wanted(string $failure, int $current, ?int $declared): ?array
    {
        return self::newerRelease($failure)
            ?? self::droppedRelease($failure, $current)
            ?? self::enforcedRange($failure)
            ?? self::enforcedMinimum($failure)
            ?? self::declaredAfterRefusal($failure, $current, $declared);
    }

    /** @return array{min: int, max: int, newest: bool}|null */
    private static function newerRelease(string $failure): ?array
    {
        if (preg_match(self::NEWER_RELEASE, $failure, $m) !== 1) {
            return null;
        }

        return self::range((int) ($m[1] !== '' ? $m[1] : $m[2]), PHP_INT_MAX);
    }

    /**
     * javac 21's `Source option 7 is no longer supported`: the oldest JDK at that release or above, older than this one.
     *
     * @return array{min: int, max: int, newest: bool}|null
     */
    private static function droppedRelease(string $failure, int $current): ?array
    {
        if (preg_match(self::DROPPED_RELEASE, $failure, $m) !== 1) {
            return null;
        }

        return self::range((int) $m[1], $current);
    }

    /** `[17,18)`, `[25,)`, `(11,21]`: Maven's version range, a blank bound open. */
    private static function enforcedRange(string $failure): ?array
    {
        if (preg_match(self::ENFORCED_RANGE, $failure, $m) !== 1) {
            return null;
        }
        $low = self::release($m[2]);
        $high = self::release($m[3]);
        $min = $low === null ? 0 : $low + ($m[1] === '(' ? 1 : 0);
        $max = $high === null ? PHP_INT_MAX : $high + ($m[4] === ']' ? 1 : 0);

        return $min < $max ? self::range($min, $max) : null;
    }

    /** A bare `17` in an enforcer rule is a minimum. */
    private static function enforcedMinimum(string $failure): ?array
    {
        if (preg_match(self::ENFORCED_MINIMUM, $failure, $m) !== 1) {
            return null;
        }
        $release = self::release($m[1]);

        return $release === null ? null : self::range($release, PHP_INT_MAX);
    }

    /**
     * An enforcer rule worded by the project, or javac's own classes failing a plugin built for an older JDK:
     * the release the pom declares, else the newest JDK older than this one.
     *
     * @return array{min: int, max: int, newest: bool}|null
     */
    private static function declaredAfterRefusal(string $failure, int $current, ?int $declared): ?array
    {
        $internals = preg_match(self::JAVAC_INTERNALS, $failure) === 1;
        if (! $internals && preg_match(self::ENFORCER_FAILED, $failure) !== 1) {
            return null;
        }
        if ($declared !== null && $declared !== $current) {
            return self::range($declared, $declared > $current ? PHP_INT_MAX : $current);
        }

        return $internals ? ['min' => 0, 'max' => $current, 'newest' => true] : null;
    }

    /** @return array{min: int, max: int, newest: bool} */
    private static function range(int $min, int $max): array
    {
        return ['min' => $min, 'max' => $max, 'newest' => false];
    }

    /** "17", "1.8" or "21.0.2" as a Java release. */
    private static function release(string $value): ?int
    {
        return preg_match('/^(?:1\.)?(\d+)/', trim($value), $m) === 1 ? (int) $m[1] : null;
    }
}
