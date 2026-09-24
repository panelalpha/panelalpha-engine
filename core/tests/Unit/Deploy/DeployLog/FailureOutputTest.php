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
}
