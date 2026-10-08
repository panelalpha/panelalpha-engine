<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Dockerfile\BuildRecipe;
use PHPUnit\Framework\TestCase;

/**
 * A deploy decision read as the answers a Dockerfile generator needs.
 *
 * The decision crosses a process boundary as JSON, so nothing in it is
 * trustworthy by type, and each writer would otherwise repeat the same
 * defaulting. Every accessor here has a fallback because a missing value
 * means "the recipe did not say", not "the recipe said nothing".
 */
class BuildRecipeTest extends TestCase
{
    public function test_the_decisions_values_are_read(): void
    {
        $recipe = new BuildRecipe([
            'runtime' => 'node',
            'image' => 'node:22-bookworm-slim',
            'install_command' => 'pnpm install --frozen-lockfile',
            'build_command' => 'pnpm build',
            'output_directory' => 'dist',
            'package_manager' => 'pnpm',
        ]);

        $this->assertSame('node', $recipe->runtime);
        $this->assertSame('node:22-bookworm-slim', $recipe->image);
        $this->assertSame('pnpm install --frozen-lockfile', $recipe->installCommand);
        $this->assertSame('pnpm build', $recipe->buildCommand);
        $this->assertSame('dist', $recipe->outputDirectory);
        $this->assertSame('pnpm', $recipe->packageManager);
    }

    public function test_values_are_trimmed(): void
    {
        $recipe = new BuildRecipe(['image' => '  node:20  ']);

        $this->assertSame('node:20', $recipe->image);
    }

    public function test_a_decision_that_says_nothing_still_names_a_runtime(): void
    {
        // Every generator branches on it, so it cannot be empty.
        $this->assertSame('node', (new BuildRecipe([]))->runtime);
    }

    public function test_a_non_string_value_is_read_as_absent(): void
    {
        $recipe = new BuildRecipe(['image' => 20, 'install_command' => ['npm', 'ci']]);

        $this->assertSame('', $recipe->image);
        $this->assertSame('npm ci', $recipe->installCommandOr('npm ci'));
    }

    public function test_a_recipes_own_command_beats_the_generators_default(): void
    {
        $recipe = new BuildRecipe(['install_command' => 'yarn --immutable']);

        $this->assertSame('yarn --immutable', $recipe->installCommandOr('npm ci'));
    }

    public function test_the_generators_default_is_used_when_the_recipe_is_silent(): void
    {
        $recipe = new BuildRecipe([]);

        $this->assertSame('npm ci', $recipe->installCommandOr('npm ci'));
        $this->assertSame('npm run build', $recipe->buildCommandOr('npm run build'));
    }

    public function test_a_port_hint_beats_the_default(): void
    {
        $this->assertSame(8090, (new BuildRecipe(['port_hint' => 8090]))->port(3000));
    }

    public function test_a_missing_or_unusable_port_hint_falls_back(): void
    {
        $this->assertSame(3000, (new BuildRecipe([]))->port(3000));
        $this->assertSame(3000, (new BuildRecipe(['port_hint' => 0]))->port(3000));
        $this->assertSame(3000, (new BuildRecipe(['port_hint' => 'eight']))->port(3000));
    }

    public function test_environment_entries_are_read_as_strings(): void
    {
        // A YAML integer in a recipe's env block would otherwise reach a
        // Dockerfile ENV line as one.
        $recipe = new BuildRecipe(['env' => ['NODE_ENV' => 'production', 'PORT' => 3000]]);

        $this->assertSame(['NODE_ENV' => 'production', 'PORT' => '3000'], $recipe->env);
    }

    public function test_environment_entries_that_cannot_be_written_are_dropped(): void
    {
        $recipe = new BuildRecipe(['env' => ['A' => null, '' => 'x', 0 => 'y', 'B' => ['nested']]]);

        $this->assertSame([], $recipe->env);
    }

    public function test_a_decision_with_no_environment_has_none(): void
    {
        $this->assertSame([], (new BuildRecipe([]))->env);
        $this->assertSame([], (new BuildRecipe(['env' => 'NODE_ENV=production']))->env);
    }

    public function test_the_projects_root_files_are_queryable(): void
    {
        $recipe = new BuildRecipe([], ['package.json' => true, 'pnpm-lock.yaml' => true]);

        $this->assertTrue($recipe->hasFile('pnpm-lock.yaml'));
        $this->assertFalse($recipe->hasFile('yarn.lock'));
    }

    public function test_the_package_manager_is_detected_when_the_recipe_is_silent(): void
    {
        // A recipe naming one is authoritative; otherwise the lockfile is.
        $recipe = new BuildRecipe([], ['package.json' => true, 'pnpm-lock.yaml' => true]);

        $this->assertSame('pnpm', $recipe->packageManager);
    }

    public function test_a_project_with_no_directory_has_no_package(): void
    {
        // The generator can run against a decision alone, with no checkout.
        $recipe = new BuildRecipe([]);

        $this->assertSame([], $recipe->package());
        $this->assertFalse($recipe->isWorkspace());
    }
}
