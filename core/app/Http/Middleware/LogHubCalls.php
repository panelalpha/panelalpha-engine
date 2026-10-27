<?php

namespace App\Http\Middleware;

use App\Auth\ApiSurface;
use App\Lib\Hub\HubConnectionCalls;
use App\Lib\Hub\HubTokens;
use App\Models\HubAccessLog;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every call made with a PanelAlpha Connect token, refused ones included:
 * time, route, status and source address, for `pae hub:status`. Never the
 * token, a request body or a response body.
 */
class LogHubCalls
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        $token = $user !== null && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if ($token instanceof PersonalAccessToken && HubTokens::isHubToken($token)) {
            $this->record($request, $token, $response->getStatusCode());
        }

        return $response;
    }

    /**
     * An admin login names the app user it logs in as; the URL itself is never
     * kept. A failure is swallowed: the log must never turn an answer into a 500.
     */
    private function record(Request $request, PersonalAccessToken $token, int $status): void
    {
        try {
            $app = $request->route('username');
            $appUser = $request->route('userId');

            HubAccessLog::query()->create([
                'at' => now(),
                'method' => $request->method(),
                'route' => ApiSurface::keyFor($request->route(), $request->method()) ?? ('/'.ltrim($request->path(), '/')),
                'app' => is_string($app) ? substr($app, 0, 191) : null,
                'status' => $status,
                'source_ip' => $request->ip(),
                'token_id' => $token->id,
                'detail' => is_string($appUser) ? substr('app user '.$appUser, 0, 191) : null,
            ]);

            HubConnectionCalls::note(HubTokens::connectionIdOf($token), $status, $request->ip());
        } catch (\Throwable) {
        }
    }
}
