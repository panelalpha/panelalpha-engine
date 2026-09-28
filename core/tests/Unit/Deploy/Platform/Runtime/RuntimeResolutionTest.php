<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Platform\Runtime\ImageResolver;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Version resolution, now owned by the runtimes rather than the recipes.
 */
class RuntimeResolutionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/runtime-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->tmpDir . '/' . $name, $contents);
    }

    private function context(): ProjectContext
    {
        $files = [];
        foreach (scandir($this->tmpDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }

        return ProjectContext::make($this->tmpDir, $files);
    }

    public function test_runtimes_are_discovered_by_convention_not_by_a_list(): void
    {
        $ids = array_keys(RuntimeRegistry::all());

        $this->assertSame(['dotnet', 'go', 'java', 'node', 'php', 'python', 'ruby', 'rust'], $ids);
    }

    public function test_a_runtime_returns_null_when_the_project_does_not_use_it(): void
    {
        $this->write('go.mod', "module demo\n\ngo 1.22\n");

        $this->assertNull(RuntimeRegistry::get('php')->resolve($this->context()));
        $this->assertNotNull(RuntimeRegistry::get('go')->resolve($this->context()));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function phpConstraints(): array
    {
        return [
            'caret 8.3'      => ['^8.3', '8.3'],
            'caret 8.2'      => ['^8.2', '8.2'],
            'gte 7.4'        => ['>=7.4', '8.1'],
            'or branches'    => ['^7.4|^8.0', '8.1'],
            'wildcard 8.4'   => ['8.4.*', '8.4'],
            'bounded range'  => ['>=8.2 <8.4', '8.2'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phpConstraints')]
    public function test_php_picks_the_lowest_minor_satisfying_the_constraint(string $constraint, string $expected): void
    {
        $this->write('composer.json', json_encode(['require' => ['php' => $constraint]]));

        $this->assertSame($expected, RuntimeRegistry::get('php')->resolve($this->context())?->version);
    }

    /**
     * The case nobody guesses: a locked dependency narrows the root manifest.
     */
    public function test_a_locked_dependency_can_raise_the_php_version(): void
    {
        $this->write('composer.json', json_encode(['require' => ['php' => '^8.1']]));
        $this->write('composer.lock', json_encode([
            'packages' => [['name' => 'acme/x', 'require' => ['php' => '^8.3']]],
        ]));

        $requirement = RuntimeRegistry::get('php')->resolve($this->context());

        $this->assertSame('8.3', $requirement?->version);
        $this->assertStringContainsString('composer.lock', (string) $requirement?->source);
    }

    /**
     * A lock is the answer, not a constraint set — when it agrees with itself.
     *
     * `composer install` reads the platform out of the lock's own
     * `platform-overrides` and never consults the interpreter, so a version
     * derived from constraints describes an install that will not happen.
     */
    public function test_a_lock_overrides_php_is_the_version_its_install_will_use(): void
    {
        $this->write('composer.json', json_encode(['require' => ['php' => '>=8.2,<=8.5']]));
        $this->write('composer.lock', json_encode([
            'platform-overrides' => ['php' => '8.3'],
            'packages' => [['name' => 'acme/x', 'require' => ['php' => '>=8.2']]],
        ]));

        $requirement = RuntimeRegistry::get('php')->resolve($this->context());

        $this->assertSame('8.3', $requirement?->version);
        $this->assertSame('composer.lock platform', $requirement?->source);
    }

    /**
     * A lock that contradicts itself cannot be trusted about its own platform.
     *
     * egroupware pins every package under `--ignore-platform-reqs`, so its lock
     * says 8.2 while it locked packages requiring 8.4. Building on 8.4 and
     * letting the install resolve against 8.2 was the original failure — 22
     * problems, deploy failed — and taking the lock's 8.2 fixed that by
     * building on 8.2 instead.
     *
     * But a contradiction also relaxes the install with
     * `--ignore-platform-req=php`, so those 8.4 packages were then installed
     * anyway and the application ran on 8.2: a green deploy on an interpreter
     * its own dependencies had said no to, failing later at runtime instead of
     * loudly at install. With the relaxation doing the work, the image should
     * be the one the packages accept.
     *
     * So the relaxation stays and the version comes from the constraints,
     * which include the locked packages' own `require.php`.
     */
    public function test_a_self_contradicting_lock_does_not_decide_the_version(): void
    {
        $this->write('composer.json', json_encode(['require' => ['php' => '>=8.2,<=8.5']]));
        $this->write('composer.lock', json_encode([
            'platform-overrides' => ['php' => '8.2'],
            'packages' => [['name' => 'acme/x', 'require' => ['php' => '~8.4.0']]],
        ]));

        $requirement = RuntimeRegistry::get('php')->resolve($this->context());

        $this->assertSame('8.4', $requirement?->version);
        $this->assertNotSame('composer.lock platform', $requirement?->source);
    }

    /**
     * A dev-only offender is not a contradiction: `composer install --no-dev`
     * loads only the non-dev repository, so that package's `require.php` is
     * never checked. Counting it moved a production deploy off the version its
     * lock names on the strength of something that is not installed.
     */
    public function test_a_dev_only_requirement_leaves_the_lock_platform_alone(): void
    {
        $this->write('composer.json', json_encode(['require' => ['php' => '>=8.2,<=8.5']]));
        $this->write('composer.lock', json_encode([
            'platform-overrides' => ['php' => '8.2'],
            'packages' => [],
            'packages-dev' => [['name' => 'acme/tool', 'require' => ['php' => '~8.4.0']]],
        ]));

        $requirement = RuntimeRegistry::get('php')->resolve($this->context());

        $this->assertSame('8.2', $requirement?->version);
        $this->assertSame('composer.lock platform', $requirement?->source);
    }

    /**
     * `platform` is the fallback when `platform-overrides` is absent, and only
     * a value shaped like a minor is admitted — a constraint there belongs to
     * the constraint path, not to an image tag.
     */
    public function test_only_a_plain_minor_in_the_lock_is_a_locked_platform(): void
    {
        $this->assertSame('8.3', PhpRuntime::lockedPlatform(json_encode(['platform' => ['php' => '8.3.7']])));
        $this->assertSame('8.2', PhpRuntime::lockedPlatform(json_encode([
            'platform-overrides' => ['php' => '8.2'],
            'platform' => ['php' => '8.3'],
        ])), 'platform-overrides is what install obeys, so it outranks platform');
        $this->assertNull(
            PhpRuntime::lockedPlatform(json_encode(['platform' => ['php' => '>=8.2']])),
            'a constraint is not a version the engine can hand to an image tag'
        );
        // htmly pins 7.2 in a lock it installs from; the engine publishes no
        // 7.2 image, so the pin must not be allowed to name one.
        $this->assertNull(PhpRuntime::lockedPlatform(json_encode(['platform-overrides' => ['php' => '7.2']])));

        // No lock, no statement: the constraint path stays in charge.
        $this->write('composer.json', json_encode(['require' => ['php' => '^8.2']]));
        $this->assertSame('8.2', RuntimeRegistry::get('php')->resolve($this->context())?->version);
    }

    /**
     * The contradiction is the lock's to admit, and only the lock's: a
     * manifest whose floor sits above the lock's platform is ordinary (htmly
     * pins 7.2 and needs 8.1) and installs fine, so it must not switch the
     * check off.
     */
    public function test_only_a_locks_own_packages_can_contradict_its_platform(): void
    {
        $contradicted = json_encode([
            'platform-overrides' => ['php' => '8.2'],
            'packages' => [
                ['name' => 'ok/a', 'require' => ['php' => '^8.2']],
                ['name' => 'bad/b', 'require' => ['php' => '~8.4.0']],
            ],
        ]);
        $this->assertTrue(PhpRuntime::lockedPhpContradicted($contradicted));

        $consistent = json_encode([
            'platform-overrides' => ['php' => '8.2'],
            'packages' => [['name' => 'ok/a', 'require' => ['php' => '^8.2']]],
        ]);
        $this->assertFalse(PhpRuntime::lockedPhpContradicted($consistent));

        // A lock that states no platform contradicts nothing -- the requirement
        // resolved from its packages is the one install will enforce.
        $this->assertFalse(PhpRuntime::lockedPhpContradicted(json_encode([
            'packages' => [['name' => 'bad/b', 'require' => ['php' => '~8.4.0']]],
        ])));
        $this->assertFalse(PhpRuntime::lockedPhpContradicted(null));
    }

    /**
     * Previously an unparseable constraint read as "compatible with
     * everything" and selected the *oldest* PHP the engine ships — the less
     * we understood, the older the runtime we picked.
     */
    public function test_an_unparseable_php_constraint_falls_back_to_the_default_not_the_oldest(): void
    {
        $this->write('composer.json', json_encode(['require' => ['php' => 'lol']]));

        $this->assertSame('8.3', RuntimeRegistry::get('php')->resolve($this->context())?->version);
    }

    public function test_node_reads_engines_then_nvmrc(): void
    {
        $this->write('package.json', json_encode(['engines' => ['node' => '>=22']]));
        $this->assertSame('22', RuntimeRegistry::get('node')->resolve($this->context())?->version);

        $this->write('package.json', json_encode(['name' => 'x']));
        $this->write('.nvmrc', "18\n");
        $requirement = RuntimeRegistry::get('node')->resolve($this->context());
        $this->assertSame('18', $requirement?->version);
        $this->assertSame('.nvmrc', $requirement?->source);
    }

    public function test_node_defaults_to_an_older_lts_when_the_project_says_nothing(): void
    {
        $this->write('package.json', '{}');

        $this->assertSame('20', RuntimeRegistry::get('node')->resolve($this->context())?->version);
    }

    public function test_go_honours_a_version_newer_than_anything_listed(): void
    {
        $this->write('go.mod', "module demo\n\ngo 1.99\n");

        $this->assertSame('1.99', RuntimeRegistry::get('go')->resolve($this->context())?->version);
    }

    public function test_python_reads_requires_python(): void
    {
        $this->write('pyproject.toml', "[project]\nrequires-python = \">=3.13\"\n");

        $requirement = RuntimeRegistry::get('python')->resolve($this->context());
        $this->assertSame('3.13', $requirement?->version);
        $this->assertSame('python:3.13-slim', RuntimeRegistry::get('python')->image($requirement));
    }

    public function test_ruby_reads_ruby_version_and_strips_an_engine_prefix(): void
    {
        $this->write('Gemfile', "source 'https://rubygems.org'\n");
        $this->write('.ruby-version', "ruby-3.4.1\n");

        $this->assertSame('3.4.1', RuntimeRegistry::get('ruby')->resolve($this->context())?->version);
    }

    public function test_java_picks_the_builder_image_from_the_build_file(): void
    {
        $this->write('build.gradle.kts', '');
        $gradle = RuntimeRegistry::get('java')->resolve($this->context());
        $this->assertSame(RuntimeRegistry::get('java')->image($gradle), 'gradle:8-jdk21');

        unlink($this->tmpDir . '/build.gradle.kts');
        $this->write('pom.xml', '<project/>');
        $maven = RuntimeRegistry::get('java')->resolve($this->context());
        $this->assertSame(RuntimeRegistry::get('java')->image($maven), 'maven:3-eclipse-temurin-21');
    }

    /**
     * A requirement that cannot say why it exists is a support ticket.
     */
    public function test_every_requirement_names_the_file_that_decided_it(): void
    {
        $this->write('composer.json', json_encode(['require' => ['php' => '^8.3']]));

        $this->assertSame(
            'php 8.3 (from composer.json require.php)',
            RuntimeRegistry::get('php')->resolve($this->context())?->explain()
        );
    }

    /**
     * Regression: the Rust start command reads the binary name out of
     * Cargo.toml, and the helper that does it was left behind when the recipe
     * classes were retired. Nothing exercised it, so the break only showed up
     * on a real Cargo.toml.
     */
    public function test_rust_start_command_uses_the_crate_binary_name(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"my-api\"\n");

        $start = RustRuntime::startCommand($this->tmpDir);

        $this->assertStringContainsString('b=./target/release/my-api;', $start);
        $this->assertStringEndsWith('exec "$b"', $start);
    }

    /**
     * The start command runs in the runtime image, which is Debian or Ubuntu with
     * GNU find; busybox find (an Alpine test runner) has no -printf.
     */
    private function requireGnuFind(): void
    {
        exec('find . -maxdepth 0 -printf "" 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('needs GNU find (-printf), which the runtime images ship');
        }
    }

    /**
     * Runs the generated start command against a fake target/release and
     * returns what it printed and which binary it exec'd.
     *
     * @param list<string> $executables
     * @return array{int, string}
     */
    private function runRustStart(array $executables, string $start): array
    {
        $this->requireGnuFind();
        $release = $this->tmpDir . '/target/release';
        mkdir($release . '/deps', 0o777, true);
        foreach ($executables as $name) {
            file_put_contents($release . '/' . $name, "#!/bin/sh\necho ran {$name}\n");
            chmod($release . '/' . $name, 0o755);
        }
        // Build by-products that must not count as a candidate.
        file_put_contents($release . '/' . ($executables[0] ?? 'x') . '.d', '');
        file_put_contents($release . '/deps/other', '');
        chmod($release . '/deps/other', 0o755);

        exec('cd ' . escapeshellarg($this->tmpDir) . ' && sh -c ' . escapeshellarg($start) . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    }

    public function test_rust_start_runs_the_cargo_package_binary(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"fx\"\n");

        [$code, $out] = $this->runRustStart(['fx', 'helper'], RustRuntime::startCommand($this->tmpDir));

        $this->assertSame(0, $code);
        $this->assertSame('ran fx', $out);
    }

    public function test_rust_start_runs_the_only_built_binary_when_cargo_names_none(): void
    {
        // A workspace root: no [package], so the name falls back to `app`.
        $this->write('Cargo.toml', "[workspace]\nmembers = [\"server\"]\n");

        [$code, $out] = $this->runRustStart(['bichon-server'], RustRuntime::startCommand($this->tmpDir));

        $this->assertSame(0, $code);
        $this->assertSame('ran bichon-server', $out);
    }

    public function test_rust_start_lists_the_candidates_when_it_cannot_choose(): void
    {
        $this->write('Cargo.toml', "[workspace]\nmembers = [\"core\", \"km\"]\n");

        [$code, $out] = $this->runRustStart(['core', 'km'], RustRuntime::startCommand($this->tmpDir));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('PANELALPHA: no runnable Rust binary at ./target/release/app', $out);
        $this->assertMatchesRegularExpression('/built binaries: (core km|km core)/', $out);
        $this->assertStringNotContainsString('ran ', $out);
    }

    public function test_rust_prefers_an_explicit_bin_name_over_the_package_name(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"lib-name\"\n\n[[bin]]\nname = \"server\"\n");

        $this->assertSame('target/release/server', RustRuntime::binaryPath($this->tmpDir));
    }

    /**
     * The gate that decides whether Railpack gets a look, derived from the
     * runtimes rather than from a list of manifest filenames.
     */
    public function test_any_recognises_reports_whether_a_toolchain_claims_the_project(): void
    {
        $this->assertFalse(RuntimeRegistry::anyRecognises($this->context()));

        $this->write('go.mod', "module demo\n\ngo 1.22\n");
        $this->assertTrue(RuntimeRegistry::anyRecognises($this->context()));
    }

    /**
     * C and C++ reach Railpack without the engine having a runtime for them.
     *
     * The engine has no C toolchain to resolve, image or warm, so there is no
     * `Runtime` class and the derived loop cannot find one. Railpack does have
     * a provider, and it claims a project on exactly these two files —
     * `Detect()` is `HasFile("CMakeLists.txt") || HasFile("meson.build")` —
     * so without them named here a C project is written off as unknown and
     * served the engine's placeholder.
     */
    public function test_a_cmake_project_is_recognised_for_railpack(): void
    {
        $this->assertFalse(RuntimeRegistry::anyRecognises($this->context()));

        $this->write('CMakeLists.txt', "cmake_minimum_required(VERSION 3.10)\n");

        $this->assertTrue(RuntimeRegistry::anyRecognises($this->context()));
        $this->assertContains('cpp', RuntimeRegistry::recognisedBy($this->context()));
    }

    public function test_a_meson_project_is_recognised_for_railpack(): void
    {
        $this->write('meson.build', "project('demo', 'c')\n");

        $this->assertTrue(RuntimeRegistry::anyRecognises($this->context()));
        $this->assertContains('cpp', RuntimeRegistry::recognisedBy($this->context()));
    }

    /**
     * The markers are Railpack's, and this is the line of that: Railpack's cpp
     * provider does not claim a Makefile or an autotools checkout, so neither
     * does this. Naming them would hand Railpack a project it declines, and the
     * `fallback` verdict those get today is the honest one.
     */
    public function test_a_makefile_alone_is_not_a_railpack_cpp_project(): void
    {
        $this->write('Makefile', "all:\n\tcc -o app main.c\n");

        $this->assertFalse(RuntimeRegistry::anyRecognises($this->context()));
        $this->assertSame([], RuntimeRegistry::recognisedBy($this->context()));
    }

    /** An autotools checkout — icecast-shaped — is not claimed either. */
    public function test_autotools_alone_is_not_a_railpack_cpp_project(): void
    {
        $this->write('configure.ac', "AC_INIT([demo], [1.0])\n");
        $this->write('autogen.sh', "#!/bin/sh\nautoreconf -i\n");

        $this->assertFalse(RuntimeRegistry::anyRecognises($this->context()));
    }

    // -- image assembly ---------------------------------------------------

    public function test_a_build_role_toolchain_stays_out_of_the_runtime_image(): void
    {
        $php = new Requirement('php', '8.3', '^8.3', 'composer.json');
        $node = new Requirement('node', '20', '', 'default', Requirement::ROLE_BUILD);

        // PHP's answer is the shared base it runs on, not the official tag
        // that base is built from -- the same door every runtime uses.
        $this->assertSame(
            PhpBaseImage::tag(PhpRuntime::imageTag('8.3')),
            ImageResolver::runtimeImage([$php, $node])
        );
        $this->assertSame(['node' => 'node:20-bookworm-slim'], ImageResolver::buildImages([$php, $node]));
        $this->assertFalse(ImageResolver::needsComposite([$php, $node]));
    }

    public function test_two_runtime_role_toolchains_need_a_composite(): void
    {
        $php = new Requirement('php', '8.3', '', 'composer.json');
        $node = new Requirement('node', '20', '', 'default');

        $this->assertTrue(ImageResolver::needsComposite([$php, $node]));
        $this->assertStringStartsWith('panelalpha/stack:node-20_php-8.3-pa', ImageResolver::runtimeImage([$php, $node]));
    }

    /**
     * Two identical stacks must resolve to one tag, or the shared cache never
     * hits and the host builds the same image twice.
     */
    public function test_a_composite_tag_does_not_depend_on_declaration_order(): void
    {
        $php = new Requirement('php', '8.3', '', 'composer.json');
        $node = new Requirement('node', '20', '', 'default');

        $this->assertSame(
            ImageResolver::compositeTag([$php, $node]),
            ImageResolver::compositeTag([$node, $php])
        );
    }

    public function test_a_stale_cargo_lock_does_not_refuse_the_deploy(): void
    {
        // `--locked` is right when the lock is good and fatal when it is a
        // little stale, which is common: Komodo's carries two dependency
        // entries its manifests no longer reference, and cargo stops with
        // "cannot update the lock file ... because --locked was passed"
        // before compiling anything.
        $this->write('Cargo.lock', "# lock\n");

        $this->assertSame(
            'cargo build --release --locked || cargo build --release',
            RustRuntime::buildCommand($this->tmpDir)
        );
    }

    public function test_no_lockfile_means_no_locked_flag_to_fall_back_from(): void
    {
        $this->assertSame('cargo build --release', RustRuntime::buildCommand($this->tmpDir));
    }

}
