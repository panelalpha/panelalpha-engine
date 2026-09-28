<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\Probes\ProbeRegistry;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests over the manifests actually shipped in resources/platforms/
 * and resources/apps/.
 *
 * Adding a platform is adding a YAML file, which is the point — but it also
 * means a typo in that file is a production detection bug with no compiler
 * between it and a deploy. These are that compiler.
 */
class ShippedManifestsTest extends TestCase
{
    /** @return list<PlatformManifest> */
    private function manifests(): array
    {
        return PlatformRegistry::all();
    }

    public function test_every_shipped_manifest_loads_and_validates(): void
    {
        $manifests = $this->manifests();

        $this->assertNotEmpty($manifests);
        foreach ($manifests as $manifest) {
            $this->assertNotSame('', $manifest->id);
            $this->assertNotSame('', $manifest->label);
        }
    }

    public function test_manifests_are_ordered_by_descending_priority(): void
    {
        $priorities = array_map(static fn (PlatformManifest $m): int => $m->priority, $this->manifests());
        $sorted = $priorities;
        rsort($sorted);

        $this->assertSame($sorted, $priorities);
    }

    /**
     * Ties are legal but they make the order depend on the id tie-break
     * rather than on intent, so nothing that must outrank something else may
     * share its number.
     */
    public function test_no_two_manifests_share_a_priority(): void
    {
        $priorities = array_map(static fn (PlatformManifest $m): int => $m->priority, $this->manifests());

        $this->assertSame(count($priorities), count(array_unique($priorities)));
    }

    /**
     * A manifest's `strategy` is the contract with the Dockerfile generators
     * and with DeployCompose::skipBuild — an id nothing downstream recognises
     * deploys as a fallback with no error anywhere.
     *
     * Asserted against the hand-maintained {@see Strategies} list rather than
     * against the manifests, which would only prove they agree with
     * themselves.
     */
    public function test_every_strategy_is_known_downstream(): void
    {
        $known = [
            Strategies::COMPOSE,
            Strategies::DOCKERFILE,
            Strategies::LARAVEL,
            Strategies::PHP,
            Strategies::RAILS,
            Strategies::RUBY,
            Strategies::STATIC,
            Strategies::FALLBACK,
        ];

        foreach ($this->manifests() as $manifest) {
            $recognised = in_array($manifest->strategy, $known, true)
                || Strategies::isKnown($manifest->strategy);

            $this->assertTrue(
                $recognised,
                "Manifest {$manifest->id} declares strategy '{$manifest->strategy}', which nothing downstream handles"
            );
        }
    }

    /**
     * A node or command platform with no serve command builds an image and
     * then exits — a green deploy in front of a container that never listens.
     */
    public function test_every_long_running_platform_declares_a_serve_command(): void
    {
        foreach ($this->manifests() as $manifest) {
            if (!in_array($manifest->runtime, [PlatformManifest::RUNTIME_NODE, PlatformManifest::RUNTIME_COMMAND], true)) {
                continue;
            }
            $this->assertNotNull(
                $manifest->serveCommand(),
                "Manifest {$manifest->id} has runtime '{$manifest->runtime}' but no command marked 'serve'"
            );
        }
    }

    /**
     * Static and nginx platforms are served from built files by a stock nginx
     * image, so a serve command there would be silently ignored.
     */
    public function test_nginx_platforms_do_not_declare_a_serve_command(): void
    {
        foreach ($this->manifests() as $manifest) {
            if ($manifest->runtime !== PlatformManifest::RUNTIME_NGINX) {
                continue;
            }
            $this->assertNull(
                $manifest->serveCommand(),
                "Manifest {$manifest->id} serves static output; its serve command would never run"
            );
        }
    }

    public function test_every_referenced_probe_exists(): void
    {
        $available = array_keys(ProbeRegistry::all());

        foreach ($this->manifests() as $manifest) {
            foreach ($this->probeNames($manifest->detect) as $probe) {
                $this->assertContains(
                    $probe,
                    $available,
                    "Manifest {$manifest->id} references unknown probe '{$probe}'"
                );
            }
        }
    }

    /**
     * The Nitro entry shim's name is written by the engine, so a manifest
     * that hardcodes a stale copy starts a file that is not there.
     */
    public function test_nitro_manifests_reference_the_generated_serve_shim(): void
    {
        foreach (['nuxt', 'tanstack-start'] as $id) {
            $manifest = PlatformRegistry::find($id);
            $this->assertNotNull($manifest, "Missing manifest {$id}");
            $this->assertStringContainsString(
                StandaloneNodeServe::FILENAME,
                (string) $manifest->serveCommand()?->run,
                "Manifest {$id} does not start the engine's Nitro entry shim"
            );
        }
    }

    /**
     * The whole reason the stage vocabulary exists.
     */
    public function test_no_manifest_runs_a_migration_on_every_start(): void
    {
        foreach ($this->manifests() as $manifest) {
            foreach ($manifest->stage(PlatformStage::START) as $command) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b(migrate|db:migrate|db:prepare)\b/',
                    $command->run,
                    "Manifest {$manifest->id} runs '{$command->run}' on every container start"
                );
            }
        }
    }

    /**
     * A manifest that names a toolchain the engine does not have is a deploy
     * that fails at image-resolution time, long after detection said yes.
     */
    /**
     * Every strategy must land on a real branch of DeployStrategy::apply() —
     * either named there, or generic via isGenerated(). One that matches
     * neither falls through to the fallback compose, which looks like a
     * successful deploy of a site that serves nothing.
     */
    public function test_every_strategy_is_dispatched_rather_than_falling_through(): void
    {
        $namedInApply = [
            Strategies::PAEMD, Strategies::COMPOSE, Strategies::DOCKERFILE,
            Strategies::RAILS, Strategies::RUBY, Strategies::LARAVEL, Strategies::PHP,
            Strategies::RAILPACK, Strategies::STATIC, Strategies::FALLBACK,
        ];

        foreach ($this->manifests() as $manifest) {
            $dispatched = in_array($manifest->strategy, $namedInApply, true)
                || Strategies::isGenerated($manifest->strategy);

            $this->assertTrue(
                $dispatched,
                "Manifest {$manifest->id} would fall through apply() to the fallback compose"
            );
        }
    }

    public function test_every_required_runtime_exists(): void
    {
        foreach ($this->manifests() as $manifest) {
            foreach (array_keys($manifest->requires) as $runtimeId) {
                $this->assertTrue(
                    RuntimeRegistry::has((string) $runtimeId),
                    "Manifest {$manifest->id} requires unknown runtime '{$runtimeId}'"
                );
            }
        }
    }

    /**
     * A platform that runs a long-lived process has to say what provides it.
     * Compose and Dockerfile platforms bring their own; nginx ones are served
     * by a stock image from built output.
     */
    public function test_every_code_running_platform_declares_its_runtime(): void
    {
        foreach ($this->manifests() as $manifest) {
            if (!in_array($manifest->runtime, [PlatformManifest::RUNTIME_NODE, PlatformManifest::RUNTIME_COMMAND, PlatformManifest::RUNTIME_PHP], true)) {
                continue;
            }
            $roles = array_column($manifest->requires, 'role');
            $this->assertContains(
                'runtime',
                $roles,
                "Manifest {$manifest->id} runs code but requires no runtime-role toolchain"
            );
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function probeNames(array $node): array
    {
        $found = [];
        foreach ($node as $key => $value) {
            if ($key === 'probe') {
                foreach (is_array($value) ? $value : [$value] as $name) {
                    $found[] = (string) $name;
                }
                continue;
            }
            if (is_array($value)) {
                $children = array_is_list($value) ? $value : [$value];
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $found = array_merge($found, $this->probeNames($child));
                    }
                }
            }
        }

        return $found;
    }

    /**
     * The split is a claim about what each directory is for, and a claim
     * nothing enforces drifts. A platform is a language or a way of
     * delivering one; anything named — a framework you build on, a product
     * you install — is an app.
     */
    public function test_platforms_holds_only_languages_and_delivery_mechanisms(): void
    {
        $expected = [
            'compose', 'compose-nested', 'dockerfile', 'dotnet', 'go', 'html', 'java', 'java-gradle',
            'node', 'not-a-web-app', 'php', 'php-plain', 'python', 'ruby', 'rust',
            'static',
        ];

        $found = [];
        foreach (PlatformRegistry::all() as $manifest) {
            if (str_starts_with($manifest->source, 'platforms/')) {
                $found[] = $manifest->id;
            }
        }
        sort($found);

        $this->assertSame($expected, $found);
    }

    /**
     * Every app in its own directory, under the filename a repository uses to
     * describe itself, and named for the id everything downstream refers to.
     */
    public function test_every_app_lives_in_a_directory_named_for_its_id(): void
    {
        $apps = 0;
        foreach (PlatformRegistry::all() as $manifest) {
            if (!str_starts_with($manifest->source, 'apps/')) {
                continue;
            }
            $apps++;
            $this->assertSame(
                'apps/' . $manifest->id . '/' . PlatformRegistry::APP_FILENAME,
                $manifest->source,
                "{$manifest->id} is not at apps/{$manifest->id}/" . PlatformRegistry::APP_FILENAME
            );
        }

        $this->assertGreaterThan(20, $apps, 'the app catalogue did not load');
    }

    /**
     * Thirty files are called panelalpha.yaml. A parse error naming only the
     * basename would name all thirty at once.
     */
    public function test_a_manifest_names_a_path_someone_can_open(): void
    {
        foreach (PlatformRegistry::all() as $manifest) {
            $this->assertStringContainsString('/', $manifest->source, $manifest->id);
            $this->assertFileExists(
                dirname(PlatformRegistry::defaultDirectory()) . '/' . $manifest->source
            );
        }
    }
}
