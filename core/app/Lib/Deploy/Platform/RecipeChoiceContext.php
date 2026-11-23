<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\Runtime\JsPackageManager;

/**
 * The recipe the deploy under way uses instead of detection: the one its request named, or the next one
 * {@see RecipeFallback} tries after a failed start, and the JS package manager that fallback tries with it.
 * A request-lifetime singleton like {@see DeployPlanContext}.
 * No Laravel dependencies of its own: callers resolve it from the container.
 */
final class RecipeChoiceContext
{
    private ?string $recipe = null;

    private ?string $fallback = null;

    private ?string $packageManager = null;

    /** Never persisted: a deploy that sends no recipe gets detection back. Starts every deploy, so it forgets a fallback too. */
    public function set(?string $recipe): void
    {
        $recipe = is_string($recipe) ? trim($recipe) : null;
        $this->recipe = $recipe === '' ? null : $recipe;
        $this->fallback = null;
        $this->packageManager = null;
    }

    /** The engine's own choice, never the request's; null hands it back to detection. */
    public function fallTo(?string $recipe): void
    {
        $this->fallback = $recipe;
    }

    /** Null hands the choice back to detection ({@see JsPackageManager::detectPackageManager()}). */
    public function fallToPackageManager(?string $packageManager): void
    {
        $this->packageManager = $packageManager;
    }

    public function packageManager(): ?string
    {
        return $this->packageManager;
    }

    public function get(): ?string
    {
        return $this->recipe ?? $this->fallback;
    }

    public function requested(): ?string
    {
        return $this->recipe;
    }

    public function fallback(): ?string
    {
        return $this->recipe === null ? $this->fallback : null;
    }

    public function clear(): void
    {
        $this->recipe = null;
        $this->fallback = null;
        $this->packageManager = null;
    }
}
