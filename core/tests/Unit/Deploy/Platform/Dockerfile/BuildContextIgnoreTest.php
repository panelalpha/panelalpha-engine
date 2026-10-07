<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\Dockerfile\BuildContextIgnore;
use App\Lib\Deploy\Platform\Dockerfile\DockerIgnore;
use PHPUnit\Framework\TestCase;

/**
 * The engine's compose files and `.git` sat in the build context
 * and made `COPY . .` miss on every redeploy. Apache Guacamole's RAT
 * license check failed on the engine's compose file and an empty `.env`.
 */
class BuildContextIgnoreTest extends TestCase
{
    public function test_the_ignore_file_sits_next_to_the_dockerfile(): void
    {
        $this->assertSame('Dockerfile.dockerignore', BuildContextIgnore::pathFor('Dockerfile'));
        $this->assertSame('docker/app.Dockerfile.dockerignore', BuildContextIgnore::pathFor('docker/app.Dockerfile'));
        $this->assertSame('docker/app.Dockerfile.dockerignore', BuildContextIgnore::pathFor('./docker/app.Dockerfile'));
        $this->assertSame('.docker/Dockerfile.dockerignore', BuildContextIgnore::pathFor('.docker/Dockerfile'));
    }

    public function test_the_engines_compose_files_are_left_out_under_their_current_names(): void
    {
        $lines = $this->lines(BuildContextIgnore::render(null, 'Dockerfile', true, false));

        $this->assertContains(EngineArtifacts::RUN_COMPOSE, $lines);
        $this->assertContains(EngineArtifacts::RUN_COMPOSE_OVERRIDE, $lines);
        $this->assertContains(EngineArtifacts::APP_CONFIG_COMPOSE, $lines);
        $this->assertContains('Dockerfile.dockerignore', $lines);
        $this->assertContains('.git', $lines);
        // docker-compose.yml is the client's name since ADR-0001, not the engine's.
        $this->assertNotContains('docker-compose.yml', $lines);
    }

    public function test_a_projects_own_dockerfile_gets_nothing_else_added(): void
    {
        $lines = $this->lines(BuildContextIgnore::render(null, 'Dockerfile', true, false));

        $this->assertNotContains('node_modules', $lines);
    }

    public function test_the_projects_rules_are_kept_and_come_first(): void
    {
        $project = "node_modules\n.output\n!keep.me\n";
        $rendered = BuildContextIgnore::render($project, 'Dockerfile', true, false);
        $lines = $this->lines($rendered);

        $this->assertStringContainsString($project, $rendered);
        $this->assertGreaterThan(
            array_search('!keep.me', $lines, true),
            array_search('.git', $lines, true),
            'last match wins, so .git must follow the project\'s rules'
        );
    }

    public function test_git_stays_when_the_build_reads_it(): void
    {
        $this->assertNotContains('.git', $this->lines(BuildContextIgnore::render(null, 'Dockerfile', false, false)));
    }

    public function test_a_generated_build_without_a_dockerignore_gets_the_base_rules(): void
    {
        $lines = $this->lines(BuildContextIgnore::render(null, 'panelalpha.Dockerfile', true, true));

        $this->assertContains('node_modules', $lines);
        $this->assertContains('.git', $lines);
        $this->assertContains('panelalpha.Dockerfile', $lines);
        $this->assertContains('panelalpha.Dockerfile.dockerignore', $lines);
    }

    public function test_the_dockerignore_an_older_engine_wrote_is_not_copied_as_the_projects(): void
    {
        $legacy = "# Written only when a project has none of its own; a project's .dockerignore\n"
            . "# is left exactly as the customer wrote it.\n.git\nnode_modules\npanelalpha.Dockerfile\ndocker-compose.yml\n";

        $lines = $this->lines(BuildContextIgnore::render($legacy, 'panelalpha.Dockerfile', true, true));

        $this->assertNotContains('docker-compose.yml', $lines);
        $this->assertContains('node_modules', $lines);
    }

    public function test_the_shipped_base_rules_no_longer_name_a_client_file(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^docker-compose\.yml$/m', DockerIgnore::contents());
        $this->assertMatchesRegularExpression('/^\.git$/m', DockerIgnore::contents());
    }

    public function test_rendering_is_stable_so_the_file_itself_never_busts_the_cache(): void
    {
        $this->assertSame(
            BuildContextIgnore::render("vendor\n", 'Dockerfile', true, false),
            BuildContextIgnore::render("vendor\n", 'Dockerfile', true, false)
        );
    }

    public function test_it_tells_its_own_file_from_a_projects(): void
    {
        $this->assertTrue(BuildContextIgnore::isEngineWritten(BuildContextIgnore::render(null, 'Dockerfile', true, false)));
        $this->assertFalse(BuildContextIgnore::isEngineWritten("node_modules\n"));
        $this->assertFalse(BuildContextIgnore::isEngineWritten(null));
    }

    public function test_the_generated_name_is_a_reserved_engine_artifact(): void
    {
        $this->assertContains('/panelalpha.Dockerfile.dockerignore', EngineArtifacts::reservedPatterns());
    }

    /** Guacamole's own .dockerignore, then the engine's files, then the empty .env and .git. */
    public function test_the_projects_rules_come_first_and_the_engines_files_after(): void
    {
        $lines = $this->lines(BuildContextIgnore::render(".git\ntarget/\n", 'Dockerfile', true, false, true));

        $this->assertSame([
            '.git',
            'target/',
            'docker-compose.panelalpha.yml',
            'docker-compose.panelalpha.override.yml',
            'docker-compose.panelalpha.app-config.yml',
            '.env.panelalpha',
            '.env.default',
            'panelalpha.passwd',
            'panelalpha.group',
            'panelalpha.Dockerfile',
            'panelalpha.Dockerfile.dockerignore',
            'Dockerfile.dockerignore',
            '.env',
            '.git',
        ], $lines);
    }

    /** The account's env_vars when the repository tracks .env: never baked into an image. */
    public function test_the_env_overrides_are_always_left_out(): void
    {
        $this->assertContains(EngineArtifacts::ENV_OVERRIDES, $this->lines(BuildContextIgnore::render(null, 'Dockerfile', false, false)));
        $this->assertContains(EngineArtifacts::ENV_OVERRIDES, $this->lines(BuildContextIgnore::render(null, 'panelalpha.Dockerfile', true, true)));
    }

    /** A COPY . of the context put it in the image, generated passwords and all. */
    public function test_the_engines_env_default_is_always_left_out(): void
    {
        $this->assertContains(EngineArtifacts::ENV_DEFAULT, $this->lines(BuildContextIgnore::render(null, 'Dockerfile', false, false)));
        $this->assertContains(EngineArtifacts::ENV_DEFAULT, $this->lines(BuildContextIgnore::render("node_modules\n", 'Dockerfile', true, true)));
    }

    public function test_env_is_listed_only_when_asked(): void
    {
        $this->assertNotContains('.env', $this->lines(BuildContextIgnore::render(null, 'Dockerfile', true, false)));
    }

    public function test_an_empty_env_is_left_out(): void
    {
        $this->assertTrue(BuildContextIgnore::excludesEnv('', "FROM maven\nCOPY . /build\n"));
        $this->assertTrue(BuildContextIgnore::excludesEnv("\n", "FROM maven\nCOPY . /build\n"));
    }

    /** A Vite or Next build reads it after `COPY . .`: that is how the values reach the build. */
    public function test_an_env_with_values_stays(): void
    {
        $this->assertFalse(BuildContextIgnore::excludesEnv("VITE_API=https://x\n", "COPY . .\nRUN npm run build\n"));
    }

    public function test_an_env_the_dockerfile_copies_by_name_stays(): void
    {
        $this->assertFalse(BuildContextIgnore::excludesEnv('', "COPY .env /app/.env\n"));
        $this->assertFalse(BuildContextIgnore::excludesEnv('', "ADD --chown=app .env.example .env\n"));
    }

    public function test_no_env_means_nothing_to_leave_out(): void
    {
        $this->assertFalse(BuildContextIgnore::excludesEnv(null, 'COPY . .'));
    }

    /** @return list<string> */
    private function lines(string $contents): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $contents)),
            static fn (string $line): bool => $line !== '' && $line[0] !== '#'
        ));
    }
}
