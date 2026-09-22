<?php

namespace App\Lib\Deploy\Health\Explainers;

use App\Lib\Deploy\Health\Explainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Whether a 5xx is the application's own fault or the engine's proxy answering
 * for a container that never came up.
 *
 * A 5xx read from `/` looks like the application failing, and often is. But an
 * unbound app port makes the engine's own proxy return a 502, and that 502 is
 * indistinguishable from an application 500 by status alone. The tell is the
 * clock: the proxy answers with no upstream in about two milliseconds, and no
 * application-generated 5xx returns that fast. This is the most common
 * dockerfile-strategy failure -- the entrypoint dies and the container
 * crash-loops -- and blaming it on the code inside sends the reader to the
 * wrong log.
 */
final class ServerErrorExplainer implements Explainer
{
    /** Faster than this and no application produced it -- it is the proxy. */
    private const PROXY_SECONDS = 0.01;

    public function id(): string
    {
        return 'server-error';
    }

    public function explain(ProjectContext $context, ProbedResponse $response): array
    {
        if ($response->respondedWithin(self::PROXY_SECONDS)) {
            $ms = max(1, (int) round($response->time * 1000));

            return [
                'detail' => "The {$response->status} came back in about {$ms}ms -- too fast for the application "
                    . 'to have answered. The engine\'s proxy returned it because nothing is bound on the app '
                    . 'port: the container never started or is crash-looping.',
                'fix' => 'Read the container\'s STARTUP output with `docker logs` -- not the application log -- '
                    . 'to see why the entrypoint died; the app-restart-looping check names the service when it '
                    . 'is still looping.',
            ];
        }

        if ($response->time > 0.0) {
            return [
                'detail' => 'The application accepted the request and its own code answered ' . $response->status
                    . ', so this is a fault inside the running container.',
                'fix' => 'Read the application log.',
            ];
        }

        // No timing was measured, so either case is possible.
        return [
            'detail' => 'A ' . $response->status . ' here is either the application failing after it accepted the '
                . 'request, or the engine\'s proxy answering for an app port nothing is bound to.',
            'fix' => 'If it returned in a few milliseconds it is the proxy with no upstream -- check `docker logs` '
                . 'for a container that did not start; otherwise read the application log.',
        ];
    }
}
