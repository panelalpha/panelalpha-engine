<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\NtfySourceRecipeFixtures;

/**
 * ntfy ships three Dockerfiles and detection built the wrong one.
 *
 * The root `Dockerfile` — the file a repository is expected to put its
 * deployable image in — is the *release* file: `FROM alpine`, `COPY ntfy
 * /usr/bin`, `ENTRYPOINT ["ntfy"]`. It copies a binary GoReleaser produces in
 * CI into an image that does not have it, so building it from a clean
 * checkout gives a container that starts and exits immediately and restart-
 * loops. That was the whole observed failure.
 *
 * `Dockerfile-build` is the file the project's own `make docker-dev` passes
 * to `docker build`, and the only one that compiles the server — docs, then
 * the web UI (which the binary consumes through `//go:embed all:dist`), then
 * the Go build. Its final stage is the default last stage, so no `--target`
 * is involved.
 *
 * What is asserted here is the recipe and the compose file it produces — a
 * deploy is the only thing that can say ntfy installs.
 */
class NtfySourceRecipeTest extends TestCase
{
    use NtfySourceRecipeFixtures;

    /**
     * The whole reason the recipe exists: build the source file, not the
     * release one that copies in a binary CI has not run yet.
     */
    public function test_it_builds_the_source_dockerfile_not_the_release_one(): void
    {
        $recipe = SourceRecipes::for(self::URL);
        $this->assertNotNull($recipe);

        $decision = $recipe->describe($this->context());

        $this->assertSame('Dockerfile-build', $decision['dockerfile']);
        $this->assertSame(80, $decision['port_hint']);
        // No ARG values: the file's own VERSION=dev / COMMIT=unknown /
        // NODE_MAJOR=24 defaults build the right variant, and `VERSION` is
        // baked into a label and the version string, not into which image is
        // produced.
        $this->assertSame([], $recipe->buildArgs);
    }

    /**
     * The compose file the dockerfile strategy writes: the repository's own
     * Dockerfile-build, built with the repo root as context — `ADD ./web`,
     * `ADD ./docs` and the Go sources all require that — and published on the
     * host port the proxy leaves free.
     */
    public function test_the_generated_compose_builds_dockerfile_build_from_the_repo_root(): void
    {
        $recipe = SourceRecipes::for(self::URL);
        $this->assertNotNull($recipe);

        $decision = $recipe->describe($this->context());
        $service = Yaml::parse(DeployCompose::dockerfile(
            $decision['dockerfile'] ?? 'Dockerfile',
            (int) $decision['port_hint'],
            $decision
        ))['services']['app'];

        $this->assertSame(['8080:80'], $service['ports']);
        // Not the `build: .` shorthand: a named Dockerfile forces the map.
        $this->assertSame('.', $service['build']['context']);
        $this->assertSame('Dockerfile-build', $service['build']['dockerfile']);
    }

    /**
     * ntfy's Dockerfile-build never copied ban/, metrics/ and twilio/ into the
     * builder — three packages the server imports — so the Go layer stops with
     * `no required module provides package …` and no image is produced. The
     * prepare hook repairs the file.
     *
     * Run for real, against a Dockerfile shaped like the upstream one, rather
     * than asserted as a string: what has to hold is that the three ADD lines
     * appear above the build layer and that a second run changes nothing. The
     * hook `cd`s to `~/project`, so it is run under a HOME that points at the
     * fixture.
     */
    public function test_the_prepare_hook_adds_the_missing_packages_idempotently(): void
    {
        $home = sys_get_temp_dir() . '/pa-ntfy-hook-' . bin2hex(random_bytes(6));
        mkdir($home . '/project', 0777, true);
        $dockerfile = $home . '/project/Dockerfile-build';
        file_put_contents(
            $dockerfile,
            "FROM golang:1.25-bookworm AS builder\n"
            . "ADD go.mod go.sum main.go ./\n"
            . "ADD ./cmd ./cmd\n"
            . "ADD ./server ./server\n"
            . "RUN --mount=type=cache,target=/go/pkg/mod make cli-linux-server\n"
            . "FROM alpine\n"
            . "COPY --from=builder /app/dist/ntfy_linux_server/ntfy /usr/bin/ntfy\n"
        );

        $hook = SourceRecipes::defaultDirectory() . '/github.com/binwiederhier/ntfy/hooks/prepare.sh';
        $this->assertFileExists($hook);

        foreach ([1, 2] as $run) {
            exec('HOME=' . escapeshellarg($home) . ' bash ' . escapeshellarg($hook) . ' 2>&1', $output, $status);
            $this->assertSame(0, $status, "hook failed on run {$run}: " . implode("\n", $output));
        }

        $patched = (string) file_get_contents($dockerfile);
        foreach (['ban', 'metrics', 'twilio'] as $dir) {
            // Exactly one ADD per directory, even though the hook ran twice.
            $this->assertSame(
                1,
                preg_match_all('/^ADD \.\/' . $dir . ' \.\/' . $dir . '$/m', $patched),
                "{$dir}/ was not copied exactly once"
            );
            // And above the layer that needs it, or the build is still stale.
            $this->assertLessThan(
                strpos($patched, 'cli-linux-server'),
                strpos($patched, "ADD ./{$dir} ./{$dir}"),
                "{$dir}/ is copied after the build layer"
            );
        }
    }

    /** Somewhere to describe a manifest against, with no Dockerfile inside. */
    private function context(): ProjectContext
    {
        $dir = sys_get_temp_dir() . '/pa-ntfy-recipe-' . bin2hex(random_bytes(6));
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return ProjectContext::make($dir, ProjectContext::listRootFiles($dir));
    }
}
