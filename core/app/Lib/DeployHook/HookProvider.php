<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * One git host's way of telling the engine about a push.
 *
 * The three methods are the three questions a delivery has to survive, in the
 * order they are asked: is this from you, is it really from you, and what does
 * it say. They take the request rather than pieces of it because what a
 * provider looks at differs -- GitHub signs the body, GitLab sends the secret
 * in a header -- and the caller has no business knowing which.
 */
interface HookProvider
{
    /** The name recorded on a delivery (`github`). */
    public function name(): string;

    /** Whether the request carries this provider's headers, whatever else it carries. */
    public function recognises(Request $request): bool;

    /** Whether the request proves it was sent by someone holding `$secret`. */
    public function verify(Request $request, string $secret): bool;

    /**
     * @throws InvalidPayload when the body is not what this provider sends
     */
    public function parse(Request $request): HookEvent;
}
