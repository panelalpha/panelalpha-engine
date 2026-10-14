<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\BugReport;
use App\Lib\Deploy\Telemetry\DeployReport;
use PHPUnit\Framework\TestCase;

/**
 * A bug report is the one payload here whose body a person wrote, which makes
 * it the one place where "redact everything" and "keep what they said" pull
 * against each other. These tests pin both ends: secrets must not survive, and
 * the paragraph they typed must.
 *
 * The other half is the evidence the engine gathers around that paragraph —
 * what the application is and whether it is answering — which is what turns a
 * report into something reproducible. That must arrive, and must arrive
 * scrubbed.
 */
class BugReportTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function build(array $overrides = []): array
    {
        return BugReport::build($overrides + [
            'id' => '01JGXR8Q4M9ZK7W2N5T3V6B0AC',
            'occurred_at' => 1756137600,
            'tier' => DeployReport::TIER_LOG,
            'install_id' => str_repeat('a', 32),
            // Every bug report is about one application; there is no
            // project-less shape to test.
            'username' => 'shop',
            'title' => 'Deploys succeed but the site answers 502',
            'description' => 'It finishes green and then 502s until I restart the container.',
        ]);
    }

    public function test_it_is_marked_as_a_bug_and_not_as_a_deploy(): void
    {
        $report = $this->build();

        $this->assertSame(BugReport::KIND, $report['kind']);
        $this->assertSame(BugReport::OUTCOME, $report['outcome']);
        $this->assertSame(BugReport::SCHEMA, $report['schema']);
        // Both are what the spool and the ingest key on; a report missing
        // either is dropped before anyone reads it.
        $this->assertNotSame('', $report['id']);
        $this->assertNotSame('', $report['outcome']);
    }

    public function test_the_account_travels_as_a_hash_and_never_as_a_name(): void
    {
        $report = $this->build();

        // The same salted hash a deploy report uses, so a bug report and that
        // account's failed deploys line up on the receiving end without either
        // naming the customer.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $report['account']);
        $this->assertStringNotContainsString('shop', json_encode($report) ?: '');
    }

    /**
     * A bug report is about one application, and the reporter is usually
     * looking straight at it. The address is the first thing a reader wants,
     * and it arrives in the clear — the same real hostnames a deploy report
     * carries, built by the same method so the two cannot drift.
     */
    public function test_it_names_the_application_the_report_is_about(): void
    {
        $report = $this->build([
            'domains' => [
                ['domain' => 'shop.acme.com', 'primary' => true, 'type' => 'main'],
                ['domain' => 'shop-4f2a.panelalpha.online', 'tunnel' => 'panelalpha'],
            ],
            'details' => [
                'deploy_strategy' => 'nextjs',
                'domain' => ['source' => 'panelalpha_online', 'publicly_resolvable' => true],
            ],
        ]);

        $this->assertSame('shop.acme.com', $report['domain']['primary']);
        $this->assertSame('shop.acme.com', $report['domain']['names'][0]['domain']);
        $this->assertSame('panelalpha', $report['domain']['names'][1]['tunnel']);
        // The categories still travel beside the names.
        $this->assertSame('panelalpha_online', $report['domain']['source']);
    }

    public function test_a_bug_report_with_no_domains_carries_no_domain_block(): void
    {
        $this->assertArrayNotHasKey('domain', $this->build());
    }

    public function test_unknown_severities_and_areas_fall_back_rather_than_failing(): void
    {
        $report = $this->build(['severity' => 'APOCALYPTIC', 'area' => '  ']);

        $this->assertSame(BugReport::DEFAULT_SEVERITY, $report['bug']['severity']);
        $this->assertSame(BugReport::DEFAULT_AREA, $report['bug']['area']);
    }

    public function test_a_severity_is_recognised_whatever_the_case(): void
    {
        $this->assertSame('critical', $this->build(['severity' => ' Critical '])['bug']['severity']);
    }

    public function test_an_area_this_engine_has_never_heard_of_still_travels(): void
    {
        // The engine gains features faster than an install gets updated. An
        // area from a newer panel is a slug, so it is passed through.
        $report = $this->build(['area' => 'Object Storage']);

        $this->assertSame('object-storage', $report['bug']['area']);
    }

    public function test_the_contact_is_absent_unless_somebody_supplied_one(): void
    {
        $this->assertArrayNotHasKey('contact', $this->build()['bug']);
    }

    public function test_the_contact_is_the_one_field_that_is_not_redacted(): void
    {
        // Masking it would defeat the only reason it exists, which is that
        // support has to be able to answer the person who filed the report.
        $report = $this->build(['contact' => 'ops@example.com']);

        $this->assertSame('ops@example.com', $report['bug']['contact']);
    }

    public function test_a_secret_pasted_into_the_description_does_not_survive(): void
    {
        $report = $this->build([
            'description' => "It fails on clone.\nI am using ghp_abcdefghijklmnopqrstuvwxyz0123456789 as the token.",
        ]);

        $this->assertStringNotContainsString('ghp_abcdefghijklmnopqrstuvwxyz0123456789', $report['bug']['description']);
    }

    public function test_a_paragraph_is_not_cut_at_the_log_line_budget(): void
    {
        // Build output is a stream of short lines and capping each is right.
        // A report written as one paragraph is one line, and the 500-byte log
        // budget would amputate the half that says what went wrong.
        $paragraph = str_repeat('the deploy finishes and the site does not answer. ', 40);

        $description = $this->build(['description' => $paragraph])['bug']['description'];

        $this->assertGreaterThan(1500, strlen($description));
    }

    public function test_paragraph_breaks_the_author_made_are_kept(): void
    {
        $report = $this->build(['description' => "What happened.\n\nWhat I expected.\n\nHow to repeat it."]);

        $this->assertStringContainsString("\n\n", $report['bug']['description']);
    }

    public function test_a_title_is_flattened_to_one_line_and_capped(): void
    {
        $report = $this->build(['title' => "wrapped\ntitle   with   gaps"]);

        $this->assertSame('wrapped title with gaps', $report['bug']['title']);

        $long = $this->build(['title' => str_repeat('x', 400)])['bug']['title'];
        $this->assertLessThanOrEqual(BugReport::MAX_TITLE_BYTES + 4, strlen($long));
    }

    public function test_reports_of_one_bug_group_together_across_installs(): void
    {
        // The whole point of the inbox: two people describing one bug write
        // two different paragraphs and the same headline.
        $a = $this->build(['area' => 'ssl', 'description' => 'happens every morning']);
        $b = $this->build(['area' => 'ssl', 'description' => 'completely different words here']);

        $this->assertSame($a['fingerprint'], $b['fingerprint']);
    }

    public function test_the_same_bug_seen_on_two_different_objects_is_one_bug(): void
    {
        $a = $this->build(['title' => 'domain 41 has no certificate']);
        $b = $this->build(['title' => 'domain 87 has no certificate']);

        $this->assertSame($a['fingerprint'], $b['fingerprint']);
    }

    public function test_the_same_words_in_a_different_area_are_a_different_bug(): void
    {
        $a = $this->build(['area' => 'ssl']);
        $b = $this->build(['area' => 'deploy']);

        $this->assertNotSame($a['fingerprint'], $b['fingerprint']);
    }

    public function test_a_deploy_log_tail_is_tier_two_material(): void
    {
        $input = ['log_tail' => ['npm ERR! code ELIFECYCLE']];

        $this->assertArrayHasKey('log_tail', $this->build($input + ['tier' => DeployReport::TIER_LOG]));
        $this->assertArrayNotHasKey('log_tail', $this->build($input + ['tier' => DeployReport::TIER_REPO]));
        $this->assertArrayNotHasKey('log_tail', $this->build($input + ['tier' => DeployReport::TIER_METADATA]));
    }

    public function test_the_attached_log_tail_is_redacted_like_any_other(): void
    {
        $report = $this->build([
            'log_tail' => ['cloning https://user:hunter2@github.com/acme/site.git'],
        ]);

        $this->assertStringNotContainsString('hunter2', json_encode($report['log_tail']) ?: '');
    }

    public function test_a_project_that_has_never_deployed_gets_no_deploy_block(): void
    {
        // Absent, never a husk of nulls: that would read as "we deployed and
        // nothing was recorded", which is a different and untrue statement.
        $this->assertArrayNotHasKey('deploy', $this->build());
    }

    public function test_the_last_deploy_is_described_in_the_same_words_a_deploy_report_uses(): void
    {
        $report = $this->build([
            'latest' => ['id' => '20260825-131200-a1b2c3', 'status' => 'failed', 'stage' => 'running'],
            'details' => [
                'deploy_source' => 'git',
                'deploy_strategy' => 'nextjs',
                'deploy_label' => 'Next.js',
                'deploy_runtime' => 'node',
            ],
        ]);

        $this->assertSame([
            'id' => '20260825-131200-a1b2c3',
            'status' => 'failed',
            'stage' => 'running',
            'source' => 'git',
            'strategy' => 'nextjs',
            'label' => 'Next.js',
            'runtime' => 'node',
        ], $report['deploy']);
    }

    public function test_the_surface_it_was_filed_from_is_recorded(): void
    {
        $this->assertSame('cli', $this->build(['via' => 'cli'])['bug']['via']);
        $this->assertSame(BugReport::DEFAULT_VIA, $this->build()['bug']['via']);
    }

    public function test_what_the_application_is_travels_with_the_report(): void
    {
        // The half that turns a complaint into a reproduction: what the engine
        // detected, what it would build, and which ports it would publish.
        $report = $this->build([
            'inspect' => [
                'application' => ['strategy' => 'nextjs', 'runtime' => 'node', 'deployable' => true],
                'ports' => ['primary' => 3000, 'source' => 'platform'],
                'environment' => ['files' => ['.env.example'], 'variables' => ['DATABASE_URL', 'NODE_ENV']],
            ],
        ]);

        $this->assertSame('nextjs', $report['app']['inspect']['application']['strategy']);
        $this->assertSame(3000, $report['app']['inspect']['ports']['primary']);
        $this->assertSame(['DATABASE_URL', 'NODE_ENV'], $report['app']['inspect']['environment']['variables']);
    }

    public function test_what_the_application_is_doing_travels_with_the_report(): void
    {
        $report = $this->build([
            'health' => [
                'healthy' => true,
                'serving' => 'placeholder',
                'ports' => [['port' => 3000, 'status' => 'ok', 'http_code' => 200]],
                'checks' => [['id' => 'not-a-stock-default-page', 'status' => 'fail', 'severity' => 'error']],
            ],
        ]);

        // `healthy` and `serving` answer different questions, and the case
        // worth reporting is exactly the one where they disagree.
        $this->assertTrue($report['app']['health']['healthy']);
        $this->assertSame('placeholder', $report['app']['health']['serving']);
        $this->assertSame('fail', $report['app']['health']['checks'][0]['status']);
    }

    public function test_a_secret_in_the_gathered_evidence_does_not_survive_either(): void
    {
        $report = $this->build([
            'inspect' => [
                'environment' => [
                    // The inspector reports variable names, not values — but a
                    // report scrubbed by shape must not depend on that holding
                    // for every section of every future release.
                    'defaults' => ['NODE_ENV' => 'production', 'DATABASE_PASSWORD' => 'hunter2'],
                ],
                'services' => [['image' => 'registry.example.com/acme/api', 'token' => 'ghp_abcdefghijklmnopqrstuvwxyz01']],
            ],
        ]);

        $json = (string) json_encode($report);
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringNotContainsString('ghp_abcdefghijklmnopqrstuvwxyz01', $json);
        // Masked, not dropped: the reader still learns the variable was set.
        $this->assertSame('production', $report['app']['inspect']['environment']['defaults']['NODE_ENV']);
    }

    public function test_ports_and_status_codes_are_left_alone(): void
    {
        // There is nothing in an integer to leak, and they are the diagnostic.
        $report = $this->build([
            'health' => ['healthy' => false, 'ports' => [['port' => 8080, 'http_code' => 502, 'time' => 0.31]]],
        ]);

        $this->assertSame(8080, $report['app']['health']['ports'][0]['port']);
        $this->assertSame(502, $report['app']['health']['ports'][0]['http_code']);
        $this->assertSame(0.31, $report['app']['health']['ports'][0]['time']);
    }

    public function test_a_section_that_could_not_be_gathered_is_absent_not_empty(): void
    {
        // A project rolled back after a failed deploy has nothing to inspect;
        // one on the classic template has no container to probe. Absent says
        // that, an empty object would not.
        $health = $this->build(['health' => ['healthy' => null, 'ports' => []]])['app'];

        $this->assertArrayHasKey('health', $health);
        $this->assertArrayNotHasKey('inspect', $health);
        $this->assertArrayNotHasKey('app', $this->build());
    }

    public function test_a_flag_named_like_a_secret_is_still_a_flag(): void
    {
        // npm's `"private": true` was the one that turned up first. Reporting
        // it as "***" both looks like a leak was caught and loses the fact.
        $report = $this->build([
            'inspect' => ['metadata' => ['private' => true, 'name' => 'acme-storefront']],
        ]);

        $this->assertTrue($report['app']['inspect']['metadata']['private']);
    }

    public function test_evidence_is_tier_one_material_because_it_is_project_identity(): void
    {
        // An inspection is the package name, the version, the repository and
        // the names of the environment variables. Tier 0 is what an operator
        // sets when they have told their customers none of that leaves the
        // machine.
        $input = ['inspect' => ['application' => ['strategy' => 'nextjs']]];

        $this->assertSame(
            ['omitted' => 'tier'],
            $this->build($input + ['tier' => DeployReport::TIER_METADATA])['app']
        );
        $this->assertArrayHasKey(
            'inspect',
            $this->build($input + ['tier' => DeployReport::TIER_REPO])['app']
        );
    }

    public function test_a_public_project_is_named_in_the_clear(): void
    {
        // The field that turns a report into "write a page for this project".
        $report = $this->build([
            'inspect' => [
                'deployment' => ['repository' => 'https://github.com/acme/storefront'],
                'metadata' => ['name' => 'acme-storefront', 'version' => '3.2.0'],
            ],
        ]);

        $inspect = $report['app']['inspect'];
        $this->assertSame('https://github.com/acme/storefront', $inspect['deployment']['repository']);
        $this->assertSame('acme-storefront', $inspect['metadata']['name']);
    }

    public function test_a_private_project_is_hashed_the_way_a_deploy_report_hashes_it(): void
    {
        $report = $this->build([
            'repo_private' => true,
            'inspect' => [
                'deployment' => ['repository' => 'https://gitlab.example.com/acme/storefront.git'],
                'metadata' => [
                    'name' => 'acme-storefront',
                    'version' => '3.2.0',
                    'homepage' => 'https://storefront.acme.example',
                    // Somebody else's package, and the useful half of the
                    // report. It is public and it stays.
                    'framework' => ['id' => 'next', 'name' => 'Next.js', 'version' => '14.2.3'],
                ],
            ],
        ]);

        $inspect = $report['app']['inspect'];
        $json = (string) json_encode($report);

        // The host stays legible: thirty failures on one self-hosted GitLab is
        // worth knowing. Which repository is not ours to know.
        $this->assertStringStartsWith('gitlab.example.com/', $inspect['deployment']['repository']);
        $this->assertStringNotContainsString('acme/storefront', $json);
        $this->assertStringNotContainsString('acme-storefront', $json);
        $this->assertStringNotContainsString('storefront.acme.example', $json);
        $this->assertNull($inspect['metadata']['version']);

        $this->assertSame('Next.js', $inspect['metadata']['framework']['name']);
        $this->assertSame('14.2.3', $inspect['metadata']['framework']['version']);
    }

    public function test_a_private_project_is_not_named_by_the_log_tail_or_the_words(): void
    {
        $url = 'https://gitlab.example.com/acme/storefront.git';
        $report = $this->build([
            'repo_url' => $url,
            'repo_private' => true,
            'details' => ['git_branch' => 'release-7'],
            'title' => 'Clone of acme/storefront fails',
            'description' => "Every rebuild of {$url} stops at cloning.",
            'log_tail' => ["Cloning repository {$url} (branch: release-7)"],
        ]);

        $json = (string) json_encode($report);
        $this->assertStringNotContainsString('storefront', $json);
        $this->assertStringNotContainsString('release-7', $json);
        $this->assertSame('Clone of <repo> fails', $report['bug']['title']);
    }

    public function test_a_private_project_still_groups_with_itself(): void
    {
        // Hashed rather than dropped: repeat reports about one project have to
        // land in the same bucket.
        $input = ['repo_private' => true, 'inspect' => ['metadata' => ['name' => 'acme-storefront']]];

        $this->assertSame(
            $this->build($input)['app']['inspect']['metadata']['name'],
            $this->build($input)['app']['inspect']['metadata']['name']
        );
    }

    public function test_evidence_too_large_to_send_is_declared_rather_than_truncated(): void
    {
        // Half an inspection is a misleading bug report, the same way half a
        // repository is.
        // Ordinary readable text, not a run of one character: the redactor
        // masks high-entropy runs, so a lazy fixture shrinks instead of busting.
        $line = str_repeat('the deploy finishes and the site does not answer. ', 20);
        $huge = [];
        for ($i = 0; $i < 100; $i++) {
            $huge['service' . $i] = ['command' => $line];
        }

        $report = $this->build(['inspect' => ['services' => $huge, 'more' => $huge]]);

        $this->assertSame(['omitted' => 'too-large'], $report['app']);
    }
}
