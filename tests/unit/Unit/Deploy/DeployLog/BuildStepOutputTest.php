<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\FailureOutput;
use PHPUnit\Framework\TestCase;
use Tests\Support\AstroBuildFailure;
use Tests\Support\BuildStepOutputFixtures;

/**
 * `docker compose up --build` (v5.5.1, measured) writes BuildKit's progress to
 * stdout and only the `failed to solve` summary to stderr. The deploy explained
 * stderr, so a repo Dockerfile whose Rust did not compile or whose
 * go:embed file was missing was reported as "A build step failed (exit
 * code N)" -- the rules for both exist and never saw the line they match.
 */
class BuildStepOutputTest extends TestCase
{
    use BuildStepOutputFixtures;

    public function test_a_failed_steps_tail_is_what_it_printed_above_the_failure_boilerplate(): void
    {
        $this->assertSame(
            "> astro build\n"
                . "12:01:33 [content] Syncing content\n"
                . "12:01:33 [build] output: \"static\"\n"
                . "[InvalidComponentArgs] Invalid arguments passed to <Layout> component.\n"
                . "  Location:\n"
                . '    /app/src/pages/index.astro:5:1',
            FailureOutput::failedStepTail(AstroBuildFailure::OUTPUT)
        );
    }

    public function test_output_without_a_failed_step_has_no_tail(): void
    {
        $this->assertSame('', FailureOutput::failedStepTail(self::RUST_STDERR));
        $this->assertSame('', FailureOutput::failedStepTail(''));
    }

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
}
