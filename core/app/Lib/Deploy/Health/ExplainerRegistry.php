<?php

namespace App\Lib\Deploy\Health;

/**
 * Every {@see Explainer} a check file may name, found by convention.
 *
 * A class named `<Something>Explainer` under `Explainers/` is one: a
 * hand-written array of `new` expressions is one edit away from a check file
 * naming an explainer the engine cannot find.
 */
final class ExplainerRegistry
{
    /** @var array<string, Explainer>|null */
    private static ?array $explainers = null;

    /**
     * @return array<string, Explainer> keyed by id
     */
    public static function all(): array
    {
        if (self::$explainers !== null) {
            return self::$explainers;
        }

        $explainers = [];
        $directory = __DIR__.'/Explainers';
        foreach (scandir($directory) ?: [] as $entry) {
            if (! str_ends_with($entry, 'Explainer.php')) {
                continue;
            }
            $class = __NAMESPACE__.'\\Explainers\\'.basename($entry, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Explainer::class)) {
                continue;
            }
            /** @var Explainer $explainer */
            $explainer = new $class;
            $explainers[$explainer->id()] = $explainer;
        }

        ksort($explainers);

        return self::$explainers = $explainers;
    }

    public static function find(string $id): ?Explainer
    {
        return self::all()[$id] ?? null;
    }

    /** Tests that install a fake explainer need this. */
    public static function flush(): void
    {
        self::$explainers = null;
    }
}
