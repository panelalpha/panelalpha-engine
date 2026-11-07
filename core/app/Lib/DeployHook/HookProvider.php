<?php

namespace App\Lib\DeployHook;

use Illuminate\Http\Request;

/**
 * One git host's way of telling the engine about a push: is this from you, is it really from you, what
 * does it say, asked in that order of the whole request, since providers look at different parts of it.
 */
interface HookProvider
{
    /** The name recorded on a delivery (`github`). */
    public function name(): string;

    /** Whether the request carries this provider's headers, whatever else it carries. */
    public function recognises(Request $request): bool;

    /** Whether the request proves it was sent by someone holding `$secret`. */
    public function verify(Request $request, string $secret): bool;

    /** Whether verify() vouches for this request's body itself (an HMAC) under `$secret`, not only for who sent it. */
    public function signsTheBody(Request $request, string $secret): bool;

    /**
     * @throws InvalidPayload when the body is not what this provider sends
     */
    public function parse(Request $request): HookEvent;
}
