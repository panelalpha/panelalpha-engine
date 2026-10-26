<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;

/**
 * Every PlatformProbe a manifest may reference by name. A `<Something>Probe`
 * class in this directory is a probe; the directory is resolved relative to
 * this file.
 */
final class ProbeRegistry
{
    /** @var array<string, PlatformProbe>|null */
    private static ?array $probes = null;

    /**
     * @return array<string, PlatformProbe> keyed by id
     */
    public static function all(): array
    {
        if (self::$probes !== null) {
            return self::$probes;
        }

        $probes = [];
        foreach (scandir(__DIR__) ?: [] as $entry) {
            if (! str_ends_with($entry, 'Probe.php')) {
                continue;
            }
            $class = __NAMESPACE__.'\\'.basename($entry, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, PlatformProbe::class)) {
                continue;
            }
            /** @var PlatformProbe $probe */
            $probe = new $class;
            $probes[$probe->id()] = $probe;
        }

        ksort($probes);

        return self::$probes = $probes;
    }

    /** Tests that install a fake probe need this. */
    public static function flush(): void
    {
        self::$probes = null;
    }
}
