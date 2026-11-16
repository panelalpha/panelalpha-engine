<?php

namespace App\Http\Middleware;

use App\Http\Responses\Refusal;
use Closure;
use Illuminate\Http\Request;

/**
 * Answers 400 for a JSON body that does not parse. Laravel reads such a body as
 * one that sent nothing, so a create would go on with every field generated.
 */
class RefuseMalformedJson
{
    private const CODE = 'invalid_json';

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->isJson()) {
            return $next($request);
        }

        $body = trim((string) $request->getContent());
        if ($body === '' || json_validate($body)) {
            return $next($request);
        }

        return Refusal::json(400, 'body', self::CODE, 'The request body is not valid JSON: '.json_last_error_msg().'.', null);
    }
}
