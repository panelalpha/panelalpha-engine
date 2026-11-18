<?php

namespace App\Lib\Deploy\Compose;

/**
 * Docker Hub namespaces whose tags were moved elsewhere: Bitnami took its versioned tags off `bitnami/` and keeps
 * them, frozen, under `bitnamilegacy/`. A service on such a tag runs it from where it went, when it is gone from
 * where it was and the new place has it.
 */
final class MovedImages
{
    /** @var array<string, string> namespace => where its tags went */
    private const MOVED = ['bitnami' => 'bitnamilegacy'];

    /**
     * @param  array<mixed>  $services
     * @param  array<string, list<?string>|string>  $env
     * @param  callable(string): ?bool  $published  whether a registry has the image, null when that cannot be told
     * @return array{services: array<mixed>, moved: list<array{service: string, from: string, to: string}>}
     */
    public static function apply(array $services, array $env, callable $published): array
    {
        $moved = [];
        foreach ($services as $name => $service) {
            if (! is_array($service) || isset($service['build'])) {
                continue;
            }
            $image = DeployCompose::resolvedImageRef($service['image'] ?? null, $env);
            $target = $image === null ? null : self::movedTo($image);
            if ($target !== null && $published($image) === false && $published($target) === true) {
                $services[$name]['image'] = $target;
                $moved[] = ['service' => (string) $name, 'from' => $image, 'to' => $target];
            }
        }

        return ['services' => $services, 'moved' => $moved];
    }

    /**
     * The log line for one service moved.
     *
     * @param  array{service: string, from: string, to: string}  $moved
     */
    public static function line(array $moved): string
    {
        return "Service {$moved['service']} runs {$moved['from']}, which Docker Hub no longer has; "
            ."its publisher moved that tag, so it runs {$moved['to']}, which is no longer updated";
    }

    /** Where $image's tag went, for an image in a moved Docker Hub namespace; null for any other. */
    public static function movedTo(string $image): ?string
    {
        if (preg_match('#^(?:docker\.io/)?([a-z0-9_-]+)/([a-z0-9._-]+(?::[A-Za-z0-9._-]+))$#', $image, $m) !== 1) {
            return null;
        }
        $namespace = self::MOVED[$m[1]] ?? null;

        return $namespace === null ? null : $namespace.'/'.$m[2];
    }
}
