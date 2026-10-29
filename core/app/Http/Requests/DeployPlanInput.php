<?php

namespace App\Http\Requests;

use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformCommand;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The `stages` field, turned into the plan the deploy will follow.
 *
 * Every endpoint that starts a deploy accepts the same field, in the same
 * shape, and rejects it in the same words — a caller who learned it from one
 * endpoint has learned it everywhere. It is deliberately not a validation rule
 * on each FormRequest: what makes a command legal is the manifest grammar
 * ({@see PlatformCommand::fromArray}), and restating
 * that grammar in Laravel rules would give two definitions of the same thing,
 * to disagree with each other later.
 *
 * The plan is not stored anywhere. It belongs to the request that sent it, so
 * it goes into the request-scoped {@see DeployPlanContext} and is gone with
 * the response — deploy again without it and the platform's defaults are back.
 */
final class DeployPlanInput
{
    public const FIELD = 'stages';

    /**
     * @throws ValidationException when the payload is not a plan
     */
    public static function parse(mixed $raw): ?DeployPlan
    {
        try {
            return DeployPlan::fromArray($raw);
        } catch (ManifestException $e) {
            throw ValidationException::withMessages([self::FIELD => $e->getMessage()]);
        }
    }

    /**
     * Read the field off a request and arm the deploy with it, returning the
     * plan for a caller that wants to log or echo it.
     *
     * @throws ValidationException
     */
    public static function arm(Request $request): ?DeployPlan
    {
        $plan = self::parse($request->input(self::FIELD));
        app(DeployPlanContext::class)->set($plan);

        return $plan;
    }
}
