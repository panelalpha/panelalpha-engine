<?php

namespace App\Lib\Deploy\Platform;

/**
 * The recipe the deploy under way was told to use, a request-lifetime singleton like {@see DeployPlanContext}.
 * No Laravel dependencies of its own: callers resolve it from the container.
 */
final class RecipeChoiceContext
{
    private ?string $recipe = null;

    /** Never persisted: a deploy that sends no recipe gets detection back. */
    public function set(?string $recipe): void
    {
        $recipe = is_string($recipe) ? trim($recipe) : null;
        $this->recipe = $recipe === '' ? null : $recipe;
    }

    public function get(): ?string
    {
        return $this->recipe;
    }

    public function clear(): void
    {
        $this->recipe = null;
    }
}
