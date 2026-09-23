<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Symfony\Component\HttpFoundation\Response;

/**
 * In-process call of an engine API route, as the root admin, the way api:call does.
 */
trait CallsEngineApi
{
    /**
     * @param array<string, mixed>|null $json
     */
    protected function dispatchEngine(string $method, string $uri, ?array $json = null): Response
    {
        $admin = (new class extends User {
            use HasApiTokens;

            protected $table = 'admins';
        })->newInstance();
        assert($admin instanceof Authenticatable);
        Auth::setUser($admin);

        $content = $json === null ? '' : json_encode($json);
        $request = Request::create(
            '/api' . $uri,
            strtoupper($method),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $content === false ? '' : $content,
        );
        App::instance('request', $request);

        return Route::dispatch($request);
    }

    protected function rejectEngineResponse(Response $response): int
    {
        $body = $response->getContent();
        $this->error(is_string($body) && $body !== '' ? $body : 'Request failed.');

        return 1;
    }

    /**
     * @param array<string, string> $settings
     */
    protected function printDirectiveMap(array $settings): void
    {
        $encoded = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);
        $this->line($encoded === false ? '{}' : $encoded);
    }
}
