<?php

namespace App\OpenApi;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use OpenApi\Annotations\Operation;
use OpenApi\Undefined;
use ReflectionAttribute;
use ReflectionMethod;

/**
 * The summary each documented REST operation carries in its `#[OA\...]` attribute, keyed `VERB /path` as this API
 * names it. Read from the controller methods the API routes dispatch to, so it needs no generated document; an
 * operation nothing documents has none.
 */
class OperationSummaries
{
    /** @var array<string, string>|null */
    private static ?array $summaries = null;

    public static function of(string $key): ?string
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$summaries !== null) {
            return self::$summaries;
        }

        $summaries = [];

        foreach (self::documentedMethods() as $method) {
            foreach ($method->getAttributes(Operation::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $operation = $attribute->newInstance();

                if (self::defined($operation->method, $operation->path, $operation->summary)) {
                    $summaries[strtoupper($operation->method).' '.$operation->path] = trim($operation->summary);
                }
            }
        }

        ksort($summaries);

        return self::$summaries = $summaries;
    }

    /** Only for tests, which register routes of their own. */
    public static function forget(): void
    {
        self::$summaries = null;
    }

    /** @return array<string, ReflectionMethod> */
    private static function documentedMethods(): array
    {
        $methods = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            $action = self::controllerAction($route);

            if ($action !== null) {
                $methods[$action[0].'@'.$action[1]] ??= new ReflectionMethod($action[0], $action[1]);
            }
        }

        return $methods;
    }

    /** @return array{0: class-string, 1: string}|null */
    private static function controllerAction(Route $route): ?array
    {
        if (! str_starts_with($route->uri(), 'api/')) {
            return null;
        }

        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action, 2);

        return method_exists($class, $method) ? [$class, $method] : null;
    }

    private static function defined(mixed ...$values): bool
    {
        foreach ($values as $value) {
            if (! is_string($value) || $value === Undefined::UNDEFINED || trim($value) === '') {
                return false;
            }
        }

        return true;
    }
}
