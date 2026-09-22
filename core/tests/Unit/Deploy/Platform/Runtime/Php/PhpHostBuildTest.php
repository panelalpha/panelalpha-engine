<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\ProjectCache;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhpHostBuildTest extends TestCase
{
    public function test_dependencies_run_before_assets(): void
    {
        $script = PhpHostBuild::script('composer install --no-dev', 'composer run-script post-autoload-dump');

        $dependencies = strpos($script, 'composer install --no-dev');
        $assets = strpos($script, 'post-autoload-dump');

        $this->assertNotFalse($dependencies);
        $this->assertNotFalse($assets);
        $this->assertLessThan(
            $assets,
            $dependencies,
            'post-autoload-dump has nothing to dump until composer install has finished'
        );
    }

    /**
     * Which commands may fail is the manifest's to say. A command marked
     * `optional: true` arrives already carrying its own `|| true`; one that
     * is not marked is load-bearing, and this layer must not soften it.
     *
     * Adminer is the case that matters: `compile.php` produces the only
     * index.php there is, so swallowing its failure would serve an empty site
     * behind a green deploy. The generated Dockerfile emitted it as a bare
     * `RUN` for the same reason.
     */
    public function test_the_manifest_decides_what_may_fail_not_this_layer(): void
    {
        $strict = PhpHostBuild::script('composer install', 'php compile.php');
        $this->assertStringNotContainsString('|| true', $strict);
        $this->assertStringNotContainsString('continuing', $strict);

        $optional = PhpHostBuild::script('composer install', 'composer run-script dump || true');
        $this->assertStringContainsString('composer run-script dump || true', $optional);
    }

    /**
     * Ten of the fourteen shipped PHP manifests declare no build commands at
     * all, because the generated Dockerfile installed Composer dependencies
     * for every PHP project whether the manifest asked or not. Taking the
     * manifest as the only source deployed those ten with no vendor/ and
     * nothing in the log — the container started and 500'd on its first
     * request.
     */
    public function test_a_project_with_composer_gets_an_install_its_manifest_never_declared(): void
    {
        $script = PhpHostBuild::script('', '', true);

        $this->assertStringContainsString('composer install', $script);
        $this->assertStringContainsString('--no-scripts', $script);
        $this->assertStringContainsString('--no-plugins', $script);
    }

    /**
     * osTicket vendors its dependencies and ships no composer.json. Running
     * composer there fails a deploy for an application that never needed it.
     */
    public function test_a_project_without_composer_gets_no_install(): void
    {
        $this->assertSame('', PhpHostBuild::script('', '', false));
        $this->assertSame('', PhpHostBuild::script('   ', "\n", false));
    }

    public function test_a_manifest_that_declares_its_own_install_keeps_it(): void
    {
        $script = PhpHostBuild::script('composer install --prefer-dist', '', true);

        $this->assertStringContainsString('composer install --prefer-dist', $script);
        $this->assertStringNotContainsString(PhpHostBuild::DEFAULT_INSTALL, $script);
    }

    /**
     * Composer resolves require-dev under --no-dev and, since 2.7, refuses
     * the whole graph over a security advisory that only touches it.
     * Islandora is the case: its require-dev pins phpunit 6 and
     * php_codesniffer 2.7, both end-of-life and both carrying advisories, and
     * the build died on them with the runtime requirements resolving
     * perfectly. Verified against the repository with Composer 2.10.2 -- the
     * same `install --no-dev` succeeds once require-dev is gone.
     */
    public function test_the_runtime_manifest_drops_require_dev(): void
    {
        $manifest = <<<'JSON'
        {
            "name": "drupal/islandora",
            "require": { "drupal/action": "^0.2" },
            "require-dev": { "phpunit/phpunit": "^6" }
        }
        JSON;

        $runtime = PhpHostBuild::runtimeManifest($manifest);

        $this->assertNotNull($runtime);
        $this->assertStringNotContainsString('require-dev', $runtime);
        $this->assertStringNotContainsString('phpunit', $runtime);
        // Only require-dev goes: dropping anything else would resolve a
        // different tree than the project asked for.
        $this->assertStringContainsString('drupal/action', $runtime);
    }

    public function test_a_manifest_without_dev_requirements_is_left_alone(): void
    {
        $manifest = '{"name": "acme/app", "require": {"psr/log": "^1.0"}}';

        $this->assertNull(PhpHostBuild::runtimeManifest($manifest));
        $this->assertNull(PhpHostBuild::runtimeManifest('{"require-dev": {"phpunit/phpunit": "^6"}}'));
    }

    /**
     * The written manifest has to be the *shape* Composer validated, or the
     * copy is refused wholesale.
     *
     * omeka ships `"require": {}` beside a require-dev and no lock, so this is
     * the path it takes. Decoded as an associative array, that empty map came
     * back as `[]` and Composer answered
     *
     *     require : Array value found, but an object is required
     *     ".pa-runtime-composer.json" does not match the expected JSON schema
     *
     * and the deploy died on the engine's own manifest, naming the engine's
     * own file. Verified with `composer validate`: the `[]` form is rejected
     * exactly there, and the `{}` form is accepted.
     */
    public function test_an_empty_map_stays_a_map_when_the_manifest_is_rewritten(): void
    {
        $manifest = '{"name": "omeka/omeka", "require": {}, "require-dev": {"phpunit/phpunit": "^7|^8"}}';

        $runtime = PhpHostBuild::runtimeManifest($manifest);

        $this->assertNotNull($runtime);
        $this->assertStringContainsString('"require": {}', $runtime);
        $this->assertStringNotContainsString('"require": []', $runtime);
        $this->assertSame('object', gettype(json_decode($runtime)->require),
            'Composer\'s schema requires every one of these maps to be an object');
    }

    /** Rewriting must not turn a real list into an object either. */
    public function test_lists_are_left_as_lists_when_the_manifest_is_rewritten(): void
    {
        $manifest = '{"require": {"php": "^8.1"}, "require-dev": {"a/b": "^1"},'
            . ' "classmap": ["src/"], "authors": [{"name": "A"}]}';

        $runtime = PhpHostBuild::runtimeManifest($manifest);
        $decoded = json_decode((string) $runtime);

        $this->assertIsArray($decoded->classmap);
        $this->assertIsArray($decoded->authors);
        $this->assertIsObject($decoded->require);
    }

    /**
     * A committed lock is the resolution the project ships, and a project
     * that has one must install from it.
     *
     * This is the case that made the workaround harmful rather than merely
     * useless. Composer looks for a lock named after the manifest it was
     * given, so exporting COMPOSER=.pa-runtime-composer.json hid the
     * project's composer.lock behind a name nothing writes. Composer then did
     * not install from the lock at all -- it announced
     * "No composer.lock file present. Updating dependencies to latest
     * instead" and resolved the whole graph remotely, reaching the VCS
     * repositories a manifest may declare and dying on GitHub's
     * unauthenticated rate limit. espocrm (2 VCS repositories) and
     * dreamfactory (11) both failed that way, on projects that shipped a
     * 375KB and a 565KB lock respectively.
     *
     * The workaround also cannot be needed here: the advisory rule it dodges
     * is applied while resolving, and an install from a lock resolves
     * nothing. So islandora -- composer.json and no composer.lock -- keeps
     * the manifest, and everything with a lock keeps its lock -- as long as
     * nothing else needs the manifest; {@see
     * test_a_lock_gets_the_manifest_once_a_pin_needs_somewhere_to_land()} is
     * the case where something does.
     */
    public function test_a_project_that_ships_a_lock_resolves_from_it_not_from_a_manifest(): void
    {
        $manifest = <<<'JSON'
        {
            "name": "espocrm/espocrm",
            "require": { "cboden/ratchet": "0.4.x-dev" },
            "require-dev": { "phpunit/phpunit": "^6" }
        }
        JSON;

        // Without a lock the manifest is still what gets written -- the
        // require-dev that can block an unlocked resolve is still dropped.
        $this->assertNotNull(PhpHostBuild::runtimeManifest($manifest));

        // With one and no pin to write, nothing is written and Composer
        // keeps composer.json, which is the name its lock is filed under.
        $this->assertNull(PhpHostBuild::runtimeManifest($manifest, '{"packages": []}'));
        $this->assertNull(PhpHostBuild::runtimeManifest($manifest, '{}'));
    }

    /**
     * A lock stops protecting composer.json the moment a platform pin needs
     * writing: `composer config` writes wherever `COMPOSER` points, and left
     * unset that is composer.json -- exactly the file ADR-0001 says the
     * engine may never write. So a lock with a pin still gets the manifest,
     * carrying composer.json byte for byte (there is nothing to drop; the
     * lock is what `install` reads), on the understanding that the caller
     * copies composer.lock in beside it under the matching engine name
     * before Composer ever sees `COMPOSER` pointed elsewhere -- see
     * {@see \App\System\Project\Dind\HostCompile}.
     */
    public function test_a_lock_gets_the_manifest_once_a_pin_needs_somewhere_to_land(): void
    {
        $manifest = '{"require": {"php": "^8.4", "psr/log": "^3"}}';
        $lock = '{"packages": []}';

        $runtime = PhpHostBuild::runtimeManifest($manifest, $lock, '8.4');

        $this->assertSame($manifest, $runtime, 'the manifest travels unchanged -- there is nothing to drop');
    }

    /** The other half of the same case: no minor to pin, so the lock still needs no manifest. */
    public function test_a_lock_with_no_pin_to_write_still_needs_no_manifest(): void
    {
        $manifest = '{"require": {"php": "^8.4"}}';
        $lock = '{"packages": []}';

        $this->assertNull(PhpHostBuild::runtimeManifest($manifest, $lock, null));
        $this->assertNull(PhpHostBuild::runtimeManifest($manifest, $lock, '8.4.25'));
    }

    /**
     * An empty or absent lock is not a lock. composer.lock is a tracked file
     * in some projects and empty in none worth believing, but the read is a
     * file that may not exist, and null is how that arrives.
     */
    public function test_an_absent_or_empty_lock_still_gets_the_manifest(): void
    {
        $manifest = '{"require": {"psr/log": "^1.0"}, "require-dev": {"phpunit/phpunit": "^6"}}';

        $this->assertNotNull(PhpHostBuild::runtimeManifest($manifest, null));
        $this->assertNotNull(PhpHostBuild::runtimeManifest($manifest, ''));
        $this->assertNotNull(PhpHostBuild::runtimeManifest($manifest, '   '));
        $this->assertNotNull(PhpHostBuild::runtimeManifest($manifest, "\n"));
    }

    public function test_a_manifest_that_is_not_one_is_refused_rather_than_guessed_at(): void
    {
        // A manifest rebuilt from a file we could not parse would resolve a
        // different tree; the caller falls back to composer.json instead.
        foreach (['', 'not json', '[]', '"a string"', '{"require": "not an object"}'] as $broken) {
            $this->assertNull(PhpHostBuild::runtimeManifest($broken), $broken);
        }
    }

    public function test_only_the_declared_half_is_emitted(): void
    {
        $this->assertStringNotContainsString('assets', PhpHostBuild::script('composer install', ''));
        $this->assertStringNotContainsString('dependencies', PhpHostBuild::script('', 'php compile.php'));
    }

    /**
     * The pin is shared with the Composer-image pass rather than spelled
     * twice, so this is the one place it is asserted -- `.99` and all.
     */
    public function test_the_platform_pin_is_the_engine_minor_and_any_patch_of_it(): void
    {
        $this->assertSame(
            'composer config --no-plugins platform.php 8.4.99',
            PhpHostBuild::platformPin('8.4')
        );
    }

    /**
     * The value reaches here from the customer's own composer.json and ends up
     * inside a shell command. Anything not shaped like a minor contributes no
     * pin at all rather than being pasted in.
     */
    public function test_a_version_that_is_not_a_minor_contributes_no_pin(): void
    {
        foreach ([null, '', '8', '8.4.25', '$(id)', '8.4; rm -rf /'] as $notAMinor) {
            $this->assertSame('', PhpHostBuild::platformPin($notAMinor), var_export($notAMinor, true));
        }
    }

    /**
     * The bug that motivated this: the engine selects the PHP minor from
     * composer.json, builds its base image, and then the project's own
     * `config.platform` decided the resolve. YOURLS pins `8.1.0` while
     * requiring `^8.4`; the deploy built 8.4 and failed with "this project
     * needs PHP ^8.4, but it was built with PHP 8.1.0; overridden via
     * config.platform, actual: 8.4.25". htmly ships the same shape at 7.2.
     *
     * `--no-file` is the safe half of the fix: composer config inherits the
     * COMPOSER manifest, so the pin is written into the runtime manifest the
     * resolve reads and never into the customer's composer.json.
     */
    public function test_a_project_pinning_its_own_php_still_resolves_for_the_engines_minor(): void
    {
        $script = PhpHostBuild::script('composer install --no-dev', '', true, '8.4');

        $this->assertStringContainsString('composer config --no-plugins platform.php 8.4.99', $script);
        $this->assertStringNotContainsString('--file', $script);
        $this->assertStringNotContainsString('--ignore-platform-reqs', $script);
        // The pin has to be written before the resolve reads the platform.
        $this->assertLessThan(
            strpos($script, 'composer install'),
            strpos($script, 'composer config'),
            'the platform has to be pinned before composer reads it'
        );
    }

    /**
     * osTicket vendors its dependencies and ships no composer.json, so the
     * pin has nothing to resolve against and no minor to resolve for.
     */
    public function test_no_install_means_no_pin(): void
    {
        $this->assertSame('', PhpHostBuild::script('', '', false, '8.4'));
    }

    /**
     * A project with an asset step and no Composer still gets no pin: the pin
     * belongs to the dependency step, and there is no Composer to configure.
     */
    public function test_an_asset_only_script_carries_no_pin(): void
    {
        $script = PhpHostBuild::script('', 'php compile.php', false, '8.4');

        $this->assertStringContainsString('build: assets', $script);
        $this->assertStringNotContainsString('platform.php', $script);
    }

    /**
     * An app whose declared install is empty but ships Composer gets the
     * default install -- and the pin that makes it answerable to the image
     * the engine chose.
     */
    public function test_the_default_install_is_pinned_too(): void
    {
        $script = PhpHostBuild::script('', '', true, '8.4');

        $this->assertStringContainsString(PhpHostBuild::DEFAULT_INSTALL, $script);
        $this->assertStringContainsString('composer config --no-plugins platform.php 8.4.99', $script);
        // The marker survives the extra step, so the deploy log still shows
        // where the dependency step began.
        $this->assertStringContainsString('echo "[panelalpha] build: dependencies" >&2', $script);
    }

    /**
     * A lock whose own packages reject the PHP it pins cannot be installed
     * strictly, and `install` is not allowed to rewrite what the lock pinned.
     * egroupware is the shape: its CI locks under `--ignore-platform-reqs`,
     * so `platform-overrides` says 8.2 while the locked set needs 8.4 and
     * Composer answers "your lock file does not contain a compatible set of
     * packages". Ignoring the one requirement the lock contradicts installs
     * the locked set as-is -- 185 packages, verified against the real lock.
     *
     * `php` only, and never the blanket `--ignore-platform-reqs`: extensions
     * stay enforced, because a genuinely absent extension is a deploy that
     * must still fail rather than one that starts with no vendor/.
     */
    public function test_a_self_contradicting_lock_installs_with_php_ignored(): void
    {
        $script = PhpHostBuild::script('composer install --no-dev', '', true, '8.2', true);

        $this->assertStringContainsString('--ignore-platform-req=php', $script);
        $this->assertStringNotContainsString('--ignore-platform-reqs', $script);
        // Still pinned, so the resolve the install is measured against is the
        // image the account was built on.
        $this->assertStringContainsString('composer config --no-plugins platform.php 8.2.99', $script);
        // And said out loud, or the only trace is Composer's own warning.
        $this->assertStringContainsString(PhpHostBuild::lockContradictionNote(), $script);
    }

    /** The escape hatch belongs to a contradicted lock, not to every deploy. */
    public function test_a_consistent_lock_is_installed_strictly(): void
    {
        $script = PhpHostBuild::script('composer install --no-dev', '', true, '8.2');

        $this->assertStringNotContainsString('--ignore-platform-req', $script);
        $this->assertStringNotContainsString(PhpHostBuild::lockContradictionNote(), $script);
    }

    /** An asset-only step installs nothing, so there is nothing to relax. */
    public function test_a_contradicted_lock_relaxes_no_asset_only_step(): void
    {
        $script = PhpHostBuild::script('', 'php compile.php', false, '8.2', true);

        $this->assertStringNotContainsString('--ignore-platform-req', $script);
        $this->assertStringContainsString('build: assets', $script);
    }

    /**
     * Null -- no composer.json, so no minor -- leaves the install exactly as
     * the manifest declared it, which is what every other runtime does.
     */
    public function test_with_no_minor_the_install_is_left_exactly_as_declared(): void
    {
        $this->assertSame(
            "echo \"[panelalpha] build: dependencies\" >&2\ncomposer install --no-dev",
            PhpHostBuild::script('composer install --no-dev', '', true)
        );
    }

    /**
     * The application's root inside the repository is the manifest's to
     * declare; the build has to work in the same place the compose file
     * mounts, or composer resolves a composer.json nobody will run.
     */
    public function test_the_working_directory_follows_the_manifests_app_root(): void
    {
        $this->assertSame('/app', PhpHostBuild::workingDir(''));
        $this->assertSame('/app/phpBB', PhpHostBuild::workingDir('phpBB'));
        $this->assertSame('/app/phpBB', PhpHostBuild::workingDir('/phpBB/'));
    }

    public function test_the_cache_is_per_account(): void
    {
        $this->assertSame(
            '/var/cache/panelalpha/projects/alice/composer',
            PhpHostBuild::cacheDirFor('alice')
        );
        $this->assertNotSame(
            PhpHostBuild::cacheDirFor('alice'),
            PhpHostBuild::cacheDirFor('bob')
        );
    }

    public function test_a_username_that_is_not_one_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PhpHostBuild::cacheDirFor('../../etc');
    }

    /**
     * The container writes into the account's directory as the account, and
     * composer that cannot write a home directory stops before it starts.
     */
    public function test_composer_is_pointed_at_a_writable_home_and_the_mounted_cache(): void
    {
        $env = PhpHostBuild::environment();

        $this->assertSame('/tmp/composer', $env['COMPOSER_HOME']);
        $this->assertSame(PhpBaseImage::COMPOSER_CACHE_DIR, $env['COMPOSER_CACHE_DIR']);
    }

    /**
     * The application's compose file is run by the account's nested daemon,
     * which resolves bind-mount sources inside the account container. Nothing
     * mounts /var/cache/panelalpha in there, so the running container's cache
     * has to live under the account home -- the one path both daemons agree
     * on -- or the daemon creates it as root and composer cannot write it.
     */
    public function test_the_container_cache_lives_under_the_account_home(): void
    {
        $this->assertSame(
            '/home/alice/.cache/composer',
            PhpHostBuild::accountCacheDirFor('/home/alice')
        );
        $this->assertSame(
            '/home/alice/.cache/composer',
            PhpHostBuild::accountCacheDirFor('/home/alice/')
        );
    }

    public function test_the_container_cache_is_not_the_host_build_cache(): void
    {
        $this->assertNotSame(
            PhpHostBuild::cacheDirFor('alice'),
            PhpHostBuild::accountCacheDirFor('/home/alice')
        );
        $this->assertStringStartsNotWith(
            ProjectCache::ROOT,
            PhpHostBuild::accountCacheDirFor('/home/alice')
        );
    }

    #[DataProvider('notAHomeDirectory')]
    public function test_a_home_directory_that_is_not_one_is_refused(string $home): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PhpHostBuild::accountCacheDirFor($home);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notAHomeDirectory(): array
    {
        return [
            'traversal' => ['/home/../etc'],
            'trailing traversal' => ['/home/alice/..'],
            'relative' => ['home/alice'],
            'empty' => [''],
            'root' => ['/'],
            'space' => ['/home/al ice'],
        ];
    }
}
