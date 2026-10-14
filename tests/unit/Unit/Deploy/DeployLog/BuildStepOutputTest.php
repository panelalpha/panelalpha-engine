<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\System\Project\Dind\AppLauncher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `docker compose up --build` (v5.5.1, measured) writes BuildKit's progress to
 * stdout and only the `failed to solve` summary to stderr. The deploy explained
 * stderr, so a repo Dockerfile whose Rust did not compile or whose
 * go:embed file was missing was reported as "A build step failed (exit
 * code N)" -- the rules for both exist and never saw the line they match.
 */
class BuildStepOutputTest extends TestCase
{
    /** wastebin, the failing step as compose printed it on stdout. */
    private const RUST_STDOUT = <<<'OUT'
    #12 [builder 7/9] RUN cargo fetch
    #12 DONE 31.2s

    #13 [builder 8/9] RUN cargo zigbuild --release --bin wastebin
    #13 247.8    Compiling wastebin_core v3.7.2 (/app/crates/wastebin_core)
    #14 [geodata 2/2] RUN apk add --no-cache curl
    #13 248.7    Compiling wastebin v3.7.2 (/app/crates/wastebin_server)
    #13 249.2 error[E0432]: unresolved import `crate::handlers::owner`
    #13 249.2   --> crates/wastebin_server/src/handlers/html/paste.rs:12:22
    #13 249.2    |
    #13 249.2 12 | use crate::handlers::owner::verify_owner_token;
    #13 249.2    |                      ^^^^^ could not find `owner` in `handlers`
    #13 251.0 For more information about this error, try `rustc --explain E0432`.
    #13 251.0 error: could not compile `wastebin` (bin "wastebin") due to 1 previous error
    #13 251.0 warning: build failed, waiting for other jobs to finish...
    #13 ERROR: process "/bin/sh -c cargo zigbuild --release --bin wastebin" did not complete successfully: exit code: 101
    #14 CANCELED
    ------
     > [builder 8/9] RUN cargo zigbuild --release --bin wastebin:
    251.0 error: could not compile `wastebin` (bin "wastebin") due to 1 previous error
    ------
    OUT;

    private const RUST_STDERR = <<<'OUT'
     Image project-app Building
    WARNING: current commit information was not captured by the build: failed to read current commit information with git rev-parse --is-inside-work-tree
    Dockerfile:30
    --------------------
      30 | >>> RUN cargo zigbuild --release --bin wastebin
    --------------------
    failed to solve: process "/bin/sh -c cargo zigbuild --release --bin wastebin" did not complete successfully: exit code: 101
    OUT;

    /** photofield. */
    private const GO_STDOUT = <<<'OUT'
    #12 [builder 3/3] RUN set -eou pipefail && CGO_ENABLED=0 go build -tags embedui,embeddocs,embedgeo -o /build/photofield .
    #12 6.297 go: downloading github.com/segmentio/asm v1.1.3
    #12 7.421 embed-geo.go:9:12: pattern data/geo/geoBoundariesCGAZ_ADM2_s5_twkb_p3.gpkg: no matching files found
    #12 ERROR: process "/bin/sh -c set -eou pipefail && CGO_ENABLED=0 go build -tags embedui,embeddocs,embedgeo -o /build/photofield ." did not complete successfully: exit code: 1
    OUT;

    private const GO_STDERR = <<<'OUT'
     Image project-app Building
    Dockerfile:9
    failed to solve: process "/bin/sh -c set -eou pipefail && CGO_ENABLED=0 go build -tags embedui,embeddocs,embedgeo -o /build/photofield ." did not complete successfully: exit code: 1
    OUT;

    public function test_stderr_alone_is_what_hid_the_cause(): void
    {
        $this->assertSame('build-step-failed', DeployFailureExplainer::match(self::RUST_STDERR)['rule'] ?? null);
        $this->assertSame('build-step-failed', DeployFailureExplainer::match(self::GO_STDERR)['rule'] ?? null);
    }

    public function test_the_failed_step_is_taken_from_stdout_and_nothing_else(): void
    {
        $step = FailureOutput::failedBuildStep(self::RUST_STDOUT);

        $this->assertStringContainsString('#13 251.0 error: could not compile `wastebin`', $step);
        $this->assertStringEndsWith('did not complete successfully: exit code: 101', $step);
        // Another step's line, interleaved, and a cached step before it.
        $this->assertStringNotContainsString('#14', $step);
        $this->assertStringNotContainsString('cargo fetch', $step);
    }

    public function test_no_failed_step_means_nothing_to_add(): void
    {
        $this->assertSame('', FailureOutput::failedBuildStep("#5 [1/2] FROM alpine\n#5 CACHED\n"));
        $this->assertSame('', FailureOutput::failedBuildStep(''));
    }

    public function test_a_failed_compose_build_is_explained_from_its_step_output(): void
    {
        $rust = AppLauncher::failureOutput($this->exited(self::RUST_STDOUT, self::RUST_STDERR, 1));
        $go = AppLauncher::failureOutput($this->exited(self::GO_STDOUT, self::GO_STDERR, 1));

        $this->assertSame('rust-compile-failed', DeployFailureExplainer::match($rust)['rule'] ?? null);
        $this->assertSame('missing-embedded-assets', DeployFailureExplainer::match($go)['rule'] ?? null);
        // The summary is still there for whoever reads the raw text.
        $this->assertStringContainsString('failed to solve:', $rust);
    }

    /**
     * The initial-deploy path narrows the text with select() first, and a
     * step line's cause sits behind BuildKit's `#13 251.0 ` prefix.
     */
    public function test_the_narrowed_text_still_carries_the_cause(): void
    {
        $rust = FailureOutput::select(AppLauncher::failureOutput($this->exited(self::RUST_STDOUT, self::RUST_STDERR, 1)));
        $go = FailureOutput::select(AppLauncher::failureOutput($this->exited(self::GO_STDOUT, self::GO_STDERR, 1)));

        $this->assertSame('rust-compile-failed', DeployFailureExplainer::match($rust)['rule'] ?? null);
        $this->assertSame('missing-embedded-assets', DeployFailureExplainer::match($go)['rule'] ?? null);
    }

    public function test_a_successful_up_keeps_stderr_as_it_was(): void
    {
        $this->assertSame(
            self::RUST_STDERR,
            AppLauncher::failureOutput($this->exited(self::RUST_STDOUT, self::RUST_STDERR, 0))
        );
    }

    /** A packaging Containerfile that COPYs what CI built. Stderr alone names it. */
    public function test_a_copy_of_a_path_the_checkout_lacks_is_named(): void
    {
        $stderr = "Containerfile:27\n"
            . "  27 | >>> COPY target .\n"
            . 'failed to solve: failed to compute cache key: failed to calculate checksum of ref '
            . 'k69cpoan246zpd26r51tmh3k0::t8rw2ynexstsycupm9sax48aw: "/target": not found';

        $match = DeployFailureExplainer::match($stderr);

        $this->assertSame('build-context-missing', $match['rule'] ?? null);
        $this->assertStringContainsString('`target`', $match['message'] ?? '');
    }

    /** A cgo-only dependency under a build with cgo off. */
    public function test_a_cgo_only_package_is_named(): void
    {
        $output = "package github.com/mynaparrot/plugnmeet-server\n"
            . "\timports github.com/livekit/server-sdk-go/v2/pkg/media\n"
            . "\timports github.com/livekit/media-sdk/opus: build constraints exclude all Go files in "
            . '/var/cache/pa-js/go/mod/github.com/livekit/media-sdk@v0.0.0-20260605212526-4c11a51d3c97/opus';

        $match = DeployFailureExplainer::match($output);

        $this->assertSame('go-cgo-required', $match['rule'] ?? null);
        $this->assertStringContainsString('`github.com/livekit/media-sdk/opus`', $match['message'] ?? '');
    }

    /** The package asked for is excluded by its own tag; cgo is not the reason. */
    public function test_an_excluded_own_package_is_not_blamed_on_cgo(): void
    {
        $output = "ERROR: Unable to open log: Permission denied\n"
            . 'package example.com/vf328: build constraints exclude all Go files in /app';

        $match = DeployFailureExplainer::match($output);

        $this->assertSame('go-package-excluded', $match['rule'] ?? null);
        $this->assertStringContainsString('`example.com/vf328`', $match['message'] ?? '');
        $this->assertStringNotContainsString('cgo', $match['message'] ?? '');
    }

    private function exited(string $stdout, string $stderr, int $code): Process
    {
        $process = new Process([
            'sh', '-c', 'printf %s "$OUT"; printf %s "$ERR" >&2; exit "$CODE"',
        ], null, ['OUT' => $stdout, 'ERR' => $stderr, 'CODE' => (string) $code]);
        $process->run();

        return $process;
    }
}
