<?php

namespace Tests\Unit\System\Services\Webserver;

use App\System\Services\Webserver\ProxyRuleUpstream;
use PHPUnit\Framework\TestCase;

/** A project's rule reaches its own app only; a system rule anything, and a rule naming no project nothing. */
class ProxyRuleUpstreamRefusalTest extends TestCase
{
    public function test_a_system_rule_and_a_projects_rule_to_its_own_app_are_allowed(): void
    {
        $this->assertNull(ProxyRuleUpstream::refusal('system', null, 'core'));
        $this->assertNull(ProxyRuleUpstream::refusal('user', 'alice', 'alice'));
    }

    public function test_a_project_rule_without_its_project_is_refused(): void
    {
        $this->assertSame("A project's rule needs its project.", ProxyRuleUpstream::refusal('user', null, 'alice'));
        $this->assertSame("A project's rule needs its project.", ProxyRuleUpstream::refusal('user', '', 'alice'));
    }

    public function test_a_project_rule_to_anything_else_names_the_only_upstream_it_may_use(): void
    {
        $this->assertStringStartsWith("The upstream must be the project's own app, 'alice'", (string) ProxyRuleUpstream::refusal('user', 'alice', 'bob'));
    }
}
