<?php

namespace App\Http\Requests;

use App\Lib\Deploy\Platform\PlatformSelector;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The `recipe` field, turned into the choice the deploy will follow.
 *
 * The companion to {@see DeployPlanInput}, one question earlier: a plan says
 * what this deploy runs, a recipe says which of the engine's recipes it runs
 * *from*. Every endpoint that starts a deploy accepts both, in the same shape
 * and with the same words for a rejection.
 *
 * Only the shape is checked here. Whether the engine has a recipe by that name
 * is settled by {@see PlatformSelector}, from the same
 * registry that would answer the question during a deploy — a validation rule
 * listing the ids would be a second copy of that list, to fall behind the first
 * the next time somebody adds a manifest.
 */
final class RecipeChoiceInput
{
    public const FIELD = 'recipe';

    /** A recipe id: the `id` a manifest declares. */
    private const PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * @throws ValidationException when the field is not shaped like a recipe id
     */
    public static function parse(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (! is_string($raw)) {
            throw ValidationException::withMessages([
                self::FIELD => self::FIELD.' must be a recipe id, as reported by inspect.',
            ]);
        }

        $recipe = trim($raw);
        if ($recipe === '') {
            return null;
        }
        if (preg_match(self::PATTERN, $recipe) !== 1) {
            throw ValidationException::withMessages([
                self::FIELD => "'{$recipe}' is not a recipe id. Inspect the source and pick one of "
                    .'the ids under application.candidates.',
            ]);
        }

        return $recipe;
    }

    /**
     * Read the field off a request and arm the deploy with it, returning the
     * id for a caller that wants to log or echo it.
     *
     * @throws ValidationException
     */
    public static function arm(Request $request): ?string
    {
        $recipe = self::parse($request->input(self::FIELD));
        app(RecipeChoiceContext::class)->set($recipe);

        return $recipe;
    }
}
