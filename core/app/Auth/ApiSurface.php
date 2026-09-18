<?php

namespace App\Auth;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * The engine's REST API, as a list of operations a token can be limited to.
 *
 * An operation is `VERB /path` as the route declares itself, taken from the
 * router and matched against the **route Laravel resolved** — not the URL the
 * caller typed, so encoding and dot segments cannot turn one into another.
 *
 * `/users` is the old spelling of `/projects` and folds into it, or a limit
 * would be bypassable under the old name. One-off project actions group with
 * the project; collections beneath one (`domains`, `files`) group on their own.
 */
class ApiSurface
{
    /** Routes that are not an API operation anyone would grant. */
    private const EXCLUDED = ['{fallbackPlaceholder}'];

    /** @var array<string, array<int, string>>|null */
    private static ?array $groups = null;

    /**
     * The operation a request has matched, or null when it has matched no
     * route of this API.
     */
    public static function keyFor(?Route $route, string $method): ?string
    {
        if ($route === null) {
            return null;
        }

        $uri = $route->uri();

        if (!str_starts_with($uri, 'api/')) {
            return null;
        }

        $path = substr($uri, 4);

        // Not an operation, so not one a token can be given. Reported as "no
        // route" to keep the placeholder's name out of what an operator reads.
        if (self::excluded($path)) {
            return null;
        }

        return strtoupper($method) . ' /' . self::canonical($path);
    }

    /**
     * Every operation, grouped by the resource it belongs to.
     *
     * @return array<string, array<int, string>>
     */
    public static function byGroup(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        $operations = [];

        foreach (Router::getRoutes() as $route) {
            $uri = $route->uri();

            if (!str_starts_with($uri, 'api/')) {
                continue;
            }

            $path = substr($uri, 4);

            if (self::excluded($path) || self::isAlias($path)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $operations[strtoupper($method) . ' /' . $path] = self::segments($path);
            }
        }

        ksort($operations);

        $groups = [];

        foreach ($operations as $key => $segments) {
            $groups[self::groupOf($segments)][] = $key;
        }

        self::$groups = self::fold($groups);
        ksort(self::$groups);

        return self::$groups;
    }

    /**
     * Every operation, flat.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::byGroup()));
    }

    /** What an operation does to the server, for the row it is ticked on. */
    public static function access(string $key): string
    {
        return str_starts_with($key, 'GET ') ? 'read' : 'write';
    }

    /** Only for tests, which register routes of their own. */
    public static function forget(): void
    {
        self::$groups = null;
    }

    /**
     * `users/...` and `projects/...` are one operation under the name the
     * tools and the docs use.
     */
    private static function canonical(string $path): string
    {
        return (string) preg_replace('#^users(/|$)#', 'projects$1', $path);
    }

    private static function isAlias(string $path): bool
    {
        return preg_match('#^users(/|$)#', $path) === 1;
    }

    private static function excluded(string $path): bool
    {
        foreach (self::EXCLUDED as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path's segments with the placeholders taken out: what the route is
     * about, rather than what it is parameterised by.
     *
     * @return array<int, string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', $path),
            fn (string $s): bool => $s !== '' && !str_starts_with($s, '{')
        ));
    }

    /** @param array<int, string> $segments */
    private static function groupOf(array $segments): string
    {
        if ($segments === []) {
            return 'root';
        }

        return $segments[0] === 'projects'
            ? ($segments[1] ?? 'projects')
            : $segments[0];
    }

    /**
     * Put the one-off project actions back where they belong.
     *
     * `projects/{username}/clone` is not a `clone` resource; it is something
     * you do to a project. A group of one that came from under `projects/` is
     * always one of those.
     *
     * @param array<string, array<int, string>> $groups
     * @return array<string, array<int, string>>
     */
    private static function fold(array $groups): array
    {
        foreach ($groups as $group => $keys) {
            if ($group === 'projects' || count($keys) > 1) {
                continue;
            }

            if (str_starts_with((string) strstr($keys[0], '/'), '/projects/')) {
                $groups['projects'][] = $keys[0];
                unset($groups[$group]);
            }
        }

        foreach ($groups as $group => $keys) {
            sort($keys);
            $groups[$group] = $keys;
        }

        return $groups;
    }
}
