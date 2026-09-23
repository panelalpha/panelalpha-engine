<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeployFailureExplainerTest extends TestCase
{
    /** The failure that made github.com/henrygd/beszel fail on a live host. */
    public function test_explains_a_too_old_go_toolchain(): void
    {
        $output = <<<'OUT'
#9 1.267 Executing busybox-1.37.0-r30.trigger
#9 1.274 OK: 20.4 MiB in 29 packages
#9 1.345 go: go.mod requires go >= 1.26.6 (running go 1.24.13; GOTOOLCHAIN=local)
#9 ERROR: process "/bin/sh -c apk add --no-cache git && go build -o app ." did not complete successfully: exit code: 1
OUT;

        $this->assertSame(
            'This project needs Go 1.26.6, but it was built with Go 1.24.13.',
            DeployFailureExplainer::explain($output)
        );
    }

    public function test_prefers_the_specific_cause_over_the_generic_exit_code(): void
    {
        $output = "no space left on device\n"
            . 'failed to solve: process "/bin/sh -c npm ci" did not complete successfully: exit code: 1';

        $this->assertStringContainsString('disk space', (string) DeployFailureExplainer::explain($output));
    }

    /**
     * DeployLogger appends the ENOSPC hint only when the disk is really full,
     * so an ownership failure with the same prefix stays unexplained instead
     * of telling the customer to buy a bigger plan.
     */
    public function test_explains_a_failed_deploy_log_write_only_when_it_is_enospc(): void
    {
        $prefix = 'Could not pre-pull opensearchproject/opensearch:2: '
            . 'Could not write deploy log file: /var/www/html/storage/logs/deploy/shopware/latest.json.tmp.619284';

        $this->assertNull(DeployFailureExplainer::explain($prefix));
        $this->assertStringContainsString(
            'disk space',
            (string) DeployFailureExplainer::explain($prefix . ': no space left on device')
        );
    }

    #[DataProvider('failureProvider')]
    public function test_recognises_common_failures(string $output, string $expectedFragment): void
    {
        $this->assertStringContainsString(
            $expectedFragment,
            (string) DeployFailureExplainer::explain($output)
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failureProvider(): array
    {
        return [
            'out of memory' => ['runc: process exited: signal: killed, exit code: 137', 'out of memory'],
            'hub rate limit' => ['toomanyrequests: You have reached your pull rate limit', 'rate limit'],
            'missing image' => ['manifest for golang:1.99-alpine not found', 'could not be downloaded'],
            'no build script' => ['npm ERR! Missing script: "build"', 'no "build" script'],
            'dependency conflict' => ['npm ERR! code ERESOLVE', 'dependencies conflict'],
            'private repo' => ['fatal: could not read Username for https://github.com', 'access token'],
            'missing repo' => ['fatal: repository https://github.com/x/y not found', 'was not found'],
            'php too old' => [
                'requires php ^8.4 but your php version (8.1.2) does not satisfy',
                'needs PHP ^8.4',
            ],
            'generic' => [
                'failed to solve: process "/bin/sh -c make" did not complete successfully: exit code: 2',
                'exit code 2',
            ],
            'outdated bun lockfile' => [
                "error parsing lockfile: Outdated lockfile version\n"
                . 'failed to solve: process "/bin/sh -c bun install --frozen-lockfile" did not complete successfully: exit code: 1',
                'Bun lockfile',
            ],
            'frozen lockfile drift' => [
                "error: lockfile had changes, but lockfile is frozen\n"
                . 'failed to solve: process "/bin/sh -c bun install --frozen-lockfile" did not complete successfully: exit code: 1',
                'frozen install',
            ],
            'frozen bun from dockerfile snippet only' => [
                "WARNING: current commit information was not captured by the build\n"
                . "------\n"
                . " > [2/3] RUN bun install --frozen-lockfile:\n"
                . "------\n"
                . 'failed to solve: process "/bin/sh -c bun install --frozen-lockfile" did not complete successfully: exit code: 1',
                'frozen lockfile',
            ],

            // The Java triage. Every one of these is real output from an
            // app-support deploy, and in each the line the deploy summary used
            // to quote -- `mkdir: cannot create directory '/root'` from the
            // Maven entrypoint, or apt's `List directory ... Permission denied`
            // -- was on stderr ahead of the real cause and said nothing about
            // it.
            'java release newer than the JDK' => [
                '[ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin:3.11.0:compile'
                . " (default-compile) on project data: Fatal error compiling: error: release version 25 not supported\n"
                . '[ERROR] To see the full stack trace of the errors, re-run Maven with the -e switch.',
                'Java 25',
            ],
            'maven frontend goal failed' => [
                '[ERROR] Failed to execute goal com.github.eirslett:frontend-maven-plugin:1.15.0:pnpm'
                . " (pnpm-build) on project keycloak-js-parent: Failed to run task: 'pnpm build' failed.",
                'frontend step',
            ],
            'maven missing child module' => [
                '[ERROR]   The project io.onedev:server:16.6.2 (/app/pom.xml) has 1 error'
                . "[ERROR]     Child module /app/server-ee/pom.xml of /app/pom.xml does not exist",
                'server-ee',
            ],
            'maven unresolvable parent pom' => [
                '[FATAL] Non-resolvable parent POM for org.xwiki.platform:xwiki-platform:18.8.0-SNAPSHOT:'
                . ' The following artifacts could not be resolved: org.xwiki.commons:xwiki-commons-pom:pom:18.8.0-SNAPSHOT (absent)',
                'could not assemble',
            ],
            'rust crate needs pkg-config' => [
                '  The pkg-config command could not be found',
                'development headers',
            ],
            'rust crate needs libclang' => [
                'Unable to find libclang: "couldn\'t find any valid shared libraries matching: [\'libclang.so\']"',
                'development headers',
            ],
            'rust crate names itself' => [
                'error: failed to run custom build command for `openssl-sys v0.9.117`',
                'openssl-sys',
            ],
            'rust source does not compile' => [
                'error: could not compile `portable-atomic` (build script) due to 1 previous error',
                'portable-atomic',
            ],
        ];
    }

    /**
     * The decoy must not win.
     *
     * A Maven host compile runs the `maven:` image's own entrypoint as the
     * account, and that entrypoint's `mkdir -p /root/.m2/repository` fails and
     * prints to *stderr* before Maven runs. The deploy runner quotes the first
     * line of stderr, so this is what the stored failure read for twelve Java
     * apps -- while the real cause was a Maven goal three screens later.
     */
    public function test_a_maven_entrypoint_denial_does_not_mask_the_real_cause(): void
    {
        $output = <<<'OUT'
        mkdir: cannot create directory '/root': Permission denied
        [INFO] Scanning for projects...
        [ERROR] Failed to execute goal com.github.eirslett:frontend-maven-plugin:1.15.0:pnpm (pnpm-build) on project keycloak-js-parent: Failed to run task: 'pnpm build' failed.
        OUT;

        $this->assertStringContainsString(
            'frontend step',
            (string) DeployFailureExplainer::explain($output)
        );
    }

    /**
     * npm prints its own error object on any failed script, and one of its
     * fields is `killed`. A bare `\bKilled\b` matched the *false* value, so the
     * deploy was filed as out of memory and the customer was sent after a
     * bigger plan.
     *
     * espocrm is the case: this is its real output, and the build had died in
     * `phantomjs-prebuilt`'s `node install.js`, which has nothing to do with
     * memory. `signal: null` sits beside it for the same reason.
     */
    public function test_npms_own_error_object_is_not_a_kill(): void
    {
        $output = <<<'OUT'
        npm error npm error   code: 2,
        npm error npm error   killed: false,
        npm error npm error   signal: null,
        npm error npm error   cmd: 'tar jxf /tmp/phantomjs-prebuilt'
        OUT;

        $this->assertNotSame('out-of-memory', DeployFailureExplainer::match($output)['rule'] ?? null);
    }

    /**
     * ...while every form a real kill actually takes still matches: the
     * kernel's, npm's, Docker's and BuildKit's.
     */
    public function test_a_genuine_kill_is_still_out_of_memory(): void
    {
        foreach ([
            'runc: process exited: signal: killed',
            'Memory cgroup out of memory: Killed process 4242 (webpack)',
            'Out of memory: Killed process 1234 (node)',
            'State: OOMKilled',
            'did not complete successfully: exit code: 137',
        ] as $output) {
            $this->assertSame('out-of-memory', DeployFailureExplainer::match($output)['rule'] ?? null, $output);
        }
    }

    /**
     * ...and the same for Rust, where the decoy is apt's permission error from
     * a `systemPackages()` best effort that runs as the account.
     */
    public function test_an_apt_denial_does_not_mask_a_rust_crate_failure(): void
    {
        $output = <<<'OUT'
        E: List directory /var/lib/apt/lists/partial is missing. - Acquire (13: Permission denied)
        error: failed to run custom build command for `rust-librocksdb-sys v0.39.0+10.5.1`
        Caused by:
        process didn't exit successfully: `/app/target/release/build/rust-librocksdb-sys/build-script-build`
        OUT;

        $this->assertStringContainsString(
            'rust-librocksdb-sys',
            (string) DeployFailureExplainer::explain($output)
        );
    }

    /**
     * The real beszel failure: BuildKit echoes the failing RUN instruction,
     * which contains our own marker text. Matching that would report "no
     * runnable program" when the truth is that embedded assets are missing.
     */
    public function test_does_not_mistake_the_echoed_dockerfile_for_build_output(): void
    {
        $output = <<<'OUT'
#9 10.97 internal/site/embed.go:9:12: pattern all:dist: no matching files found
#9 ERROR: process "/bin/sh -c apk add --no-cache git && go build -o app ./internal/cmd/hub" did not complete successfully
------
 > [4/4] RUN apk add --no-cache git && go build -o app ./internal/cmd/hub && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }:
10.97 internal/site/embed.go:9:12: pattern all:dist: no matching files found
------
panelalpha.Dockerfile:7
   7 | >>> RUN apk add --no-cache git && go build -o app ./internal/cmd/hub && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }
failed to solve: process "/bin/sh -c apk add ..." did not complete successfully: exit code: 1
OUT;

        $explanation = (string) DeployFailureExplainer::explain($output);

        $this->assertStringContainsString('embeds files', $explanation);
        $this->assertStringNotContainsString('No runnable program', $explanation);
    }

    public function test_reports_a_missing_entrypoint_from_real_build_output(): void
    {
        $output = "#9 0.412 PANELALPHA: no runnable Go program found\n"
            . 'failed to solve: process "/bin/sh -c go build" did not complete successfully: exit code: 1';

        $this->assertStringContainsString(
            'No runnable program was found',
            (string) DeployFailureExplainer::explain($output)
        );
    }

    public function test_says_nothing_when_it_recognises_nothing(): void
    {
        $this->assertNull(DeployFailureExplainer::explain('something entirely unfamiliar happened'));
        $this->assertNull(DeployFailureExplainer::explain('   '));
    }

    public function test_it_explains_a_placeholder_setting_the_app_rejected(): void
    {
        $output = 'The Environment variables has failed the following validations: '
            . '{"isNotIn":"APP_SECRET should not be one of the following values: REPLACE_WITH_LONG_SECRET"}';

        $this->assertStringContainsString(
            'placeholder value',
            (string) DeployFailureExplainer::explain($output)
        );
    }

    public function test_it_explains_a_database_password_left_over_from_an_earlier_deploy(): void
    {
        $output = 'PostgresError: password authentication failed for user "docmost"';

        $this->assertStringContainsString(
            'could not sign in to its database',
            (string) DeployFailureExplainer::explain($output)
        );
    }

    /**
     * The failure that made github.com/nextcloud/server report a Node version
     * nothing in it asks for. npm prints EBADENGINE as a *warning* and carries
     * on installing; the range in it belongs to a transitive dependency, not
     * to the project. The build failed for its own reasons and this rule put
     * an impossible sentence on it.
     */
    public function test_ignores_the_node_engine_range_in_an_npm_warning(): void
    {
        $output = <<<'OUT'
npm warn EBADENGINE Unsupported engine {
npm warn EBADENGINE   package: '@nextcloud/calendar-availability-vue@3.0.0',
npm warn EBADENGINE   required: { node: '^22.0.0', npm: '^10.5.0' },
npm warn EBADENGINE   current: { node: 'v24.20.0', npm: '11.19.0' }
npm warn EBADENGINE }
OUT;

        $this->assertNull(DeployFailureExplainer::explain($output));
    }

    /** engine-strict, where npm really does refuse, still gets named. */
    public function test_explains_a_node_engine_mismatch_npm_treated_as_an_error(): void
    {
        $output = <<<'OUT'
npm error code EBADENGINE
npm error engine Unsupported engine
npm error engine   required: { node: '^22.0.0' }
npm error engine   current: { node: 'v24.20.0' }
OUT;

        $this->assertSame(
            'This project needs Node ^22.0.0, which does not match the version used to build it.',
            DeployFailureExplainer::explain($output)
        );
    }

    /**
     * A real npm error earlier in the log must not reach forward and borrow
     * the range out of an unrelated warning further down.
     */
    public function test_does_not_borrow_a_range_from_a_later_warning(): void
    {
        $output = <<<'OUT'
npm error engine Unsupported engine for some-package
npm warn EBADENGINE Unsupported engine {
npm warn EBADENGINE   required: { node: '^20.0.0' },
npm warn EBADENGINE }
OUT;

        $this->assertNull(DeployFailureExplainer::explain($output));
    }

    /**
     * Pelican Panel's compose builds `FROM localhost:5000/base-php`, a
     * registry that exists only inside its own CI. Huginn asked for
     * `ruby:3.4.0-slim-bookworm`, a patch tag Docker Hub has pruned. Neither
     * says "manifest unknown", so both fell through every rule to the generic
     * fallback and were reported as `app_did_not_start` -- sending the reader
     * to container logs for a container that was never built.
     */
    public function test_an_unreachable_registry_is_a_base_image_problem(): void
    {
        $output = 'failed to solve: localhost:5000/base-php:amd64: failed to do request: '
            . 'Head "https://localhost:5000/v2/base-php/manifests/amd64": '
            . 'dial tcp [::1]:5000: connect: connection refused';

        $this->assertSame('base-image-unavailable', DeployFailureExplainer::match($output)['rule']);
    }

    public function test_a_pruned_tag_is_a_base_image_problem(): void
    {
        $output = 'failed to solve: failed to resolve source metadata for '
            . 'docker.io/library/ruby:3.4.0-slim-bookworm: not found';

        $this->assertSame('base-image-unavailable', DeployFailureExplainer::match($output)['rule']);
    }

    /**
     * The registry rules are worded for BuildKit, not for the phrase alone --
     * an application failing to reach its own database says "Connection
     * refused" too, and must not be reported as a missing base image.
     */
    public function test_an_application_connection_error_is_not_a_registry_error(): void
    {
        $match = DeployFailureExplainer::match('SQLSTATE[HY000] [2002] Connection refused');

        $this->assertNotSame('base-image-unavailable', $match['rule'] ?? null);
    }

    /** Rate limiting is still its own answer, ranked above the general rule. */
    public function test_rate_limiting_still_wins(): void
    {
        $output = 'toomanyrequests: You have reached your pull rate limit';

        $this->assertSame('registry-rate-limited', DeployFailureExplainer::match($output)['rule']);
    }

    /** Foodsoft (engine#285): the image exists, a layer came back corrupted from the mirror. */
    public function test_a_layer_digest_mismatch_is_not_a_missing_base_image(): void
    {
        $output = 'failed commit on ref "layer-sha256:5c1e0a5b4f2b": commit failed: unexpected commit digest '
            . 'sha256:e3b0c44298fc, expected sha256:5c1e0a5b4f2b: failed precondition';

        $match = DeployFailureExplainer::match($output);

        $this->assertSame('layer-digest-mismatch', $match['rule'] ?? null);
        $this->assertStringContainsString('corrupted', $match['message']);
        $this->assertStringContainsString('retrying', $match['message']);
    }

    /**
     * Leon's install died on `better-sqlite3` and Supabase's on `node-pty`,
     * both with 4KB of gyp output and no rule to match it -- so the slug fell
     * through to app_did_not_start, which sends the reader to container logs
     * for a container that was never built.
     */
    public function test_a_missing_python_for_node_gyp_is_explained(): void
    {
        $output = "better-sqlite3 install\$ node-gyp rebuild\n"
            . "gyp ERR! find Python checking if \"python3\" can be used\n"
            . "gyp ERR! stack Error: Could not find any Python installation to use\n"
            . '[ELIFECYCLE] Command failed with exit code 1.';

        $match = DeployFailureExplainer::match($output);

        $this->assertSame('native-build-toolchain-missing', $match['rule']);
        $this->assertStringContainsString('compiled during install', $match['message']);
    }

    /** It outranks the generic build failure, being the more specific answer. */
    public function test_it_wins_over_the_generic_build_rule(): void
    {
        $output = "gyp ERR! not ok\n"
            . 'ERROR: process "/bin/sh -c pnpm install" did not complete successfully: exit code: 1';

        $this->assertSame('native-build-toolchain-missing', DeployFailureExplainer::match($output)['rule']);
    }

    /**
     * A duplicate slug is invisible: PHP keeps the last one and the earlier
     * rule simply stops existing, with no error anywhere. `git-binary-missing`
     * was nearly given a second entry, which would have deleted the first.
     */
    public function test_no_rule_slug_is_declared_twice(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../../app/Lib/Deploy/DeployLog/DeployFailureExplainer.php'
        );
        $this->assertIsString($source);

        preg_match_all("/^\s+'([a-z0-9-]+)' => \[/m", $source, $matches);
        $declared = $matches[1];

        $this->assertSame(
            [],
            array_values(array_diff_assoc($declared, array_unique($declared))),
            'a duplicate slug silently replaces the rule above it'
        );
        $this->assertSame(count($declared), count(DeployFailureExplainer::ruleIds()));
    }

    /**
     * tine's failure, which named npm and nothing else. The image is picked
     * from what the project declares, so the reader needs to be told that is
     * what went wrong -- 149 s of build otherwise ends in a message about a
     * dependency.
     */
    public function test_a_missing_git_binary_is_explained(): void
    {
        $output = "npm error code 1\n"
            . "npm error syscall spawn git\n"
            . 'npm error git dep preparation failed';

        $match = DeployFailureExplainer::match($output);

        $this->assertSame('git-binary-missing', $match['rule']);
        $this->assertStringContainsString('git binary', $match['message']);
    }

    /** The shell's wording for the same thing, from a build script. */
    public function test_the_shells_wording_for_a_missing_git_is_explained(): void
    {
        $this->assertSame(
            'git-binary-missing',
            DeployFailureExplainer::match("/bin/sh: 1: git: not found\n")['rule']
        );
    }

    /** An ordinary mention of git is not a missing binary. */
    public function test_a_build_that_merely_runs_git_does_not_match(): void
    {
        $match = DeployFailureExplainer::match('Cloning into \'app\'... git rev-parse HEAD');

        $this->assertNotSame('git-binary-missing', $match['rule'] ?? null);
    }

    /** A build that merely mentions Python is not a toolchain failure. */
    public function test_an_ordinary_python_mention_does_not_match(): void
    {
        $match = DeployFailureExplainer::match('Successfully installed Python packages');

        $this->assertNotSame('native-build-toolchain-missing', $match['rule'] ?? null);
    }

    /**
     * The real Alfresco Community deploy (#1117): Maven ran out of heap in
     * the host build container, reported `Java heap space -> [Help 1]`, and
     * the deploy was then filed under `build-step-failed` quoting the maven
     * image's own entrypoint warning -- `mkdir: cannot create directory
     * '/root'` -- which prints before Maven starts and has nothing to do with
     * the failure. The rule must win over both.
     */
    public function test_explains_a_maven_java_heap_failure_not_the_entrypoint_warning(): void
    {
        $output = <<<'OUT'
Can not write to /root/.m2/copy_reference_file.log. Wrong volume permissions? Carrying on ...
mkdir: cannot create directory ‘/root’: Permission denied
[INFO] Scanning for projects...
[INFO] ------------------------------------------------------------------------
[INFO] Reactor Summary for Alfresco Community Repo Parent 26.3.0.48-SNAPSHOT:
[INFO]
[INFO] Alfresco Community Repo Parent ..................... SUCCESS [ 50.360 s]
[INFO] Alfresco Core ...................................... SUCCESS [ 59.671 s]
[INFO] Alfresco Data Model ................................ SUCCESS [ 52.581 s]
[INFO] Alfresco Repository ................................ SKIPPED
[INFO] ------------------------------------------------------------------------
[INFO] BUILD SUCCESS
[INFO] ------------------------------------------------------------------------
[INFO] Total time:  07:34 min
[INFO] ------------------------------------------------------------------------
[ERROR] Java heap space -> [Help 1]
[ERROR]
[ERROR] To see the full stack trace of the errors, re-run Maven with the -e switch.
[ERROR] [Help 1] http://cwiki.apache.org/confluence/display/MAVEN/OutOfMemoryError
Deploy failed: Failed to start app: mkdir: cannot create directory ‘/root’: Permission denied
OUT;

        $match = DeployFailureExplainer::match($output);

        $this->assertSame('java-heap-space', $match['rule'] ?? null);
        $this->assertStringContainsString('heap', $match['message'] ?? '');
        $this->assertStringNotContainsString('/root', $match['message'] ?? '');
    }

    /**
     * The same failure, said by the JVM rather than by Maven: a Gradle build
     * dumps the stack, and that stack is the first thing in the log, not the
     * last.
     */
    #[DataProvider('javaHeapProvider')]
    public function test_recognises_each_way_a_java_build_says_it_ran_out_of_heap(string $output): void
    {
        $this->assertSame(
            'java-heap-space',
            DeployFailureExplainer::match($output)['rule'] ?? null
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function javaHeapProvider(): array
    {
        return [
            'jvm stack trace' => ["Exception in thread \"main\" java.lang.OutOfMemoryError: Java heap space\n\tat java.base/java.util.Arrays.copyOf(Arrays.java:3537)"],
            'maven help url' => ['[ERROR] Java heap space -> [Help 1]'],
            'gradle daemon cannot start' => ['There is insufficient memory for the Java Runtime Environment to continue.'],
        ];
    }

    /**
     * A cgroup OOM and a heap exhaustion in one log is the heap that has to
     * win: they have different fixes, and the specific one is the useful one.
     */
    public function test_the_heap_rule_outranks_the_generic_out_of_memory_rule(): void
    {
        $output = "java.lang.OutOfMemoryError: Java heap space\n"
            . 'runc: process exited: signal: killed, exit code: 137';

        $this->assertSame('java-heap-space', DeployFailureExplainer::match($output)['rule']);
    }

    /**
     * The same shape for V8, and the same reason it has to be named: the
     * Node form carries no exit 137 and no `Killed`, so before this it was
     * caught by `out-of-memory` — "needs more RAM than the plan allows" —
     * when the real answer is the engine's heap cap. The dub #462 deploy
     * reported exactly this, and the one-line summary it produced named
     * neither Node nor the heap.
     */
    #[DataProvider('nodeHeapProvider')]
    public function test_recognises_each_way_a_node_build_says_it_ran_out_of_heap(string $output): void
    {
        $match = DeployFailureExplainer::match($output);

        $this->assertSame('node-heap-space', $match['rule'] ?? null);
        $this->assertStringContainsString('heap', $match['message'] ?? '');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nodeHeapProvider(): array
    {
        return [
            'capped, at the wall' => ["FATAL ERROR: Reached heap limit Allocation failed - JavaScript heap out of memory"],
            'uncapped, mark-compacts' => ["FATAL ERROR: Ineffective mark-compacts near heap limit Allocation failed - JavaScript heap out of memory"],
            'npm lifecycle wrapper' => ["npm error command sh -c next build\nFATAL ERROR: Ineffective mark-compacts near heap limit Allocation failed - JavaScript heap out of memory"],
        ];
    }

    /**
     * A Node heap failure that also names the kernel needs the heap to win,
     * exactly as the Java one does.
     */
    public function test_the_node_heap_rule_outranks_the_generic_out_of_memory_rule(): void
    {
        $output = "web:build: FATAL ERROR: Ineffective mark-compacts near heap limit Allocation failed - JavaScript heap out of memory\n"
            . 'Deploy failed: Failed to start app: Killed (exit code: 137)';

        $this->assertSame('node-heap-space', DeployFailureExplainer::match($output)['rule']);
    }

    /**
     * Composer changed its wording. Dreeve failed on the newer phrasing and
     * came back as `deploy_failed` -- the "nothing matched" code -- while the
     * engine's own PhpExtensionAvailability quotes that exact sentence as the
     * one it expects to see.
     */
    public function test_a_missing_extension_is_recognised_in_both_composer_phrasings(): void
    {
        $modern = 'Root composer.json requires PHP extension ext-zstd * but it is missing from your system.';
        $older = 'Root composer.json requires ext-zstd * -> it is missing from your system.';

        foreach ([$modern, $older] as $output) {
            $match = DeployFailureExplainer::match($output);

            $this->assertSame('php-extension-missing', $match['rule'] ?? null, $output);
            $this->assertStringContainsString('zstd', $match['message'] ?? '');
        }
    }

    /**
     * ActivityWatch #528. The project is a Poetry application, not a package,
     * so pip asking poetry-core to build a wheel of it can never work. The
     * engine now installs such a project with `poetry install --no-root`, but
     * a project it does not detect still deserves the sentence rather than the
     * raw metadata dump.
     */
    public function test_it_explains_a_poetry_project_that_is_not_a_package(): void
    {
        $output = <<<'OUT'
Preparing metadata (pyproject.toml): started
Preparing metadata (pyproject.toml): finished with status 'error'
  error: subprocess-exited-with-error
  × Preparing metadata (pyproject.toml) did not run successfully.
  │ RuntimeError: Building a package is not possible in non-package mode.
  error: metadata-generation-failed
OUT;

        $match = DeployFailureExplainer::match($output);

        $this->assertSame('python-non-package-poetry', $match['rule'] ?? null);
        $this->assertStringContainsString('Poetry application', $match['message'] ?? '');
        // The specific rule has to beat the generic exit-code fallback.
        $this->assertStringContainsString('no-root', $match['message'] ?? '');
    }

    /**
     * engine#235. A compose that builds the app image to a local tag and has a
     * sibling reference it makes `docker compose up` PULL that tag first: it
     * fails with a benign `failed to resolve reference ... not found`, then
     * builds it (`naming to ... done`). The container then dies on a missing
     * entrypoint -- Limbas (supported-apps#1195) -- and that, not the benign
     * pull, is the cause the reader needs.
     */
    public function test_a_missing_entrypoint_wins_over_the_benign_local_build_pull(): void
    {
        $output = <<<'OUT'
 limbas-local Pulling
 limbas-local Warning  failed to resolve reference "docker.io/library/limbas-local:latest": docker.io/library/limbas-local:latest: not found
#1 [internal] load build definition from panelalpha.Dockerfile
#8 naming to docker.io/library/limbas-local:latest done
 Container limbas-app  Created
 Container limbas-app  Starting
Error response from daemon: failed to create task for container: failed to create shim task: OCI runtime create failed: runc create failed: unable to start container process: exec: "docker-entrypoint.sh": executable file not found in $PATH: unknown
OUT;

        $match = DeployFailureExplainer::match($output);

        $this->assertSame('container-entrypoint-missing', $match['rule'] ?? null);
        $this->assertStringContainsString('docker-entrypoint.sh', $match['message'] ?? '');
        $this->assertStringNotContainsString('base image', (string) DeployFailureExplainer::explain($output));
    }

    /**
     * The same shape with a one-shot exiting non-zero rather than a bad
     * entrypoint -- Bitpoll (supported-apps#1085), whose init container exits 1.
     */
    public function test_a_one_shot_exit_wins_over_the_benign_local_build_pull(): void
    {
        $output = <<<'OUT'
 bitpoll-local Pulling
 bitpoll-local Warning  failed to resolve reference "docker.io/library/bitpoll-local:latest": docker.io/library/bitpoll-local:latest: not found
#12 naming to docker.io/library/bitpoll-local:latest done
 Container bitpoll-init-1  Created
 Container bitpoll-init-1  Starting
 Container bitpoll-init-1  Error
dependency failed to start: container bitpoll-init-1 exited (1)
OUT;

        $match = DeployFailureExplainer::match($output);

        $this->assertSame('container-start-failed', $match['rule'] ?? null);
        $this->assertStringContainsString('exited with code 1', $match['message'] ?? '');
        $this->assertStringNotContainsString('base image', (string) DeployFailureExplainer::explain($output));
    }

    /**
     * A base image that really is missing -- nothing in the log builds that tag
     * (no `naming to`) -- still reads as base-image-unavailable.
     */
    public function test_a_genuinely_missing_base_image_is_still_base_image_unavailable(): void
    {
        $output = <<<'OUT'
 someapp Pulling
 someapp Warning  failed to resolve reference "ghcr.io/acme/someapp:1.4.2": ghcr.io/acme/someapp:1.4.2: not found
Error response from daemon: failed to resolve reference "ghcr.io/acme/someapp:1.4.2": not found
OUT;

        $this->assertSame('base-image-unavailable', DeployFailureExplainer::match($output)['rule'] ?? null);
    }

    /**
     * The benign local-build pull on its own must not claim a missing base
     * image: a lower-ranked generic failure has to surface instead.
     */
    public function test_the_benign_local_build_pull_does_not_mask_a_lower_ranked_failure(): void
    {
        $output = <<<'OUT'
 foo-local Warning  failed to resolve reference "docker.io/library/foo-local:latest": docker.io/library/foo-local:latest: not found
#9 naming to docker.io/library/foo-local:latest done
failed to solve: process "/bin/sh -c some-post-step" did not complete successfully: exit code: 1
OUT;

        $this->assertSame('build-step-failed', DeployFailureExplainer::match($output)['rule'] ?? null);
    }
}
