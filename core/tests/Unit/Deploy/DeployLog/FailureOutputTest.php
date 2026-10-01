<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\FailureOutput;
use PHPUnit\Framework\TestCase;

/**
 * The text a failure sentence is built from.
 *
 * A host build prints thousands of lines, most of them advisory, and the whole
 * stream used to be handed over -- so the headline was whichever recognised
 * line came first. Measured on real deploys, that was an npm deprecation
 * warning three times: galette, glpi and octobercms all reported
 * "npm warn deprecated <package>" as the cause of a build that had failed
 * somewhere else entirely.
 */
class FailureOutputTest extends TestCase
{
    public function test_the_failing_line_leads_not_an_advisory_one(): void
    {
        $output = <<<'OUT'
        npm warn deprecated rimraf@3.0.2: Rimraf versions prior to v4 are no longer supported
        npm warn deprecated glob@7.2.3: Glob versions prior to v9 are no longer supported
        <s> [webpack.Progress] 10% building
        Error: Cannot find module './semantic/tasks/build'
            at Function.Module._resolveFilename (internal/modules/cjs/loader.js:636:15)
        error Command failed with exit code 1.
        OUT;

        $selected = FailureOutput::select($output);

        $this->assertStringStartsWith(
            'Error: Cannot find module',
            $selected,
            'the first line is what a failure sentence leads with'
        );
        $this->assertStringNotContainsString('npm warn deprecated', $selected);
        // What the failure said next is kept; it is usually the detail.
        $this->assertStringContainsString('_resolveFilename', $selected);
    }

    /** mydia's Flutter precache: the tar lines, not BuildKit's summary below them, name the cause. */
    public function test_an_owner_id_out_of_range_leads_the_region(): void
    {
        $output = <<<'OUT'
        #18 141.6 [2/11] Gradle Wrapper                                               46ms
        #18 141.7 /usr/bin/tar: gradlew: Cannot change ownership to uid 397546, gid 5000: Invalid argument
        #18 141.7 /usr/bin/tar: Exiting with failure status due to previous errors
        #18 141.7 Flutter could not download and/or extract https://storage.googleapis.com/gradle-wrapper.tgz. Ensure you have network connectivity.
        #18 141.7 The original exception was: ProcessException: The command failed with exit code 2
        #18 ERROR: process "/bin/sh -c flutter precache --web" did not complete successfully: exit code: 1
        ------
        failed to solve: process "/bin/sh -c flutter precache --web" did not complete successfully: exit code: 1
        OUT;

        $match = DeployFailureExplainer::match(FailureOutput::select($output));

        $this->assertSame('owner-id-out-of-range', $match['rule'] ?? null);
    }

    public function test_the_kernel_writing_npm_warnings_is_the_same_case(): void
    {
        $output = "npm warn deprecated a@1\nnpm warn deprecated b@2\nnpm error code 127\nnpm error path /app\n";

        $this->assertStringStartsWith('npm error code 127', FailureOutput::select($output));
    }

    /** BuildKit's own progress and webpack's per-module lines are the bulk. */
    public function test_progress_and_layer_headers_never_become_the_headline(): void
    {
        $output = <<<'OUT'
        #8 [stage-0 2/4] RUN npm install
        #8 0.123 <s> [webpack.Progress] 10% building 0/1 entries
        <s> [webpack.Progress] 40% building 100/200 modules
        ERROR  Failed to compile with 1 errors
        OUT;

        $this->assertStringStartsWith('ERROR  Failed to compile', FailureOutput::select($output));
    }

    /**
     * A failure nobody has a rule for still has to lead somewhere useful, so
     * the window of the last attempt is kept rather than nothing.
     *
     * The bound that keeps an earlier stage out is the window, not a tail
     * inside it: 80 non-noise lines back is "this attempt", and cutting
     * further than that is what threw away the only line worth reading — see
     * the Composer case below.
     */
    public function test_an_unrecognised_failure_keeps_the_window_of_the_last_attempt(): void
    {
        $output = implode("\n", array_map(static fn (int $i): string => "harmless line {$i}", range(1, 200)))
            . "\nthe thing that actually broke\nand its detail";

        $selected = FailureOutput::select($output);

        $this->assertStringContainsString('the thing that actually broke', $selected);
        $this->assertStringNotContainsString('harmless line 1 ', $selected . ' ', 'an earlier stage is not the failure');
        $this->assertStringNotContainsString('harmless line 100', $selected, 'an earlier stage is not the failure');
    }

    /**
     * The case the tail cut in half.
     *
     * Composer announces a platform failure with `Your requirements could not
     * be resolved` / `Problem 1` / `- … requires ext-zstd … missing from your
     * system`, none of which matches a CAUSE pattern — and then prints a fixed
     * boilerplate tail: "To enable extensions, verify …", one line per loaded
     * ini, and two lines of advice. A six-line tail is exactly that
     * boilerplate, so the explainer was handed text with no cause in it and
     * the customer read a list of .ini paths.
     *
     * Asserted through {@see DeployFailureExplainer}, because the contract
     * that matters is not what `select()` returns but whether what it returns
     * still explains the failure.
     */
    public function test_a_composer_platform_failure_still_explains_itself(): void
    {
        $output = <<<'OUT'
        Loading composer repositories with package information
        Updating dependencies
        Your requirements could not be resolved to an installable set of packages.

          Problem 1
            - root/app requires PHP extension ext-zstd * but it is missing from your system.

        To enable extensions, verify that they are enabled in your .ini files:
            - /usr/local/etc/php/conf.d/docker-php-ext-pcntl.ini
            - /usr/local/etc/php/conf.d/docker-php-ext-sodium.ini
            - /usr/local/etc/php/conf.d/docker-php-ext-zip.ini
        You can also run `php --ini` to show them.
        Alternatively, you can run Composer with `--ignore-platform-req=ext-zstd`.
        OUT;

        $explained = DeployFailureExplainer::match(FailureOutput::select($output));

        $this->assertSame('php-extension-missing', $explained['rule'] ?? null);
        $this->assertStringContainsString('zstd', $explained['message'] ?? '');
    }

    /**
     * BuildKit's own error line is the cause, not progress.
     *
     * `#N ERROR:` was in the noise list, so it was dropped before the explainer
     * saw it and the sentence came from the layer banner above it. hitkeep is
     * the case: its whole failure is one line -- the Dockerfile names an image
     * tag that does not exist -- while the reported headline was
     * "Image project-hitkeep Building".
     */
    public function test_a_buildkit_error_line_leads(): void
    {
        $output = <<<'OUT'
        #5 [2/4] RUN go build ./...
        #5 CACHED
        #6 [3/4] FROM docker.io/library/golang:required-by-hk
        #6 ERROR: docker.io/library/golang:required-by-hk: not found
        ------
         > [3/4] FROM docker.io/library/golang:required-by-hk:
        ------
        failed to solve: docker.io/library/golang:required-by-hk: not found
        OUT;

        $selected = FailureOutput::select($output);

        $this->assertStringStartsWith('#6 ERROR: docker.io/library/golang:required-by-hk: not found', $selected);
        // The layer banner is still dropped, and it is not the headline.
        $this->assertStringNotContainsString('[2/4] RUN go build', $selected);
    }

    /**
     * qdrant: rustc was killed compiling several crates, and the compile
     * errors were further above the memory failure than the region reaches.
     */
    public function test_a_step_that_ran_out_of_memory_leads_over_the_compile_errors_it_caused(): void
    {
        $lines = ['#22 177.7 error: could not compile `segment` (lib)'];
        for ($i = 0; $i < 20; $i++) {
            $lines[] = "#22 177.7 error: could not compile `crate{$i}` (lib)";
        }
        $lines[] = '#22 ERROR: process "/bin/sh -c cargo build --release" did not complete successfully: cannot allocate memory';
        $lines[] = '------';
        $lines[] = 'failed to solve: ResourceExhausted: process "/bin/sh -c cargo build --release" did not complete successfully: cannot allocate memory';

        $region = FailureOutput::select(implode("\n", $lines));

        $this->assertStringStartsWith('#22 ERROR: process', $region);
        $this->assertSame('out-of-memory', DeployFailureExplainer::match($region)['rule'] ?? null);
    }

    /**
     * The daemon refusing to create a container -- akkoma's shape, where the
     * app's own image builds and sysbox will not run it.
     */
    public function test_a_daemon_refusal_leads(): void
    {
        $output = <<<'OUT'
        #6 6.920 (129/159) Installing perl-error (0.17029-r1)
        Error response from daemon: failed to create task for container: failed to create shim task: OCI runtime create failed: runc create failed: unable to start container process
        OUT;

        $this->assertStringStartsWith('Error response from daemon:', FailureOutput::select($output));
    }

    /**
     * Docker compose logs in logfmt, and its own notices are not the failure.
     *
     * `compose up` opens with `level=warning msg="The \"IMAGE\" variable is not
     * set..."`, which the selector recognised as ordinary text and led with,
     * while the line that says why sits directly beneath it:
     *
     *   foodsoft  -> service "foodsoft" has neither an image nor a build
     *                context specified: invalid compose project
     *   coreshop  -> failed to pull OCI resource "...": ... 401 Unauthorized
     *   assets    -> unable to get image 'ghcr.io/venil7/assets:': Error
     *                response from daemon: invalid reference format
     */
    public function test_a_compose_notice_does_not_lead(): void
    {
        $output = <<<'OUT'
        time="2026-09-14T17:51:21Z" level=warning msg="The \"IMAGE\" variable is not set. Defaulting to a blank string."
        time="2026-09-14T17:51:21Z" level=warning msg="The \"DOMAIN\" variable is not set. Defaulting to a blank string."
        service "foodsoft" has neither an image nor a build context specified: invalid compose project
        OUT;

        $this->assertStringStartsWith(
            'service "foodsoft" has neither an image nor a build context specified',
            FailureOutput::select($output)
        );
    }

    /** `level=info` is a notice too -- coreshop's first line is one. */
    public function test_a_compose_info_line_does_not_lead_either(): void
    {
        $output = <<<'OUT'
        time="2026-09-14T17:50:07Z" level=info msg="fetch failed" error="failed to authorize: ... 401 Unauthorized" host=ghcr.io
        failed to pull OCI resource "ghcr.io/cors-gmbh/dev-compose:pimcore2026.1": failed to authorize: failed to fetch anonymous token: unexpected status ... 401 Unauthorized
        OUT;

        $this->assertStringStartsWith('failed to pull OCI resource', FailureOutput::select($output));
    }

    /**
     * ...but `level=error` is compose saying something went wrong, so it is
     * kept: dropping every logfmt line would throw away the real ones.
     */
    public function test_a_compose_error_line_is_kept(): void
    {
        $output = 'time="2026-09-14T17:00:00Z" level=error msg="dependency failed to start: container is unhealthy"';

        $this->assertStringContainsString('dependency failed to start', FailureOutput::select($output));
    }

    /** An image the daemon refuses to fetch, in compose's own words. */
    public function test_an_unfetchable_image_leads(): void
    {
        $output = <<<'OUT'
        time="2026-09-14T17:49:06Z" level=warning msg="The \"ASSETS_CACHE_TTL\" variable is not set."
        unable to get image 'ghcr.io/venil7/assets:': Error response from daemon: invalid reference format
        OUT;

        $this->assertStringStartsWith("unable to get image 'ghcr.io/venil7/assets:'", FailureOutput::select($output));
    }

    /**
     * The `maven:` image's entrypoint is a decoy, and Maven's own failure is
     * the cause.
     *
     * The engine compiles Java on the *host*, as the account. The image's HOME
     * is /root, so its entrypoint cannot create the directory it wants and
     * says so -- and then says the same thing itself, in the line above, as
     * `Can not write to /root/.m2/copy_reference_file.log ... Carrying on
     * ...`. Being the first cause-looking line in a reactor build, the mkdir
     * won the selector while the real failure sat thousands of lines later:
     * measured on openmeetings, whose deploy log is 1381 lines, the selected
     * region opened on a compose banner and Maven's `[ERROR] Failed to
     * execute goal org.apache.rat:...` was never reached.
     *
     * {@see DeployFailureExplainer} already documents this decoy -- "twelve
     * Java apps were once filed under it" -- so this asserts the selector
     * agrees with the explainer instead of re-introducing it.
     */
    public function test_a_maven_entrypoint_mkdir_does_not_lead_past_the_goal_failure(): void
    {
        $output = <<<'OUT'
        Compiling application on host
        Waiting for the host build slot
        Can not write to /root/.m2/copy_reference_file.log. Wrong volume permissions? Carrying on ...
        mkdir: cannot create directory ‘/root’: Permission denied
        Picked up JAVA_TOOL_OPTIONS: -Xmx3642m
        [INFO] Scanning for projects...
        [INFO] Downloading from central: https://repo.maven.apache.org/maven2/org/apache/apache/39/apache-39.pom
        [INFO] BUILD FAILURE
        [ERROR] Failed to execute goal org.apache.rat:apache-rat-plugin:0.18:check (default) on project openmeetings-parent: Counter(s) UNAPPROVED exceeded minimum or maximum values.
        [ERROR] To see the full stack trace of the errors, re-run Maven with the -e switch.
        OUT;

        $selected = FailureOutput::select($output);

        $this->assertStringStartsWith(
            '[ERROR] Failed to execute goal org.apache.rat:apache-rat-plugin:0.18:check',
            $selected,
            'the goal line names whose failure this is'
        );
        $this->assertStringNotContainsString('mkdir: cannot create directory', $selected);
        // Maven's per-module chatter is not the headline either.
        $this->assertStringNotContainsString('[INFO] Scanning for projects', $selected);
    }

    /**
     * ...but a `mkdir` that names anything else is still a finding. Only the
     * maven image's own path is dropped.
     */
    public function test_a_mkdir_that_is_not_the_maven_entrypoint_still_leads(): void
    {
        $output = <<<'OUT'
        [INFO] Scanning for projects...
        mkdir: cannot create directory ‘/app/storage/framework/views’: Permission denied
        OUT;

        $this->assertStringStartsWith(
            'mkdir: cannot create directory ‘/app/storage',
            FailureOutput::select($output)
        );
    }

    /**
     * The host build's own plumbing is not the failure.
     *
     * A Go host compile runs as the account, so `apk add git` cannot open its
     * log and prints `ERROR: Unable to open log: Permission denied` -- and then
     * is ignored, which is why {@see GoRuntime::buildCommand()} wraps it in
     * `|| true`. Being the first non-noise line after "Compiling application on
     * host", it became the headline for a dozen Go and Python apps while the
     * real failure was the line directly beneath it (stash, anubis, beszel,
     * dropserver, ech0, lura, mediamtx, photoprism, plugnmeet, pomerium,
     * prometheus, recipya, screego, traefik, outline-server).
     */
    public function test_the_host_builds_own_log_error_does_not_lead(): void
    {
        $output = <<<'OUT'
        Loaded base image golang:1.25-alpine from host cache
        Compiling application on host
        ERROR: Unable to open log: Permission denied
        package github.com/stashapp/stash: build constraints exclude all Go files in /app
        OUT;

        $selected = FailureOutput::select($output);

        $this->assertStringStartsWith('package github.com/stashapp/stash', $selected);
        $this->assertStringNotContainsString('Unable to open log', $selected);
    }

    /** ...but an `ERROR:` with a different subject is still a finding. */
    public function test_an_error_with_another_subject_is_kept(): void
    {
        $output = 'ERROR: Unable to open /srv/app/config.yml: No such file or directory';

        $this->assertStringStartsWith('ERROR: Unable to open /srv/app', FailureOutput::select($output));
    }

    public function test_empty_and_all_noise_answer_nothing(): void
    {
        $this->assertSame('', FailureOutput::select(''));
        $this->assertSame('', FailureOutput::select("npm warn deprecated a@1\n\n   \n"));
    }

    /**
     * AppLauncher's shape: the failed step's `#N` lines, then compose's stderr.
     * `Image ... Building` is noise, so without this the region kept the pull
     * error and lost the proof the tag is built here (live deploy, #235).
     */
    public function test_the_pull_of_a_tag_compose_builds_is_not_the_reason(): void
    {
        $output = <<<'OUT'
#6 [2/2] RUN echo "gex296 local build step" && exit 1
#6 0.421 gex296 local build step
#6 ERROR: process "/bin/sh -c echo \"gex296 local build step\" && exit 1" did not complete successfully: exit code: 1
 Image probe-local:latest Pulling 
 Image probe-local:latest Error failed to resolve reference "docker.io/library/probe-local:latest": docker.io/library/probe-local:latest: not found
 Image probe-local:latest Building 
failed to solve: process "/bin/sh -c echo \"gex296 local build step\" && exit 1" did not complete successfully: exit code: 1
OUT;

        $selected = FailureOutput::select($output);

        $this->assertStringNotContainsString('failed to resolve reference', $selected);
        $this->assertSame('build-step-failed', DeployFailureExplainer::match($selected)['rule'] ?? null);
    }

    public function test_the_pull_error_of_a_tag_nothing_builds_is_kept(): void
    {
        $output = <<<'OUT'
 Image other/app:1 Error failed to resolve reference "docker.io/other/app:1": docker.io/other/app:1: not found
 Image probe-local:latest Building 
OUT;

        $this->assertStringContainsString('other/app:1 Error failed to resolve', FailureOutput::select($output));
    }

    /**
     * `docker compose up -d` on a stack whose datastore never turned healthy,
     * captured from Compose on Docker 29.8.1 and trimmed. rero-ils (#112) was
     * reported by the layer downloads above the one line that said why.
     */
    public const COMPOSE_UNHEALTHY_DEPENDENCY = <<<'OUT'
         Image postgres:17-alpine Pulling 
         Image curlimages/curl Pulling 
         Image redis:alpine Pulling 
         8c9c3a906635 Pulling fs layer 0B
         618072543738 Pulling fs layer 0B
         d0c1d894c237 Pulling fs layer 0B
         2f1039339059 Pulling fs layer 0B
         095940d4ebca Pulling fs layer 0B
         d60026184d2e Pulling fs layer 0B
         4f4fb700ef54 Pulling fs layer 0B
         b18ba029a163 Pulling fs layer 0B
         4ece9a32c307 Pulling fs layer 0B
         a8a481ae6efc Pulling fs layer 0B
         eb805f20f060 Pulling fs layer 0B
         f3b07e8a357c Pulling fs layer 0B
         7ccdb0dcae74 Pulling fs layer 0B
         43f9814c9a3b Pulling fs layer 0B
         93a3470d5852 Pulling fs layer 0B
         3333950675b2 Pulling fs layer 0B
         b0a0d9d2abf2 Pulling fs layer 0B
         e2de96513ba9 Pulling fs layer 0B
         4f4fb700ef54 Download complete 0B
         8c9c3a906635 Downloading 1.049MB
         2f1039339059 Download complete 0B
         Image curlimages/curl Pulled 
         Image redis:alpine Pulled 
         Image postgres:17-alpine Pulled 
         Network r_default Creating 
         Network r_default Created 
         Network r_default Created 
         Container r-cache-1 Creating 
         Container r-db-1 Creating 
         Container r-cache-1 Created 
         Container r-db-1 Created 
         Container r-init-1 Creating 
         Container r-init-1 Created 
         Container r-cache-1 Starting 
         Container r-db-1 Starting 
         Container r-cache-1 Started 
         Container r-db-1 Started 
         Container r-db-1 Waiting 
         Container r-db-1 Error dependency db failed to start
        dependency failed to start: container r-db-1 is unhealthy
        OUT;

    public function test_compose_progress_is_not_the_reason(): void
    {
        $selected = FailureOutput::select(self::COMPOSE_UNHEALTHY_DEPENDENCY);

        $this->assertStringStartsWith('dependency failed to start: container r-db-1 is unhealthy', $selected);
        $this->assertStringNotContainsString('Downloading', $selected);
        $this->assertStringNotContainsString('Pulling', $selected);
    }

    public function test_a_compose_line_that_says_error_is_kept(): void
    {
        $output = <<<'OUT'
         Image HaschekSolutions/pictshare:3 Pulling 
         Image HaschekSolutions/pictshare:3 Error failed to resolve reference "HaschekSolutions/pictshare:3": no such host
         Container project-mongo-1 Error dependency mongo failed to start
        OUT;

        $selected = FailureOutput::select($output);

        $this->assertStringContainsString('Image HaschekSolutions/pictshare:3 Error failed to resolve', $selected);
        $this->assertStringContainsString('Container project-mongo-1 Error dependency', $selected);
        $this->assertStringNotContainsString('Pulling', $selected);
    }

    /**
     * tigase-server on develop, 2026-09-23: the host compile's stderr is only
     * the maven image's mkdir and the JVM banner, and Maven's own failure is on
     * stdout. The deploy said "Failed to start app: mkdir: cannot create
     * directory '/root'" (#86).
     */
    private const TIGASE_STDERR = "mkdir: cannot create directory ‘/root’: Permission denied\nPicked up JAVA_TOOL_OPTIONS: -Xmx3641m\n";

    private const TIGASE_STDOUT = <<<'OUT'
        [INFO] Compiling 816 source files with javac [debug release 25] to target/classes
        [INFO] ------------------------------------------------------------------------
        [INFO] BUILD FAILURE
        [INFO] ------------------------------------------------------------------------
        [INFO] Total time:  36.629 s
        [INFO] --             Maven Build Time Profiler Summary                      --
        [INFO]         1189 ms : compile
        [INFO] 99,363 ms  54,170,013 bytes. 0.52 MiB / s
        [INFO] ForkTime: 0
        [ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin:3.15.0:compile (default-compile) on project tigase-server: Fatal error compiling: error: release version 25 not supported -> [Help 1]
        [ERROR]
        [ERROR] To see the full stack trace of the errors, re-run Maven with the -e switch.
        [ERROR] Re-run Maven using the -X switch to enable full debug logging.
        OUT;

    public function test_the_maven_entrypoint_mkdir_is_noise_with_the_quotes_coreutils_prints(): void
    {
        $this->assertSame('', FailureOutput::select(self::TIGASE_STDERR));
        $this->assertSame('', FailureOutput::select("mkdir: cannot create directory '/root': Permission denied"));
    }

    public function test_a_noise_only_stderr_hands_over_to_the_failure_on_stdout(): void
    {
        $text = FailureOutput::fromStreams(self::TIGASE_STDERR, self::TIGASE_STDOUT);

        $this->assertStringStartsWith('[ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin', $text);
        $this->assertStringNotContainsString('mkdir', $text);
        $this->assertStringContainsString('Java 25', (string) DeployFailureExplainer::explain($text));
    }

    public function test_a_stderr_that_says_something_is_kept(): void
    {
        $stderr = "npm error code ERESOLVE\nnpm error ERESOLVE unable to resolve dependency tree\n";

        $this->assertSame($stderr, FailureOutput::fromStreams($stderr, "added 12 packages\n"));
        $this->assertSame('only stdout', FailureOutput::fromStreams('', 'only stdout'));
    }

    /** Rust's best-effort `apt-get update` as the account, wrapped in `|| true`. */
    public function test_apt_lists_permission_line_is_noise(): void
    {
        $this->assertSame('', FailureOutput::select(
            'E: List directory /var/lib/apt/lists/partial is missing. - Acquire (13: Permission denied)'
        ));
    }

    /** OpenSourcePOS's host frontend build: stderr of `docker run node:22-bookworm`, gulp failing. */
    private const HOST_BUILD_PULL_STDERR = <<<'ERR'
        Unable to find image 'node:22-bookworm' locally
        22-bookworm: Pulling from library/node
        0c06829c34ad: Pulling fs layer
        105754cf457c: Pulling fs layer
        0c06829c34ad: Download complete
        105754cf457c: Pull complete
        Digest: sha256:363e1587494626837fa7f9a23bdb453d13b0ff3c67c705c2805cfc69c2d2fad7
        Status: Downloaded newer image for node:22-bookworm
        npm warn deprecated gulp-util@3.0.8: gulp-util is deprecated
        [18:02:31] 'update-licenses' errored after 24 ms
        [18:02:31] Error: Command `composer licenses --format=json --no-dev > public/license/composer.LICENSES` exited with code 127
            at ChildProcess.handleSubShellExit (/app/node_modules/gulp-run/command.js:166:13)
        [18:02:31] 'default' errored after 38 ms
        ERR;

    public function test_docker_runs_image_pull_is_not_the_reason(): void
    {
        $region = FailureOutput::select(self::HOST_BUILD_PULL_STDERR);

        $this->assertStringStartsWith("[18:02:31] 'update-licenses' errored", $region);
        $this->assertStringNotContainsString('Pulling', $region);
        $this->assertStringNotContainsString('Digest:', $region);
    }

    public function test_an_unexplained_host_build_failure_leads_with_what_the_build_said(): void
    {
        $text = FailureOutput::withoutNoise(FailureOutput::fromStreams(self::HOST_BUILD_PULL_STDERR, "added 574 packages\n"));

        $this->assertStringStartsWith("[18:02:31] 'update-licenses' errored", $text);
        $this->assertStringContainsString('exited with code 127', $text);
        $this->assertStringNotContainsString('Unable to find image', $text);
        $this->assertStringNotContainsString('npm warn', $text);
    }

    public function test_without_noise_keeps_every_line_that_says_something(): void
    {
        $sentence = "Failed to start app: build failed\nnpm error code 1\nnpm error path /app | Set FOO";

        $this->assertSame($sentence, FailureOutput::withoutNoise($sentence));
        $this->assertSame('Deploy failed on purpose', FailureOutput::withoutNoise("  Deploy failed on purpose\n"));
        // All noise: the output itself, not nothing.
        $this->assertSame('npm warn deprecated x', FailureOutput::withoutNoise('npm warn deprecated x'));
    }
}
