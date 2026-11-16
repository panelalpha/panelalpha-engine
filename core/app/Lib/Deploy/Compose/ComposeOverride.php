<?php

namespace App\Lib\Deploy\Compose;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * A compose file layered over the engine's run file with `-f`, with only its escapes removed: limits and
 * defaults are the base file's to set. A file with nothing to remove is returned byte for byte.
 */
final class ComposeOverride
{
    /**
     * A top-level volume redefined here applies to the base file's services too.
     *
     * @param  (callable(string): ?string)|null  $read  project-relative path => contents, for
     *                                                  the files it `include:`s; without one an include is refused
     * @param  array<string, list<?string>|string>  $env  what compose may interpolate the file with
     * @param  ?string  $accountUser  whose ~/.panelalpha a service may bind by its absolute path
     * @return array{yaml: ?string, removed: list<string>} yaml is null when
     *                                                     the file cannot be read, and so cannot be checked
     */
    public static function harden(string $raw, ?callable $read = null, array $env = [], ?string $accountUser = null, ?string $projectDir = null): array
    {
        $parsed = self::parse($raw);
        if ($parsed === null) {
            return ['yaml' => null, 'removed' => []];
        }
        [$parsed, $sources] = self::withIncludesMerged($parsed, $read);
        [$parsed, $removed] = ServiceHardener::withoutVolumeDriverOptions($parsed);
        [$parsed, $removed] = self::withServicesHardened($parsed, $removed, $env, $accountUser, $projectDir);
        [$parsed, $files] = ServiceHardener::withoutUnsafeFileSources($parsed, $env, $accountUser, $projectDir);
        $removed = [...$removed, ...$files];

        if ($sources !== null) {
            return ['yaml' => ComposeYaml::dump($parsed, $raw, 6, 2, ...$sources), 'removed' => $removed];
        }

        return $removed === []
            ? ['yaml' => $raw, 'removed' => []]
            : ['yaml' => Yaml::dump($parsed, 6, 2), 'removed' => $removed];
    }

    /**
     * Compose layers an included file's services as written; merged in here, they get the override's checks.
     *
     * @param  array<string, mixed>  $parsed
     * @param  (callable(string): ?string)|null  $read
     * @return array{0: array<string, mixed>, 1: list<string>|null}
     */
    private static function withIncludesMerged(array $parsed, ?callable $read): array
    {
        $sources = null;
        if (array_key_exists('include', $parsed)) {
            if ($read === null) {
                throw new \InvalidArgumentException(
                    'A compose override cannot use include: here; list the services in the override itself.'
                );
            }
            ['compose' => $parsed, 'sources' => $sources] = ComposeInclude::flatten($parsed, $read);
        }

        return [$parsed, $sources];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $removed
     * @param  array<string, list<?string>|string>  $env
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function withServicesHardened(array $parsed, array $removed, array $env, ?string $accountUser, ?string $projectDir): array
    {
        foreach (is_array($parsed['services'] ?? null) ? $parsed['services'] : [] as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            [$clean, $dropped] = self::withoutEscapes($service, $env, $accountUser, $projectDir);
            foreach ($dropped as $what) {
                $removed[] = $name.': '.$what;
            }
            $parsed['services'][$name] = $clean;
        }

        return [$parsed, $removed];
    }

    /**
     * The override without its entries for services the base file lacks, which compose would bring
     * back as fragments with no image. A file naming none is returned as is.
     *
     * @param  list<string>  $names
     * @return array{yaml: string, dropped: list<string>}
     */
    public static function withoutServices(string $raw, array $names): array
    {
        $parsed = $names === [] ? null : self::parse($raw);
        $dropped = [];
        foreach (is_array($parsed['services'] ?? null) ? $names : [] as $name) {
            if (array_key_exists($name, $parsed['services'])) {
                unset($parsed['services'][$name]);
                $dropped[] = $name;
            }
        }

        return ['yaml' => $dropped === [] ? $raw : Yaml::dump($parsed, 6, 2), 'dropped' => $dropped];
    }

    /**
     * The override without the services nothing under it defines and it gives no image or build, which
     * compose refuses the whole project for. An empty map dumps as `{  }`, which compose expects.
     *
     * @param  list<string>  $defined  services the files layered under it define
     * @return array{yaml: ?string, dropped: list<string>} yaml is null when the
     *                                                     file cannot be read; unchanged, byte for byte, when nothing is dropped
     */
    public static function withoutUndefinedServices(string $raw, array $defined): array
    {
        $parsed = self::parse($raw);
        if ($parsed === null) {
            return ['yaml' => null, 'dropped' => []];
        }
        $block = $parsed['services'] ?? null;
        $tag = $block instanceof TaggedValue ? $block->getTag() : null;
        $services = $block instanceof TaggedValue ? $block->getValue() : $block;
        if (! is_array($services)) {
            return ['yaml' => $raw, 'dropped' => []];
        }
        $dropped = [];
        foreach ($services as $name => $service) {
            $service = $service instanceof TaggedValue ? $service->getValue() : $service;
            $own = is_array($service) && (isset($service['image']) || isset($service['build']) || isset($service['extends']));
            if (! $own && ! in_array((string) $name, $defined, true)) {
                unset($services[$name]);
                $dropped[] = (string) $name;
            }
        }
        if ($dropped === []) {
            return ['yaml' => $raw, 'dropped' => []];
        }
        $parsed['services'] = $tag === null ? $services : new TaggedValue($tag, $services);

        return ['yaml' => Yaml::dump($parsed, 6, 2), 'dropped' => $dropped];
    }

    /**
     * Compose's `!reset` and `!override` tags are read, not rejected, so a tag is no way around the check.
     *
     * @return array<string, mixed>|null
     */
    private static function parse(string $raw): ?array
    {
        try {
            $parsed = Yaml::parse($raw, Yaml::PARSE_CUSTOM_TAGS);

            return is_array($parsed) ? $parsed : null;
        } catch (ParseException) {
            return ComposeYaml::parse($raw);
        }
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, list<?string>|string>  $env
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function withoutEscapes(array $service, array $env, ?string $accountUser, ?string $projectDir): array
    {
        [$tag, $plain] = self::untagged($service);

        $clean = ServiceHardener::withoutEscapes($plain, $env, $accountUser, $projectDir);

        $dropped = self::droppedFrom($plain, $clean);
        if ($dropped === []) {
            return [$service, []];
        }

        return [self::retagged($clean, $tag), $dropped];
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array{0: array<string, string>|null, 1: array<string, mixed>}
     */
    private static function untagged(array $service): array
    {
        $tag = null;
        $plain = [];
        foreach ($service as $key => $value) {
            if ($value instanceof TaggedValue) {
                $tag[$key] = $value->getTag();
                $value = $value->getValue();
            }
            $plain[$key] = $value;
        }

        return [$tag, $plain];
    }

    /**
     * @param  array<string, mixed>  $plain
     * @param  array<string, mixed>  $clean
     * @return list<string>
     */
    private static function droppedFrom(array $plain, array $clean): array
    {
        $dropped = [];
        foreach (array_keys($plain) as $key) {
            if (! array_key_exists($key, $clean)) {
                $dropped[] = $key === 'volumes' ? 'every volume (host paths)' : (string) $key;
            }
        }
        if (is_array($plain['volumes'] ?? null) && is_array($clean['volumes'] ?? null)) {
            foreach ($plain['volumes'] as $volume) {
                if (! in_array($volume, $clean['volumes'], true)) {
                    $dropped[] = 'volume '.(is_string($volume) ? $volume : json_encode($volume));
                }
            }
        }

        return $dropped;
    }

    /**
     * @param  array<string, mixed>  $clean
     * @param  array<string, string>|null  $tag
     * @return array<string, mixed>
     */
    private static function retagged(array $clean, ?array $tag): array
    {
        foreach ($clean as $key => $value) {
            if (isset($tag[$key])) {
                $clean[$key] = new TaggedValue($tag[$key], $value);
            }
        }

        return $clean;
    }
}
