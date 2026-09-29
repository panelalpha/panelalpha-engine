<?php

namespace Tests\Unit\Mcp;

use App\Mcp\ToolPolicy;
use App\Mcp\Tools\Api\AppUsers\AppSsoLoginTool;
use App\Mcp\Tools\Api\CSF\CsfUiCredentialsTool;
use App\Mcp\Tools\Api\System\SystemEximConfigGetTool;
use App\Mcp\Tools\Api\Deploy\SourceInspectTool;
use App\Mcp\Tools\Api\Domains\DomainListTool;
use App\Mcp\Tools\Api\Projects\ProjectDeleteTool;
use App\Mcp\Tools\Api\Projects\ProjectCreateTool;
use App\Mcp\Tools\Api\Projects\ProjectListTool;
use App\Mcp\Tools\Api\Projects\ProjectUpdateTool;
use App\Mcp\Tools\Api\ServerMetrics\MetricsCurrentTool;
use App\Mcp\Tools\MetricsLatestTool;
use App\Mcp\Tools\ProjectListSummaryTool;
use Laravel\Mcp\Server\Tools\ToolSearch;
use Tests\TestCase;

class ToolPolicyTest extends TestCase
{
    private const SAMPLE = [
        MetricsLatestTool::class,
        ProjectListSummaryTool::class,
        ProjectListTool::class,
        ProjectCreateTool::class,
        ProjectUpdateTool::class,
        ProjectDeleteTool::class,
        DomainListTool::class,
        MetricsCurrentTool::class,
        // A POST that only reads. In the sample because the modes have to be
        // proved against one, not just against the verbs.
        SourceInspectTool::class,
    ];

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function names(array $config): array
    {
        $policy = new ToolPolicy($config + ['permission_mode' => 'full']);

        return array_map(
            fn (string $c): string => $policy->nameOf($c),
            $policy->filter(self::SAMPLE)
        );
    }

    public function test_layout_lists_direct_tools_and_catalogues_every_tool(): void
    {
        $policy = new ToolPolicy(['tool_search' => true, 'direct' => 'project_create, metrics_*']);

        $this->assertSame(
            [MetricsLatestTool::class, ProjectCreateTool::class, MetricsCurrentTool::class, ToolSearch::class => self::SAMPLE],
            $policy->layout(self::SAMPLE)
        );
    }

    public function test_layout_is_flat_when_tool_search_is_off_or_everything_is_direct(): void
    {
        $this->assertSame(self::SAMPLE, (new ToolPolicy(['tool_search' => false, 'direct' => 'project_create']))->layout(self::SAMPLE));
        $this->assertSame(self::SAMPLE, (new ToolPolicy(['tool_search' => 'false']))->layout(self::SAMPLE));
        $this->assertSame(self::SAMPLE, (new ToolPolicy(['tool_search' => true, 'direct' => '*']))->layout(self::SAMPLE));
    }

    public function test_everything_is_exposed_by_default(): void
    {
        $this->assertCount(count(self::SAMPLE), $this->names(['toolsets' => 'all']));
    }

    public function test_toolsets_limit_exposure_to_named_groups(): void
    {
        $names = $this->names(['toolsets' => 'engine,servermetrics']);

        $this->assertEqualsCanonicalizing(
            ['metrics_latest', 'project_list_summary', 'metrics_current'],
            $names
        );
    }

    public function test_individual_tools_can_be_added_on_top_of_toolsets(): void
    {
        $names = $this->names(['toolsets' => 'engine', 'tools' => 'project_list']);

        $this->assertContains('project_list', $names);
        $this->assertNotContains('project_delete', $names);
    }

    public function test_readonly_mode_removes_everything_that_writes(): void
    {
        $policy = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => 'readonly']);
        $names = array_map(fn (string $c): string => $policy->nameOf($c), $policy->filter(self::SAMPLE));

        $this->assertEqualsCanonicalizing(
            [
                'metrics_latest',
                'project_list_summary',
                'project_list',
                'domain_list',
                'metrics_current',
                'source_inspect',
            ],
            $names
        );
    }

    /**
     * source_inspect POSTs because a git token belongs in a body rather than a
     * URL, and it changes nothing: it clones into a temp directory, reports,
     * and deletes it. Classifying it by its verb withheld the one tool that
     * answers "what would this repository deploy as" from exactly the profile
     * that should have it.
     */
    public function test_a_post_that_only_reads_counts_as_a_read(): void
    {
        $policy = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => 'readonly']);

        $this->assertSame('POST', $policy->verbOf(SourceInspectTool::class));
        $this->assertTrue($policy->readsOnly(SourceInspectTool::class));
        $this->assertSame('read', $policy->accessOf(SourceInspectTool::class));
        $this->assertContains(SourceInspectTool::class, $policy->filter(self::SAMPLE));
    }

    /**
     * The exemption is per operation and declared, so a POST that was never
     * declared read-only stays a write in every mode.
     */
    public function test_an_ordinary_post_is_still_a_write(): void
    {
        $policy = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => 'readonly']);

        $this->assertFalse($policy->readsOnly(ProjectCreateTool::class));
        $this->assertSame('write', $policy->accessOf(ProjectCreateTool::class));
        $this->assertNotContains(ProjectCreateTool::class, $policy->filter(self::SAMPLE));
    }

    public function test_modify_mode_allows_writes_but_not_deletes(): void
    {
        $policy = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => 'modify']);
        $names = array_map(fn (string $c): string => $policy->nameOf($c), $policy->filter(self::SAMPLE));

        $this->assertContains('project_create', $names);
        $this->assertContains('project_update', $names);
        $this->assertNotContains('project_delete', $names);
    }

    public function test_denied_tools_accept_wildcards(): void
    {
        // Names are <resource>_<action>, so a suffix wildcard is what denies a
        // whole class of operation and a prefix wildcard denies a resource.
        $names = $this->names(['toolsets' => 'all', 'denied' => '*_delete']);

        $this->assertNotContains('project_delete', $names);
        $this->assertContains('project_list', $names);
    }

    public function test_denied_tools_wildcard_can_match_a_whole_resource(): void
    {
        $names = $this->names(['toolsets' => 'all', 'denied' => 'project_*']);

        $this->assertNotContains('project_delete', $names);
        $this->assertNotContains('project_suspend', $names);
        $this->assertContains('domain_list', $names);
    }

    public function test_denied_regex_removes_matching_tools(): void
    {
        $names = $this->names(['toolsets' => 'all', 'denied_regex' => '^project_(delete|create)$']);

        $this->assertNotContains('project_delete', $names);
        $this->assertNotContains('project_create', $names);
        $this->assertContains('project_list', $names);
    }

    /**
     * A denial has to be final, or "deny this one thing" quietly depends on
     * nothing else happening to name it.
     */
    public function test_a_denied_tool_stays_denied_even_when_named_in_the_allow_list(): void
    {
        $names = $this->names([
            'toolsets' => 'engine',
            'tools' => 'project_delete',
            'denied' => 'project_delete',
        ]);

        $this->assertNotContains('project_delete', $names);
    }

    /**
     * permission_mode is a ceiling, not a default. If naming a tool could lift
     * it, MCP_PERMISSION_MODE=readonly would guarantee nothing.
     */
    public function test_permission_mode_is_a_ceiling_the_allow_list_cannot_lift(): void
    {
        $policy = new ToolPolicy([
            'toolsets' => 'engine',
            'tools' => 'project_delete',
            'permission_mode' => 'readonly',
        ]);

        $names = array_map(fn (string $c): string => $policy->nameOf($c), $policy->filter(self::SAMPLE));

        $this->assertNotContains('project_delete', $names);
        $this->assertEqualsCanonicalizing(['metrics_latest', 'project_list_summary'], $names);
    }

    /**
     * A typo in the mode must not be read as "no restriction".
     */
    public function test_an_unrecognised_permission_mode_falls_back_to_readonly(): void
    {
        $policy = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => 'banana']);

        $this->assertSame(ToolPolicy::MODE_READONLY, $policy->mode());
        $this->assertNotContains(
            'project_delete',
            array_map(fn (string $c): string => $policy->nameOf($c), $policy->filter(self::SAMPLE))
        );
    }

    /**
     * An unusable pattern must not silently disable the denylist it belongs to,
     * nor take the server down.
     */
    public function test_an_invalid_denied_regex_is_ignored_rather_than_fatal(): void
    {
        $names = $this->names(['toolsets' => 'all', 'denied_regex' => '([unclosed']);

        $this->assertContains('project_list', $names);
    }

    public function test_toolsets_are_matched_case_insensitively(): void
    {
        $this->assertNotEmpty($this->names(['toolsets' => 'ServerMetrics']));
        $this->assertNotEmpty($this->names(['toolsets' => 'SERVERMETRICS']));
    }

    public function test_whitespace_around_list_entries_is_tolerated(): void
    {
        $names = $this->names(['toolsets' => ' engine , servermetrics ']);

        $this->assertContains('metrics_current', $names);
    }

    public function test_toolsets_are_derived_from_the_namespace(): void
    {
        $policy = new ToolPolicy([]);

        $this->assertSame('engine', $policy->toolsetOf(ProjectListSummaryTool::class));
        $this->assertSame('projects', $policy->toolsetOf(ProjectListTool::class));
        $this->assertSame('servermetrics', $policy->toolsetOf(MetricsCurrentTool::class));
    }

    public function test_hand_written_tools_count_as_reads(): void
    {
        $policy = new ToolPolicy([]);

        $this->assertSame('GET', $policy->verbOf(ProjectListSummaryTool::class));
        $this->assertSame('DELETE', $policy->verbOf(ProjectDeleteTool::class));
    }

    /**
     * The shipped default is every group. A new install hands the assistant the
     * whole surface, and narrowing it is the operator's deliberate act; this
     * fails if the default silently hides a group again.
     */
    public function test_the_shipped_default_exposes_every_group(): void
    {
        $default = (new ToolPolicy((array)config('mcp-tools')))->filter($this->everyTool());
        $everything = (new ToolPolicy(['toolsets' => 'all', 'permission_mode' => 'full']))->filter($this->everyTool());

        $this->assertSame(count($everything), count($default));

        $names = array_map(fn (string $c): string => (new $c())->name(), $default);

        foreach (['project_create', 'project_deploy_archive', 'domain_create', 'file_write', 'backup_restore'] as $needed) {
            $this->assertContains($needed, $names, "the default must keep {$needed}");
        }

        foreach (['csf_rule_create', 'modsec_mode_set', 'ip_assign', 'tunnel_create'] as $on) {
            $this->assertContains($on, $names, "the default must expose {$on}");
        }
    }

    private const CREDENTIAL_READS = [
        CsfUiCredentialsTool::class,
        SystemEximConfigGetTool::class,
        AppSsoLoginTool::class,
    ];

    /**
     * A GET that returns a password is a read by verb, but readonly means the
     * assistant may look, not log in (#48 item 20).
     */
    public function test_readonly_withholds_the_reads_that_return_a_credential(): void
    {
        $policy = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => 'readonly']);

        foreach (self::CREDENTIAL_READS as $class) {
            $this->assertSame('GET', $policy->verbOf($class));
            $this->assertFalse($policy->permits($class), $class);
            $this->assertSame([], $policy->filter([$class]), $class);
            $this->assertSame('secret', $policy->accessOf($class));
        }

        // An ordinary read next to them is untouched.
        $this->assertSame([DomainListTool::class], $policy->filter([DomainListTool::class]));
    }

    /** Naming one in MCP_TOOLS does not lift the ceiling either. */
    public function test_naming_a_credential_read_does_not_get_it_past_readonly(): void
    {
        $policy = new ToolPolicy([
            'toolsets' => 'engine',
            'tools' => 'csf_ui_credentials,system_exim_config_get',
            'permission_mode' => 'readonly',
        ]);

        $this->assertSame([], $policy->filter(self::CREDENTIAL_READS));
    }

    public function test_modify_and_full_keep_the_credential_reads(): void
    {
        foreach (['modify', 'full'] as $mode) {
            $policy = new ToolPolicy(['toolsets' => 'all', 'permission_mode' => $mode]);

            $this->assertSame(self::CREDENTIAL_READS, $policy->filter(self::CREDENTIAL_READS), $mode);
        }
    }

    /** A renamed tool would otherwise drop off the list without a sound. */
    public function test_every_listed_credential_tool_exists(): void
    {
        $names = array_map(fn (string $c): string => (new $c())->name(), $this->everyTool());

        foreach (ToolPolicy::CREDENTIAL_TOOLS as $name) {
            $this->assertContains($name, $names);
        }
    }

    /** @return array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    private function everyTool(): array
    {
        return array_merge(
            [\App\Mcp\Tools\MetricsLatestTool::class, \App\Mcp\Tools\ProjectListSummaryTool::class],
            require __DIR__ . '/../../../app/Mcp/Tools/Api/generated-tools.php'
        );
    }
}
