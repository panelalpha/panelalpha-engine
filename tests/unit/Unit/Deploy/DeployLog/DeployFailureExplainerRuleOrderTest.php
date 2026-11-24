<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use PHPUnit\Framework\TestCase;

/**
 * The rule table's order is behaviour: the first rule that matches names the failure. These pin the order and the
 * branches of a few rules no other test reaches.
 */
class DeployFailureExplainerRuleOrderTest extends TestCase
{
    public function test_rules_are_tried_in_this_order(): void
    {
        $this->assertSame([
            'host-namespaces-unavailable',
            'disk-limit-reached',
            'build-stalled',
            'clone-timed-out',
            'submodule-fetch-failed',
            'build-timed-out',
            'step-timed-out',
            'dependency-failed',
            'new-version-empty-page',
            'sysbox-not-running',
            'sysbox-fs-connection-lost',
            'go-toolchain-too-old',
            'java-release-too-new',
            'java-project-build-failed',
            'dotnet-windows-only',
            'rust-build-tool-missing',
            'native-library-headers-missing',
            'php-version-mismatch',
            'php-extension-missing',
            'composer-package-not-found',
            'composer-unresolvable',
            'node-engine-mismatch',
            'disk-full',
            'owner-id-out-of-range',
            'java-heap-space',
            'node-heap-space',
            'out-of-memory',
            'java-plugin-goal-failed',
            'java-license-check-failed',
            'native-build-interrupted',
            'rust-build-script-failed',
            'rust-compile-failed',
            'registry-rate-limited',
            'layer-digest-mismatch',
            'image-reference-invalid',
            'build-dns-unavailable',
            'bind-file-missing',
            'container-entrypoint-missing',
            'container-entrypoint-not-executable',
            'container-start-failed',
            'stage-mount-refused',
            'build-daemon-fault',
            'build-arg-unset',
            'base-image-unauthorized',
            'base-image-rebuild-failed',
            'base-image-unavailable',
            'package-archive-gone',
            'native-build-headers-download-failed',
            'webpack4-openssl-unsupported',
            'bun-native-addon-failed',
            'native-build-toolchain-missing',
            'missing-embedded-assets',
            'go-cgo-required',
            'go-package-excluded',
            'go-entrypoint-not-found',
            'bind-source-missing',
            'env-validation-failed',
            'database-auth-failed',
            'missing-build-script',
            'angular-project-ambiguous',
            'npm-lockfile-out-of-sync',
            'npm-dependency-tree-crash',
            'dependency-conflict',
            'static-build-several-sites',
            'git-binary-missing',
            'prepare-script-failed',
            'git-exec-failed',
            'bun-lockfile-outdated',
            'bun-lockfile-frozen',
            'bun-frozen-install-failed',
            'missing-package-at-runtime',
            'dependency-not-found',
            'python-non-package-poetry',
            'dockerfile-no-from',
            'install-error-line',
            'repo-read-interrupted',
            'repo-auth-failed',
            'repo-not-found',
            'build-arg-guard-unset',
            'build-context-missing',
            'ruby-gem-not-loaded',
            'build-step-failed',
        ], DeployFailureExplainer::ruleIds());
    }

    public function test_a_pull_refused_without_a_login_hint_is_a_private_base_image(): void
    {
        $this->assertSame([
            'rule' => 'base-image-unavailable',
            'message' => 'The base image acme/widget could not be downloaded: its registry refused access, so it is private '
                .'or needs credentials this server does not have.',
        ], DeployFailureExplainer::match("#5 ERROR: pull access denied for acme/widget\n"));
    }

    public function test_a_registry_error_of_no_known_kind_names_every_possibility(): void
    {
        $this->assertSame([
            'rule' => 'base-image-unavailable',
            'message' => 'The base image foo:1 could not be downloaded — it may not exist, may be private, or its registry '
                .'may be unreachable from here.',
        ], DeployFailureExplainer::match("failed to resolve source metadata for docker.io/library/foo:1: something odd happened\n"));
    }

    public function test_a_missing_linker_without_fuse_ld_names_the_configured_one(): void
    {
        $this->assertSame([
            'rule' => 'rust-build-tool-missing',
            'message' => 'The Rust build needs the linker the project selects in .cargo/config.toml and the build image '
                .'does not have it. The full output is in the deploy log.',
        ], DeployFailureExplainer::match("error: linking with `cc` failed\ncollect2: fatal error: cannot find 'ld'\n"));
    }

    public function test_a_bare_killed_line_is_the_host_build_running_out_of_memory(): void
    {
        $this->assertSame([
            'rule' => 'out-of-memory',
            'message' => 'The build ran out of memory in the engine\'s build container, which is sized for the server '
                .'(DEPLOY_BUILD_MEMORY, 8 GB or half its RAM by default), not by the plan. Raising the project\'s '
                .'memory limit does not change it. The full build output is in the deploy log.',
        ], DeployFailureExplainer::match("npm run build\nKilled\n"));
    }

    public function test_a_one_shot_that_exited_zero_is_no_failure(): void
    {
        $this->assertNull(DeployFailureExplainer::match("container app-init-1 exited (0)\n"));
    }

    public function test_an_out_of_sync_lockfile_without_npm_detail_says_so_plainly(): void
    {
        $this->assertSame([
            'rule' => 'npm-lockfile-out-of-sync',
            'message' => 'The project\'s package-lock.json does not match its package.json, and `npm ci` installs only '
                .'from a lockfile that does. Regenerate it with `npm install` and commit it.',
        ], DeployFailureExplainer::match(
            "npm error `npm ci` can only install packages when your package.json and package-lock.json are in sync.\n"
        ));
    }
}
