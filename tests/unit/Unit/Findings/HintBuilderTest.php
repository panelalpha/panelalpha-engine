<?php

namespace Tests\Unit\Findings;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\Inspect\Report\PortsReport;
use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Finding;
use App\Lib\Findings\Hints\FindingPresenter;
use App\Lib\Findings\Hints\HintBuilder;
use App\Lib\Project\CreateInspection;
use App\Mcp\ToolPolicy;
use App\System\Project\Dind\AppHealth;
use Tests\TestCase;

class HintBuilderTest extends TestCase
{
    private const DOMAIN = 'shop.136-243-229-118.panelalpha.direct';

    private function builder(array $policy = []): HintBuilder
    {
        return new HintBuilder(new ToolPolicy($policy + ['toolsets' => 'all', 'permission_mode' => ToolPolicy::MODE_FULL]));
    }

    public function test_a_refused_certificate_gets_one_hint_per_surface(): void
    {
        $this->assertSame([
            [
                'type' => 'rest',
                'action' => 'request_certificate',
                'method' => 'POST',
                'path' => '/projects/{username}/domains/{domain}/request-ssl-cert',
                'path_params' => ['username' => 'shop', 'domain' => self::DOMAIN],
            ],
            [
                'type' => 'mcp',
                'action' => 'request_certificate',
                'tool' => 'ssl_cert_request',
                'arguments' => ['name' => 'shop', 'domain' => self::DOMAIN],
            ],
            [
                'type' => 'cli',
                'action' => 'request_certificate',
                'command' => 'pae ssl:project-cert:request '.self::DOMAIN,
            ],
        ], self::only('request_certificate', $this->builder()->for(PublicUrl::CERTIFICATE_REFUSED, ['domain' => self::DOMAIN, 'username' => 'shop'])));
    }

    public function test_a_tool_this_engine_does_not_expose_gets_no_mcp_hint(): void
    {
        $hints = $this->builder(['denied' => 'ssl_cert_request'])
            ->for(PublicUrl::CERTIFICATE_REFUSED, ['domain' => self::DOMAIN, 'username' => 'shop']);

        $this->assertSame(['rest', 'cli'], array_column(self::only('request_certificate', $hints), 'type'));
        $this->assertSame(['rest', 'mcp'], array_column(self::only('check_certificate', $hints), 'type'));

        $readOnly = $this->builder(['permission_mode' => ToolPolicy::MODE_READONLY])
            ->for(PublicUrl::CERTIFICATE_REFUSED, ['domain' => self::DOMAIN, 'username' => 'shop']);
        $this->assertSame(['rest', 'cli'], array_column(self::only('request_certificate', $readOnly), 'type'));
        $this->assertSame(['rest', 'mcp'], array_column(self::only('check_certificate', $readOnly), 'type'));
    }

    public function test_a_certificate_cannot_be_requested_for_a_name_that_does_not_resolve(): void
    {
        $hints = $this->builder()->for(
            PublicUrl::CERTIFICATE_REFUSED,
            ['domain' => 'shop.local', 'username' => 'shop'],
            [PublicUrl::UNRESOLVABLE, PublicUrl::CERTIFICATE_REFUSED],
        );

        $this->assertSame(['check_certificate', 'check_certificate'], array_column($hints, 'action'));
    }

    public function test_what_nobody_knows_is_left_for_the_caller(): void
    {
        $hints = $this->builder()->for(PublicUrl::UNRESOLVABLE, ['domain' => 'shop.local', 'username' => null]);

        $this->assertSame([
            [
                'type' => 'rest',
                'action' => 'add_domain',
                'method' => 'POST',
                'path' => '/projects/{username}/domains',
                'path_params' => ['username' => null],
                'body' => ['domain' => null],
            ],
            [
                'type' => 'mcp',
                'action' => 'add_domain',
                'tool' => 'domain_create',
                'arguments' => ['name' => null, 'domain' => null],
            ],
            [
                'type' => 'cli',
                'action' => 'add_domain',
                'command' => 'pae domain:create <domain> --project=<username>',
            ],
        ], $hints);
    }

    public function test_a_secondary_port_is_routed_by_a_proxy_rule(): void
    {
        $hints = $this->builder()->for(PortsReport::SECONDARY_PORT, ['port' => 9001, 'username' => 'shop']);

        $this->assertSame(['route_port_http', 'route_port_http', 'route_port_tcp', 'route_port_tcp'], array_column($hints, 'action'));
        $this->assertSame(['rest', 'mcp', 'rest', 'mcp'], array_column($hints, 'type'));
        $this->assertSame([
            'username' => 'shop',
            'transport' => 'http',
            'listen_port' => null,
            'server_name' => null,
            'upstream_port' => 9001,
            'upstream_host' => 'shop',
        ], $hints[0]['body']);
        $this->assertSame('proxy_rule_create', $hints[1]['tool']);
    }

    public function test_inspect_adds_hints_to_a_secondary_port_only(): void
    {
        $ports = FindingPresenter::ports(['unrouted' => [
            ['port' => 5432, 'reason' => 'non_web', 'service' => 'postgres', 'routable' => false],
            ['port' => 9001, 'reason' => PortsReport::SECONDARY, 'routable' => true],
        ]], null);

        $this->assertArrayNotHasKey('hints', $ports['unrouted'][0]);
        $this->assertSame('/proxy-rules', $ports['unrouted'][1]['hints'][0]['path']);
        $this->assertNull($ports['unrouted'][1]['hints'][0]['body']['username']);
        $this->assertSame(9001, $ports['unrouted'][1]['hints'][0]['body']['upstream_port']);
    }

    public function test_a_create_that_may_not_deploy_gets_the_hints_of_its_code(): void
    {
        $inspection = FindingPresenter::inspection(
            ['verdict' => CreateInspection::NOT_DEPLOYABLE, 'strategy' => 'php', 'code' => DeployabilityCheck::ROOT_FILE_MISSING, 'reason' => 'r', 'suggestion' => 's'],
            'shop',
        );

        $this->assertSame(['inspect_with_recipe', 'inspect_with_recipe', 'redeploy_with_recipe', 'redeploy_with_recipe'], array_column($inspection['hints'], 'action'));
        $this->assertSame(['source' => 'shop', 'recipe' => null], $inspection['hints'][0]['body']);
        $this->assertSame(['verdict' => 'deployable', 'strategy' => 'php'], FindingPresenter::inspection(['verdict' => 'deployable', 'strategy' => 'php'], 'shop'));
        $this->assertNull(FindingPresenter::inspection(null, 'shop'));
    }

    public function test_inspect_says_when_no_port_was_found(): void
    {
        $ports = FindingPresenter::ports(['primary' => null, 'unrouted' => [], 'issue' => [
            'code' => PortsReport::PORT_NOT_DETECTED, 'message' => 'No port.', 'guessed_port' => 8080,
        ]], 'shop');

        $this->assertSame(PortsReport::PORT_NOT_DETECTED, $ports['issue']['code']);
        $this->assertSame('route_domain_to_port', $ports['issue']['hints'][0]['action']);
        $this->assertNull(FindingPresenter::ports(['primary' => 80, 'issue' => null], 'shop')['issue']);
    }

    public function test_a_value_a_shell_would_split_is_quoted(): void
    {
        $hints = $this->builder()->for(PublicUrl::CERTIFICATE_REFUSED, ['domain' => 'a b;c', 'username' => 'shop']);

        $this->assertSame("pae ssl:project-cert:request 'a b;c'", self::only('request_certificate', $hints)[2]['command']);
    }

    public function test_a_failure_out_of_memory_offers_a_higher_limit_then_the_log_and_a_redeploy(): void
    {
        $hints = $this->builder()->for('out-of-memory', ['username' => 'shop']);

        $this->assertSame(
            ['raise_memory_limit', 'raise_memory_limit', 'raise_memory_limit', 'read_deploy_log', 'read_deploy_log', 'read_deploy_log', 'redeploy', 'redeploy', 'redeploy'],
            array_column($hints, 'action'),
        );
        $this->assertSame(['memory_limit' => null], $hints[0]['body']);
        $this->assertSame('pae project:limit:set --project=shop --memory-limit=<memory_limit>', $hints[2]['command']);
    }

    public function test_every_failure_can_be_read_and_deployed_again(): void
    {
        foreach (DeployFailureExplainer::failureCodes() as $code) {
            $actions = array_unique(array_column($this->builder()->for($code, ['username' => 'shop']), 'action'));

            $this->assertContains('read_deploy_log', $actions, $code);
            $this->assertContains('redeploy', $actions, $code);
        }
    }

    public function test_an_operator_only_fix_has_only_a_cli_form(): void
    {
        $hints = array_values(array_filter(
            $this->builder()->for('registry-rate-limited', ['username' => 'shop']),
            static fn (array $hint): bool => $hint['action'] === 'log_in_to_docker_hub',
        ));

        $this->assertSame([['type' => 'cli', 'action' => 'log_in_to_docker_hub', 'command' => 'pae docker-hub:login --username=<hub_user> --token-stdin']], $hints);
    }

    public function test_a_refusal_offers_another_recipe(): void
    {
        $hints = $this->builder()->for(DeployabilityCheck::ROOT_FILE_MISSING, ['file' => 'composer.json', 'username' => 'shop']);

        $this->assertSame(['inspect_with_recipe', 'inspect_with_recipe', 'redeploy_with_recipe', 'redeploy_with_recipe'], array_column($hints, 'action'));
        $this->assertSame(['source' => null, 'recipe' => null], $hints[0]['body']);
        $this->assertSame(['recipe' => null], $hints[2]['body']);
        $this->assertSame(['name' => 'shop', 'recipe' => null], $hints[3]['arguments']);
    }

    public function test_a_port_nobody_named_is_routed_by_hand(): void
    {
        $hints = $this->builder()->for(PortsReport::PORT_NOT_DETECTED, ['username' => 'shop', 'domain' => 'shop.example.com']);

        $this->assertSame('route_domain_to_port', $hints[0]['action']);
        $this->assertSame(
            ['username' => 'shop', 'transport' => 'http', 'listen_port' => 443, 'server_name' => 'shop.example.com', 'upstream_port' => null, 'upstream_host' => 'shop'],
            $hints[0]['body'],
        );
        $this->assertSame(['username' => 'shop', 'service' => 'app'], $hints[2]['path_params']);
    }

    public function test_a_code_with_no_action_has_no_hints(): void
    {
        $this->assertSame([], $this->builder()->for(Finding::UNCLASSIFIED, ['username' => 'shop']));
        $this->assertSame([], $this->builder()->for('a_code_from_a_newer_engine', ['username' => 'shop']));
    }

    public function test_the_presenter_adds_hints_to_stored_findings_and_legacy_sentences(): void
    {
        $presented = FindingPresenter::present([
            'An old warning.',
            (new Finding(AppHealth::CODE_NOT_ANSWERING, AppHealth::NOT_ANSWERING))->toArray(),
        ], 'shop');

        $this->assertSame(['code' => Finding::UNCLASSIFIED, 'message' => 'An old warning.', 'hints' => []], $presented[0]);
        $this->assertSame(AppHealth::CODE_NOT_ANSWERING, $presented[1]['code']);
        $this->assertSame('pae project:deploy:log shop', end($presented[1]['hints'])['command']);
    }

    /**
     * @param  list<array<string, mixed>>  $hints
     * @return list<array<string, mixed>>
     */
    private static function only(string $action, array $hints): array
    {
        return array_values(array_filter($hints, static fn (array $hint): bool => $hint['action'] === $action));
    }
}
