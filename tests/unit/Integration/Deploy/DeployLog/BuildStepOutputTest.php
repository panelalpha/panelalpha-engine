<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\System\Project\Dind\AppLauncher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
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

    /** photofield. */
    private const GO_STDOUT = <<<'OUT'
    #12 [builder 3/3] RUN set -eou pipefail && CGO_ENABLED=0 go build -tags embedui,embeddocs,embedgeo -o /build/photofield .
    #12 6.297 go: downloading github.com/segmentio/asm v1.1.3
    #12 7.421 embed-geo.go:9:12: pattern data/geo/geoBoundariesCGAZ_ADM2_s5_twkb_p3.gpkg: no matching files found
    #12 ERROR: process "/bin/sh -c set -eou pipefail && CGO_ENABLED=0 go build -tags embedui,embeddocs,embedgeo -o /build/photofield ." did not complete successfully: exit code: 1
    OUT;

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

    private function exited(string $stdout, string $stderr, int $code): Process
    {
        $process = new Process([
            'sh', '-c', 'printf %s "$OUT"; printf %s "$ERR" >&2; exit "$CODE"',
        ], null, ['OUT' => $stdout, 'ERR' => $stderr, 'CODE' => (string) $code]);
        $process->run();

        return $process;
    }
}
