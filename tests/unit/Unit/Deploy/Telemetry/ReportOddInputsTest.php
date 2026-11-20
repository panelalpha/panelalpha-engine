<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Telemetry\AppFacts;
use App\Lib\Deploy\Telemetry\BugReport;
use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\Redactor;
use App\Lib\Deploy\Telemetry\SourceBundlePolicy;
use PHPUnit\Framework\TestCase;

/**
 * What the report builders do with input of the wrong shape, and the redaction edges a well-formed report
 * rarely reaches: a long tail, blank paragraphs, a key pair, a malformed address.
 */
class ReportOddInputsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bug(array $overrides = []): array
    {
        return BugReport::build($overrides + [
            'id' => '01JGXR8Q4M9ZK7W2N5T3V6B0AC',
            'occurred_at' => 1756137600,
            'tier' => DeployReport::TIER_LOG,
            'install_id' => str_repeat('a', 32),
            'username' => 'shop',
            'title' => 'The site answers 502',
            'description' => 'It deploys and then 502s.',
        ]);
    }

    public function test_a_contact_that_is_not_text_or_is_blank_is_left_out(): void
    {
        $this->assertArrayNotHasKey('contact', $this->bug(['contact' => ['ops@example.test']])['bug']);
        $this->assertArrayNotHasKey('contact', $this->bug(['contact' => " \n\t "])['bug']);
        $this->assertSame('ops team', $this->bug(['contact' => "ops \n team"])['bug']['contact']);
    }

    public function test_a_private_repository_address_that_cannot_be_parsed_is_masked_whole(): void
    {
        $report = $this->bug([
            'repo_private' => true,
            'inspect' => ['application' => ['strategy' => 'php'], 'source' => ['repository' => 'not a repository address']],
        ]);

        $this->assertSame('<private>', $report['app']['inspect']['source']['repository']);
        $this->assertStringNotContainsString('not a repository address', (string) json_encode($report));
    }

    public function test_candidates_and_packages_of_the_wrong_type_are_dropped(): void
    {
        $report = DeployReport::build([
            'id' => '01JGXR0000000000000000000A',
            'occurred_at' => 1756137600,
            'outcome' => DeployReport::OUTCOME_FAILED,
            'tier' => DeployReport::TIER_LOG,
            'install_id' => 'install-abc',
            'username' => 'shop',
            'latest' => ['stage' => 'running'],
            'details' => ['deploy_strategy' => 'php'],
            'error' => 'Build failed',
            'candidates' => 'php',
            'packages' => 'composer.json',
        ]);

        $this->assertSame([], $report['deploy']['candidates'] ?? []);
        $this->assertArrayNotHasKey('app', $report);
    }

    public function test_platform_requirements_that_are_not_strings_are_skipped(): void
    {
        $package = new AppPackage('php', 'composer.json', 'acme/shop', 'file', platform: ['php' => '^8.3', 'ext-intl' => '*', 0 => 'x', 'ext-gd' => ['bad']]);

        $this->assertSame(['php' => '^8.3', 'ext-intl' => '*'], AppFacts::build([$package], DeployReport::TIER_LOG, false, str_repeat('a', 32))['platform']);
    }

    public function test_prose_collapses_runs_of_blank_lines(): void
    {
        $this->assertSame("first\n\nsecond", Redactor::prose("first\n\n\n\nsecond"));
        $this->assertSame('only', Redactor::prose("\n\nonly"));
    }

    public function test_a_tail_keeps_only_the_newest_lines_that_fit(): void
    {
        $lines = array_map(static fn (int $i): string => sprintf('step %02d ', $i).str_repeat('compiling module ', 25), range(1, Redactor::MAX_LINES));
        $tail = Redactor::tail($lines);

        $this->assertLessThan(Redactor::MAX_LINES, count($tail));
        $this->assertSame(trim(end($lines)), end($tail));
        $this->assertLessThanOrEqual(Redactor::MAX_TAIL_BYTES, strlen(implode("\n", $tail)) + 1);
    }

    public function test_an_address_without_four_parts_is_not_private(): void
    {
        $this->assertFalse(Redactor::isPrivateIpv4('10.0.1'));
    }

    public function test_key_pairs_and_credential_files_are_sensitive(): void
    {
        $this->assertTrue(SourceBundlePolicy::isSensitiveBasename('id_ed25519-work.pub'));
        $this->assertTrue(SourceBundlePolicy::isSensitiveBasename('.netrc'));
        $this->assertFalse(SourceBundlePolicy::isSensitiveBasename('identity.md'));
    }
}
