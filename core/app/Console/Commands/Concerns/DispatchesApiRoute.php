<?php

namespace App\Console\Commands\Concerns;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/** For the commands that still answer by dispatching an /api route in-process. */
trait DispatchesApiRoute
{
    /**
     * Dispatch an /api route in-process as the root admin, the way api:call
     * does, but with room for uploaded files.
     *
     * @param array<string, mixed> $params
     * @param array<string, \Illuminate\Http\UploadedFile> $files
     */
    protected function dispatchApiRoute(string $method, string $uri, array $params = [], array $files = []): Response
    {
        // The default guard is 'api', so setting the user here satisfies the
        // auth:api middleware the route still runs under. rootAccount(), not
        // the first admins row: a fresh install has none until a token is
        // minted, and every command here died on a TypeError until then --
        // `installer.sh --repo` included (engine#225, #224).
        Auth::setUser(Admin::rootAccount());

        $request = Request::create("/api{$uri}", strtoupper($method), $params, [], $files);
        App::instance('request', $request);

        return Route::dispatch($request);
    }

    /** Pull the API's own message out of an error response, if it sent one. */
    protected function errorMessage(Response $response): string
    {
        $body = $response->getContent();
        if (is_string($body) && $body !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
                return sprintf('HTTP %d: %s', $response->getStatusCode(), $decoded['message']);
            }
            return sprintf('HTTP %d: %s', $response->getStatusCode(), substr($body, 0, 300));
        }

        return sprintf('HTTP %d', $response->getStatusCode());
    }
}
