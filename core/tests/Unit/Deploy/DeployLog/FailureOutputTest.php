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

    /**
     * ntfy's Makefile asks git for a version in a build context with no .git,
     * and those `fatal:` lines led the headline twelve lines above the reason.
     */
    public function test_a_go_toolchain_refusal_leads_not_gits_missing_repository(): void
    {
        $output = <<<'OUT'
        #40 [builder 34/34] RUN --mount=type=cache,target=/go/pkg/mod make VERSION=dev COMMIT=unknown cli-linux-server
        #40 0.688 fatal: not a git repository (or any of the parent directories): .git
        #40 0.690 fatal: not a git repository (or any of the parent directories): .git
        #40 0.695 mkdir -p server/docs server/site
        #40 0.697 touch server/docs/index.html server/site/app.html
        #40 0.707 # This is a target to build the CLI (including the server) manually.
        #40 0.709 # Use this for development, if you really don't want to install GoReleaser ...
        #40 0.709 mkdir -p dist/ntfy_linux_server server/docs
        #40 0.712 CGO_ENABLED=1 go build \
        #40 0.712 	-o dist/ntfy_linux_server/ntfy \
        #40 0.712 	-tags sqlite_omit_load_extension,osusergo,netgo \
        #40 0.712 	-ldflags \
        #40 0.712 	"-linkmode=external -extldflags=-static -s -w -X main.version=dev -X main.commit=unknown -X main.date=1790933091"
        #40 0.724 go: go.mod requires go >= 1.26.0 (running go 1.25.14; GOTOOLCHAIN=local)
        #40 0.725 make: *** [Makefile:201: cli-linux-server] Error 1
        #40 ERROR: process "/bin/sh -c make VERSION=$VERSION COMMIT=$COMMIT cli-linux-server" did not complete successfully: exit code: 2
        OUT;

        $selected = FailureOutput::select($output);

        $this->assertStringStartsWith('#40 0.724 go: go.mod requires go >= 1.26.0', $selected);
        $this->assertSame(
            'This project needs Go 1.26.0, but it was built with Go 1.25.14.',
            DeployFailureExplainer::explain($selected)
        );
    }

    public function test_a_real_git_failure_still_leads(): void
    {
        $this->assertStringStartsWith(
            'fatal: repository',
            FailureOutput::select("Cloning into 'x'...\nfatal: repository 'https://github.com/a/b/' not found")
        );
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
    /**
     * technomancy-dev/00 on a Debian 11 base: apt says why above `#8 ERROR:`, and
     * the region started at that line, so the deploy read "A build step failed".
     * Real streams of `docker compose up --build`, joined as AppLauncher does.
     */
    public function test_apts_own_error_lines_lead_a_failed_package_install(): void
    {
        $stdout = "#8 3.263 Get:9 http://deb.debian.org/debian bullseye/main amd64 libncurses5 amd64 6.2+20201114-2+deb11u2 [96.8 kB]\n"
            . "#8 3.264 Get:10 http://deb.debian.org/debian bullseye/main amd64 pandoc-data all 2.9.2.1-1+deb11u1 [377 kB]\n"
            . "#8 3.278 Get:11 http://deb.debian.org/debian bullseye/main amd64 pandoc amd64 2.9.2.1-1+deb11u1 [18.5 MB]\n"
            . "#8 3.445 Fetched 19.5 MB in 0s (81.8 MB/s)\n"
            . "#8 3.445 E: Failed to fetch http://deb.debian.org/debian-security/pool/updates/main/o/openssl/openssl_1.1.1w-0%2bdeb11u8_amd64.deb  404  Not Found [IP: 151.101.2.132 80]\n"
            . "#8 3.445 E: Failed to fetch http://deb.debian.org/debian-security/pool/updates/main/c/ca-certificates/ca-certificates_20250419%7edeb12u1%7edeb11u1_all.deb  404  Not Found [IP: 151.101.2.132 80]\n"
            . "#8 3.445 E: Failed to fetch http://deb.debian.org/debian-security/pool/updates/main/g/glibc/libc-l10n_2.31-13%2bdeb11u14_all.deb  404  Not Found [IP: 151.101.2.132 80]\n"
            . "#8 3.445 E: Failed to fetch http://deb.debian.org/debian-security/pool/updates/main/g/glibc/locales_2.31-13%2bdeb11u14_all.deb  404  Not Found [IP: 151.101.2.132 80]\n"
            . "#8 3.445 E: Unable to fetch some archives, maybe run apt-get update or try with --fix-missing?\n"
            . "#8 ERROR: process \"/bin/sh -c apt-get update -y &&     apt-get install -y libstdc++6 openssl libncurses5 locales ca-certificates pandoc     && apt-get clean && rm -f /var/lib/apt/lists/*_*\" did not complete successfully: exit code: 100\n";
        $stderr = " Image project-app Building \n"
            . "Dockerfile:77\n"
            . "\n"
            . "--------------------\n"
            . "\n"
            . "  76 |     \n"
            . "\n"
            . "  77 | >>> RUN apt-get update -y && \\\n"
            . "\n"
            . "  78 | >>>     apt-get install -y libstdc++6 openssl libncurses5 locales ca-certificates pandoc \\\n"
            . "\n"
            . "  79 | >>>     && apt-get clean && rm -f /var/lib/apt/lists/*_*\n"
            . "\n"
            . "  80 |     \n"
            . "\n"
            . "--------------------\n"
            . "\n"
            . "failed to solve: process \"/bin/sh -c apt-get update -y &&     apt-get install -y libstdc++6 openssl libncurses5 locales ca-certificates pandoc     && apt-get clean && rm -f /var/lib/apt/lists/*_*\" did not complete successfully: exit code: 100\n"
            . "\n";
        $raw = FailureOutput::failedBuildStep($stdout) . "\n" . $stderr;

        $region = FailureOutput::select($raw);

        $this->assertStringStartsWith('#8 3.445 E: Failed to fetch', $region);
        $this->assertSame('package-archive-gone', DeployFailureExplainer::match($region)['rule'] ?? null);
    }

    public function test_a_bare_apt_error_leads(): void
    {
        $this->assertStringStartsWith(
            "E: Unable to locate package libfoo-dev",
            FailureOutput::select("Reading package lists...\nE: Unable to locate package libfoo-dev\nexit code: 100")
        );
    }

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

    /**
     * electerm-web's out-of-sync lockfile on npm 11: the usage text after EUSAGE
     * is longer than WINDOW, so the region began mid-usage and the deploy quoted
     * it. Real stderr of the host build.
     */
    public function test_an_npm_error_block_longer_than_the_window_leads_from_its_start(): void
    {
        $stderr = <<<'NPMUSAGE'
Unable to find image 'node:24-bookworm' locally
24-bookworm: Pulling from library/node
4cc81be23c06: Pulling fs layer
240de4f9ec20: Pulling fs layer
496e07b192ff: Pulling fs layer
7f25c0042239: Pulling fs layer
26d180362d43: Download complete
4cc81be23c06: Download complete
f133ed9f16b1: Download complete
496e07b192ff: Download complete
7f25c0042239: Download complete
7f25c0042239: Pull complete
240de4f9ec20: Download complete
240de4f9ec20: Pull complete
4cc81be23c06: Pull complete
496e07b192ff: Pull complete
Digest: sha256:64af3819f9275802414d7cdc38c27e9d82bd564dec4d4da87d008255d36c63b4
Status: Downloaded newer image for node:24-bookworm
npm error code EUSAGE
npm error
npm error `npm ci` can only install packages when your package.json and package-lock.json or npm-shrinkwrap.json are in sync. Please update your lock file with `npm install` before continuing.
npm error
npm error Missing: @types/react@19.3.0 from lock file
npm error
npm error Clean install a project
npm error
npm error Usage:
npm error npm ci
npm error
npm error Options:
npm error [--install-strategy <hoisted|nested|shallow|linked>] [--legacy-bundling]
npm error [--global-style] [--omit <dev|optional|peer> [--omit <dev|optional|peer> ...]]
npm error [--include <prod|dev|optional|peer> [--include <prod|dev|optional|peer> ...]]
npm error [--strict-peer-deps] [--foreground-scripts] [--ignore-scripts]
npm error [--allow-directory <all|none|root>] [--allow-file <all|none|root>]
npm error [--allow-git <all|none|root>] [--allow-remote <all|none|root>]
npm error [--allow-scripts <package-list> [--allow-scripts <package-list> ...]]
npm error [--strict-allow-scripts] [--dangerously-allow-all-scripts] [--no-audit]
npm error [--no-bin-links] [--no-fund] [--dry-run]
npm error [-w|--workspace <workspace-name> [-w|--workspace <workspace-name> ...]]
npm error [--workspaces] [--include-workspace-root] [--install-links]
npm error
npm error   --install-strategy
npm error     Sets the strategy for installing packages in node_modules.
npm error
npm error   --legacy-bundling
npm error     Instead of hoisting package installs in `node_modules`, install packages
npm error
npm error   --global-style
npm error     Only install direct dependencies in the top level `node_modules`,
npm error
npm error   --omit
npm error     Dependency types to omit from the installation tree on disk.
npm error
npm error   --include
npm error     Option that allows for defining which types of dependencies to install.
npm error
npm error   --strict-peer-deps
npm error     If set to `true`, and `--legacy-peer-deps` is not set, then _any_
npm error
npm error   --foreground-scripts
npm error     Run all build scripts (ie, `preinstall`, `install`, and
npm error
npm error   --ignore-scripts
npm error     If true, npm does not run scripts specified in package.json files.
npm error
npm error   --allow-directory
npm error     Limits the ability for npm to install dependencies from directories.
npm error
npm error   --allow-file
npm error     Limits the ability for npm to install dependencies from tarball files.
npm error
npm error   --allow-git
npm error     Limits the ability for npm to fetch dependencies from git references.
npm error
npm error   --allow-remote
npm error     Limits the ability for npm to fetch dependencies from urls.
npm error
npm error   --allow-scripts
npm error     Comma-separated list of packages whose install-time lifecycle scripts
npm error
npm error   --strict-allow-scripts
npm error     If `true`, turn the install-script policy from a warning into a hard
npm error
npm error   --dangerously-allow-all-scripts
npm error     If `true`, bypass the `allowScripts` policy entirely and run every
npm error
npm error   --audit
npm error     When "true" submit audit reports alongside the current npm command to the
npm error
npm error   --bin-links
npm error     Tells npm to create symlinks (or `.cmd` shims on Windows) for package
npm error
npm error   --fund
npm error     When "true" displays the message at the end of each `npm install`
npm error
npm error   --dry-run
npm error     Indicates that you don't want npm to make any changes and that it should
npm error
npm error   -w|--workspace
npm error     Enable running a command in the context of the configured workspaces of the
npm error
npm error   --workspaces
npm error     Set to true to run the command in the context of **all** configured
npm error
npm error   --include-workspace-root
npm error     Include the workspace root when workspaces are enabled for a command.
npm error
npm error   --install-links
npm error     When set file: protocol dependencies will be packed and installed as
npm error
npm error aliases: clean-install, ic, install-clean, isntall-clean
npm error
npm error Run "npm help ci" for more info
npm error A complete log of this run can be found in: /var/cache/pa-js/npm/_logs/2026-10-02T08_57_27_453Z-debug-0.log
NPMUSAGE;

        $region = FailureOutput::select($stderr);

        $this->assertStringStartsWith('npm error code EUSAGE', $region);
        $match = DeployFailureExplainer::match($region);
        $this->assertSame('npm-lockfile-out-of-sync', $match['rule'] ?? null);
        $this->assertStringContainsString('(npm: Missing: @types/react@19.3.0 from lock file)', $match['message']);
    }

    public function test_an_npm_error_block_inside_the_window_is_unchanged(): void
    {
        $output = "> build\nnpm error code ELIFECYCLE\nnpm error errno 1\nnpm error app@1.0.0 build: `vite build`";

        $this->assertStringStartsWith('npm error code ELIFECYCLE', FailureOutput::select($output));
    }

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
