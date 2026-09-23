<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\Metadata\Framework;
use App\Lib\Deploy\Telemetry\DeployReport;
use PHPUnit\Framework\TestCase;

class DeployReportTest extends TestCase
{
    private const USERNAME = 'acme7x';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'id' => '01JGXR0000000000000000000A',
            'occurred_at' => 1756137600,
            'outcome' => DeployReport::OUTCOME_FAILED,
            'tier' => DeployReport::TIER_LOG,
            'install_id' => 'install-abc',
            'username' => self::USERNAME,
            'latest' => [
                'id' => '20260825-131200-a1b2c3',
                'stage' => 'running',
                'stages' => [
                    ['name' => 'preparing', 'started_at' => 100, 'finished_at' => 141],
                    ['name' => 'cloning', 'started_at' => 141, 'finished_at' => 147],
                    ['name' => 'running', 'started_at' => 147, 'finished_at' => 334],
                ],
            ],
            'details' => [
                'deploy_source' => 'git',
                'deploy_strategy' => 'nextjs',
                'deploy_label' => 'Next.js',
                'deploy_runtime' => 'node',
                'deploy_port' => 3000,
                'git_commit' => 'abcdef1234567890',
                'cpu_limit' => 2,
                'memory_limit' => 4096,
            ],
            'error' => 'no space left on device',
            'repo_url' => 'https://github.com/vercel/next.js.git',
            'repo_private' => false,
            'manifests' => ['package.json', 'src', 'readme.md', 'bun.lock'],
            'log_tail' => ['#12 building', 'ENOSPC: no space left on device'],
        ], $overrides);
    }

    public function test_a_precheck_rejection_names_the_precheck_not_the_cloning_stage(): void
    {
        $report = DeployReport::build($this->input([
            'latest' => ['id' => '20260923-083840-9d954b', 'stage' => 'cloning', 'precheck_rejected' => true],
            'error' => 'Error: Less than 10GB of disk space available.',
        ]));

        $this->assertSame('precheck', $report['failure']['stage']);
        $this->assertSame('running', DeployReport::build($this->input())['failure']['stage']);
    }

    public function test_the_domain_block_says_which_rung_the_install_landed_on(): void
    {
        $report = DeployReport::build($this->input(['details' => [
            'domain' => [
                'source' => 'local',
                'publicly_resolvable' => false,
                'tls_terminated_at' => 'engine',
                'tunnel' => null,
                'fallback_reason' => 'panelalpha_online: PanelAlpha Online create failed: License is not valid (HTTP 403)',
            ],
            'ssl' => ['status' => 'self_signed', 'issuer' => 'PanelAlpha'],
        ]]));

        $this->assertSame('local', $report['domain']['source']);
        $this->assertFalse($report['domain']['publicly_resolvable']);
        $this->assertSame('engine', $report['domain']['tls_terminated_at']);
        $this->assertSame('self_signed', $report['domain']['ssl_status']);
        $this->assertSame('PanelAlpha', $report['domain']['ssl_issuer']);
        $this->assertStringContainsString('License is not valid (HTTP 403)', $report['domain']['fallback_reason']);
        // The categories say which rung; the names themselves come from the
        // account, so a report built without them carries none.
        $this->assertArrayNotHasKey('names', $report['domain']);
    }

    /**
     * The operator's requirement: an event names the application it is about.
     *
     * The address is the real hostname, in the clear — it is what a visitor
     * types, and a report that says an application is degraded without saying
     * where to look sends support back to the panel to find out. The account
     * name is still a hash everywhere else in the same report.
     */
    public function test_the_domain_block_names_the_real_hostnames(): void
    {
        $report = DeployReport::build($this->input([
            'domains' => [
                ['domain' => 'shop.acme.com', 'primary' => true, 'type' => 'main'],
                ['domain' => 'www.shop.acme.com', 'alias' => true],
                ['domain' => 'shop-4f2a.panelalpha.online', 'tunnel' => 'panelalpha'],
            ],
            'details' => ['domain' => ['source' => 'panelalpha_online', 'publicly_resolvable' => true]],
        ]));

        $this->assertSame('shop.acme.com', $report['domain']['primary']);
        $this->assertSame([
            ['domain' => 'shop.acme.com', 'type' => 'main'],
            ['domain' => 'www.shop.acme.com', 'alias' => true],
            ['domain' => 'shop-4f2a.panelalpha.online', 'tunnel' => 'panelalpha'],
        ], $report['domain']['names']);
        // The categories still travel beside them.
        $this->assertSame('panelalpha_online', $report['domain']['source']);
    }

    /**
     * Every engine has the domain rows, so every engine can name the project
     * — including one whose deploy predates `details.domain` entirely.
     */
    public function test_the_names_arrive_even_with_no_domain_snapshot(): void
    {
        $report = DeployReport::build($this->input([
            'domains' => [['domain' => 'shop.acme.com', 'primary' => true]],
        ]));

        $this->assertSame('shop.acme.com', $report['domain']['primary']);
        $this->assertArrayNotHasKey('source', $report['domain']);
    }

    public function test_the_names_list_is_bounded_and_shape_checked(): void
    {
        $many = [];
        for ($i = 0; $i < 120; $i++) {
            $many[] = ['domain' => "host{$i}.example.com"];
        }
        // Entries that are not an object, and objects with no name, are noise
        // rather than an error: this list is assembled inside the engine, but
        // a report that can be made malformed is a report that stops arriving.
        $many[] = 'not-an-object';
        $many[] = ['primary' => true];

        $report = DeployReport::build($this->input(['domains' => $many]));

        $this->assertCount(DeployReport::MAX_NAMES, $report['domain']['names']);
    }

    public function test_a_report_with_no_domains_carries_no_names(): void
    {
        $this->assertArrayNotHasKey('names', DeployReport::build($this->input())['domain'] ?? []);
    }

    public function test_a_working_online_name_reports_the_proxy_that_terminates_it(): void
    {
        $report = DeployReport::build($this->input(['details' => [
            'domain' => [
                'source' => 'panelalpha_online',
                'publicly_resolvable' => true,
                'tls_terminated_at' => 'proxy',
                'tunnel' => 'panelalpha',
                'fallback_reason' => null,
            ],
            'ssl' => ['status' => 'self_signed', 'issuer' => 'PanelAlpha'],
        ]]));

        $this->assertTrue($report['domain']['publicly_resolvable']);
        $this->assertSame('proxy', $report['domain']['tls_terminated_at']);
        $this->assertSame('panelalpha', $report['domain']['tunnel']);
        $this->assertArrayNotHasKey('fallback_reason', $report['domain']);
    }

    public function test_an_unknown_resolvability_is_omitted_rather_than_sent_as_false(): void
    {
        $report = DeployReport::build($this->input(['details' => [
            'domain' => ['source' => 'sites_base_domain', 'publicly_resolvable' => null],
        ]]));

        $this->assertSame('sites_base_domain', $report['domain']['source']);
        $this->assertArrayNotHasKey('publicly_resolvable', $report['domain']);
    }

    public function test_a_project_with_no_domain_snapshot_carries_no_domain_block(): void
    {
        $this->assertArrayNotHasKey('domain', DeployReport::build($this->input()));
    }

    public function test_the_fallback_reason_is_redacted(): void
    {
        $report = DeployReport::build($this->input(['details' => [
            'domain' => [
                'source' => 'local',
                'fallback_reason' => 'panelalpha_online: ' . self::USERNAME . '-4f2a was refused',
            ],
        ]]));

        $this->assertStringNotContainsString(self::USERNAME, $report['domain']['fallback_reason']);
    }

    public function test_recognised_failure_carries_its_rule(): void
    {
        $report = DeployReport::build($this->input());

        $this->assertSame('disk-full', $report['failure']['rule']);
        $this->assertTrue($report['failure']['explained']);
        $this->assertSame('running', $report['failure']['stage']);
    }

    public function test_unrecognised_failure_is_marked_unexplained(): void
    {
        $report = DeployReport::build($this->input(['error' => 'something nobody has a rule for yet']));

        $this->assertNull($report['failure']['rule']);
        $this->assertFalse($report['failure']['explained']);
        $this->assertStringContainsString('nobody has a rule', $report['failure']['signature']);
    }

    /**
     * The single most important property of the whole feature.
     */
    public function test_no_report_contains_the_account_name(): void
    {
        foreach ([DeployReport::TIER_METADATA, DeployReport::TIER_REPO, DeployReport::TIER_LOG] as $tier) {
            $report = DeployReport::build($this->input([
                'tier' => $tier,
                'error' => 'cp: cannot stat /home/' . self::USERNAME . '/project/app.js',
                'log_tail' => ['building in /home/' . self::USERNAME . '/project'],
            ]));

            $this->assertStringNotContainsString(
                self::USERNAME,
                (string) json_encode($report),
                "username leaked at tier {$tier}"
            );
        }
    }

    public function test_tier_zero_carries_neither_repository_nor_log(): void
    {
        $report = DeployReport::build($this->input(['tier' => DeployReport::TIER_METADATA]));

        $this->assertArrayNotHasKey('repo', $report);
        $this->assertArrayNotHasKey('log_tail', $report);
        $this->assertArrayNotHasKey('git_commit', $report['deploy']);
        $this->assertSame('nextjs', $report['deploy']['strategy']);
    }

    public function test_tier_one_names_a_public_repository_in_the_clear(): void
    {
        $report = DeployReport::build($this->input(['tier' => DeployReport::TIER_REPO]));

        $this->assertSame('github.com', $report['repo']['host']);
        $this->assertSame('vercel/next.js', $report['repo']['path']);
        $this->assertFalse($report['repo']['private']);
        $this->assertArrayNotHasKey('log_tail', $report);
    }

    public function test_a_private_repository_is_hashed_and_loses_its_commit(): void
    {
        $report = DeployReport::build($this->input([
            'repo_url' => 'https://gitlab.com/acme/internal-crm.git',
            'repo_private' => true,
        ]));

        $this->assertSame('gitlab.com', $report['repo']['host']);
        $this->assertArrayNotHasKey('path', $report['repo']);
        $this->assertNotEmpty($report['repo']['path_hash']);
        $this->assertNull($report['deploy']['git_commit']);
        $this->assertStringNotContainsString('internal-crm', (string) json_encode($report));
    }

    /**
     * @return array<string, mixed>
     */
    private function checkout(array $overrides = []): array
    {
        return array_replace([
            'present' => true,
            'remote' => 'https://github.com/vercel/next.js.git',
            'branch' => 'main',
            'detached' => false,
            'commit' => '0d2f3a4b5c6d7e8f90a1b2c3d4e5f60718293a4b',
            'committed_at' => 1756137600 - (11 * 86400) - 3600,
            'shallow' => true,
            'submodules' => false,
            'lfs' => true,
        ], $overrides);
    }

    public function test_the_checkout_says_what_the_build_had_to_resolve(): void
    {
        $report = DeployReport::build($this->input(['checkout' => $this->checkout()]));

        $this->assertTrue($report['repo']['checked_out']);
        $this->assertTrue($report['repo']['shallow']);
        $this->assertTrue($report['repo']['lfs']);
        $this->assertFalse($report['repo']['submodules']);
        $this->assertSame('main', $report['repo']['branch']);
        // Whole days since the commit, not the second it was made.
        $this->assertSame(11, $report['repo']['age_days']);
        $this->assertStringNotContainsString('1756', (string) json_encode($report['repo']));
    }

    public function test_a_report_with_no_checkout_claims_nothing_about_one(): void
    {
        $report = DeployReport::build($this->input());

        $this->assertArrayNotHasKey('checked_out', $report['repo']);
        $this->assertArrayNotHasKey('branch', $report['repo']);
        $this->assertArrayNotHasKey('age_days', $report['repo']);
        $this->assertSame('vercel/next.js', $report['repo']['path']);
    }

    /**
     * The branch is the customer's own string -- `feature/acme-migration`
     * names their work -- so it follows the repository rule the path follows.
     */
    public function test_a_private_repository_keeps_its_branch_to_itself(): void
    {
        $report = DeployReport::build($this->input([
            'repo_url' => 'https://gitlab.com/acme/internal-crm.git',
            'repo_private' => true,
            'checkout' => $this->checkout(['branch' => 'feature/acme-migration']),
        ]));

        $this->assertArrayNotHasKey('branch', $report['repo']);
        $this->assertStringNotContainsString('acme', (string) json_encode($report));
        // The shape of the clone is not the customer's string, and a build
        // that has to resolve lfs fails the same way on any repository.
        $this->assertTrue($report['repo']['lfs']);
        $this->assertSame(11, $report['repo']['age_days']);
    }

    /**
     * The case the account record could never answer: a site connected to a
     * remote after it was created, or an archive that turned out to carry a
     * .git. Both used to report `{"present": false}`.
     */
    public function test_a_repository_only_the_disk_knew_about_is_reported(): void
    {
        $report = DeployReport::build($this->input([
            'repo_url' => 'https://github.com/acme/widget.git',
            'repo_source' => 'checkout',
            'details' => ['deploy_source' => 'archive'],
            'checkout' => $this->checkout(['remote' => 'https://github.com/acme/widget.git']),
        ]));

        $this->assertTrue($report['repo']['present']);
        $this->assertSame('acme/widget', $report['repo']['path']);
        $this->assertSame('checkout', $report['repo']['source']);
        // The deploy still says how the source arrived; the repo says what
        // was in it. Those are two different answers and both are true.
        $this->assertSame('archive', $report['deploy']['source']);
    }

    public function test_a_checkout_with_no_remote_is_a_repository_nobody_can_name(): void
    {
        $report = DeployReport::build($this->input([
            'repo_url' => null,
            'checkout' => $this->checkout(['remote' => null]),
        ]));

        $this->assertTrue($report['repo']['present']);
        $this->assertFalse($report['repo']['parsed']);
        $this->assertTrue($report['repo']['checked_out']);
        $this->assertArrayNotHasKey('host', $report['repo']);
    }

    public function test_no_repository_and_no_checkout_is_still_the_plain_answer(): void
    {
        $report = DeployReport::build($this->input([
            'repo_url' => null,
            'checkout' => ['present' => false],
        ]));

        $this->assertSame(['present' => false], $report['repo']);
    }

    /**
     * The snapshot is what the deploy built; the checkout is only asked when
     * there is no snapshot. A rebuild that pulled in between would otherwise
     * report a commit that never went through the pipeline.
     */
    public function test_the_frozen_commit_wins_over_the_one_on_disk(): void
    {
        $report = DeployReport::build($this->input(['checkout' => $this->checkout()]));

        $this->assertSame('abcdef123456', $report['deploy']['git_commit']);
    }

    public function test_the_checkout_supplies_the_commit_nobody_froze(): void
    {
        $report = DeployReport::build($this->input([
            'details' => ['deploy_strategy' => 'nextjs'],
            'checkout' => $this->checkout(),
        ]));

        $this->assertSame('0d2f3a4b5c6d', $report['deploy']['git_commit']);
    }

    public function test_the_checkout_says_nothing_at_tier_zero(): void
    {
        $report = DeployReport::build($this->input([
            'tier' => DeployReport::TIER_METADATA,
            'checkout' => $this->checkout(),
        ]));

        $this->assertArrayNotHasKey('repo', $report);
        $this->assertStringNotContainsString('main', (string) json_encode($report));
    }

    /**
     * A clock that disagrees with the commit, or a commit dated in the future.
     * Reporting a negative age would be worse than reporting none.
     */
    public function test_an_age_that_cannot_be_true_is_left_out(): void
    {
        $report = DeployReport::build($this->input([
            'checkout' => $this->checkout(['committed_at' => 1756137600 + 86400]),
        ]));

        $this->assertArrayNotHasKey('age_days', $report['repo']);
    }

    public function test_tier_two_attaches_a_redacted_log_tail(): void
    {
        $report = DeployReport::build($this->input([
            'log_tail' => ['connecting to postgres://app:hunter2@db:5432/main'],
        ]));

        $this->assertNotEmpty($report['log_tail']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($report['log_tail']));
    }

    public function test_timings_are_derived_per_stage_with_a_total(): void
    {
        $report = DeployReport::build($this->input());

        $this->assertSame(41, $report['timings']['preparing']);
        $this->assertSame(6, $report['timings']['cloning']);
        $this->assertSame(187, $report['timings']['running']);
        $this->assertSame(234, $report['timings']['total']);
    }

    public function test_an_unfinished_stage_is_skipped_rather_than_counted_as_zero(): void
    {
        $report = DeployReport::build($this->input([
            'latest' => [
                'stage' => 'running',
                'stages' => [
                    ['name' => 'preparing', 'started_at' => 100, 'finished_at' => 141],
                    ['name' => 'running', 'started_at' => 141, 'finished_at' => null],
                ],
            ],
        ]));

        $this->assertArrayNotHasKey('running', $report['timings']);
        $this->assertSame(41, $report['timings']['total']);
    }

    public function test_only_known_manifests_travel_never_customer_file_names(): void
    {
        $report = DeployReport::build($this->input([
            'manifests' => ['package.json', 'our-secret-product-plan.md', 'Dockerfile'],
        ]));

        $this->assertSame(['dockerfile', 'package.json'], $report['deploy']['manifests']);
    }

    /**
     * `strategy` is what `html` and `static` share; `platform` is what tells
     * them apart. Grouping two recipes that fail for different reasons under
     * one strategy is grouping two bugs into one.
     */
    public function test_the_recipe_that_ran_travels_alongside_the_strategy_it_shares(): void
    {
        $report = DeployReport::build($this->input(['details' => [
            'deploy_strategy' => 'static',
            'deploy_platform' => 'html',
            'deploy_label' => 'HTML',
        ]]));

        $this->assertSame('static', $report['deploy']['strategy']);
        $this->assertSame('html', $report['deploy']['platform']);
    }

    /** An engine too old to have recorded one says so, rather than guessing. */
    public function test_a_deploy_that_recorded_no_recipe_reports_none(): void
    {
        $report = DeployReport::build($this->input());

        $this->assertNull($report['deploy']['platform']);
    }

    /**
     * The alternatives are the half that makes a per-recipe failure rate
     * actionable: what the chosen recipe was chosen over.
     */
    public function test_the_recipes_that_could_have_run_travel_in_order(): void
    {
        $report = DeployReport::build($this->input([
            'candidates' => ['dockerfile', 'laravel', 'railpack'],
        ]));

        $this->assertSame(['dockerfile', 'laravel', 'railpack'], $report['deploy']['candidates']);
    }

    /**
     * Recipe ids are the engine's own vocabulary, and a report that a stray
     * manifest can make malformed is a report that stops being sent.
     */
    public function test_candidates_are_bounded_and_shape_checked(): void
    {
        $report = DeployReport::build($this->input([
            'candidates' => array_merge(
                ['laravel', 'laravel', 'Not An Id', '../../etc/passwd', 42],
                array_map(static fn (int $i): string => "recipe-{$i}", range(1, 20))
            ),
        ]));

        $candidates = $report['deploy']['candidates'];

        $this->assertCount(12, $candidates);
        $this->assertSame('laravel', $candidates[0]);
        $this->assertNotContains('Not An Id', $candidates);
        $this->assertNotContains('../../etc/passwd', $candidates);
    }

    /** A deploy nothing gathered for still produces a report. */
    public function test_candidates_default_to_an_empty_list(): void
    {
        $report = DeployReport::build($this->input());

        $this->assertSame([], $report['deploy']['candidates']);
    }

    public function test_a_recovered_signal_names_itself_instead_of_being_explained(): void
    {
        $report = DeployReport::build($this->input([
            'outcome' => DeployReport::OUTCOME_RECOVERED,
            'signal' => 'port-realigned',
            'error' => 'Recipe expected port 3000, application bound 8090',
        ]));

        $this->assertSame('port-realigned', $report['failure']['rule']);
        $this->assertTrue($report['failure']['explained']);
        $this->assertSame('recovered', $report['outcome']);
    }

    public function test_success_and_cancelled_are_not_reportable(): void
    {
        $this->assertTrue(DeployReport::isReportable(DeployReport::OUTCOME_FAILED));
        $this->assertTrue(DeployReport::isReportable(DeployReport::OUTCOME_PARTIAL));
        $this->assertTrue(DeployReport::isReportable(DeployReport::OUTCOME_RECOVERED));
        // Sent too: without the successes there is no denominator for the
        // failures.
        $this->assertTrue(DeployReport::isReportable(DeployReport::OUTCOME_SUCCESS));
        // The customer stopping their own deploy is not an outcome of one.
        $this->assertFalse(DeployReport::isReportable(DeployReport::OUTCOME_CANCELLED));
    }

    public function test_tier_is_clamped_into_range(): void
    {
        $this->assertSame(DeployReport::TIER_METADATA, DeployReport::clampTier(-4));
        $this->assertSame(DeployReport::TIER_LOG, DeployReport::clampTier(99));
    }

    public function test_parses_scp_style_and_https_remotes(): void
    {
        $this->assertSame(
            ['host' => 'github.com', 'path' => 'owner/repo'],
            DeployReport::parseRepoUrl('git@github.com:owner/repo.git')
        );
        $this->assertSame(
            ['host' => 'github.com', 'path' => 'owner/repo'],
            DeployReport::parseRepoUrl('https://github.com/owner/repo')
        );
        $this->assertNull(DeployReport::parseRepoUrl('not a url'));
    }

    public function test_two_installs_hitting_the_same_bug_share_a_fingerprint(): void
    {
        $one = DeployReport::build($this->input(['install_id' => 'install-a', 'username' => 'aaa1']));
        $two = DeployReport::build($this->input(['install_id' => 'install-b', 'username' => 'bbb2']));

        $this->assertSame($one['fingerprint'], $two['fingerprint']);
        $this->assertNotSame($one['account'], $two['account']);
    }

    public function test_says_which_application_failed_when_a_package_file_named_one(): void
    {
        $report = DeployReport::build($this->input([
            'packages' => [
                new AppPackage(
                    ecosystem: 'php',
                    file: 'composer.json',
                    name: 'getgrav/grav',
                    nameSource: 'composer.json name',
                    frameworks: [new Framework('laravel', 'Laravel', '^11.0', '11.9.2', 'composer.lock')],
                    dependencyCounts: ['require' => 12],
                    platform: ['php' => '^8.2', 'ext-gd' => '*']
                ),
            ],
        ]));

        $this->assertSame('getgrav/grav', $report['app']['name']);
        $this->assertSame('php', $report['app']['ecosystem']);
        $this->assertSame(11, $report['app']['frameworks'][0]['major']);
        $this->assertSame('^8.2', $report['app']['platform']['php']);
    }

    public function test_a_project_no_reader_recognises_carries_no_app_key(): void
    {
        $this->assertArrayNotHasKey('app', DeployReport::build($this->input()));
        $this->assertArrayNotHasKey('app', DeployReport::build($this->input(['packages' => []])));
    }

    public function test_ignores_anything_in_packages_that_is_not_a_package(): void
    {
        $report = DeployReport::build($this->input(['packages' => ['composer.json', null, 42]]));

        $this->assertArrayNotHasKey('app', $report);
    }
}
