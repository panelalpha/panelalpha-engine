<?php

namespace Tests\Integration\Project;

use App\Lib\Domains\DomainPlan;
use App\Lib\Project\NewProjectRules;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * A PanelAlpha Online tunnel serves the name it is attached to, so a create that names another domain with one is
 * refused, while one that names its panelalpha.online name or none at all goes through.
 */
class NewProjectRulesTunnelTest extends TestCase
{
    public function test_an_online_tunnel_with_a_domain_of_its_own_is_refused(): void
    {
        $errors = $this->errors('shop.example.com', DomainPlan::TUNNEL_PANELALPHA);

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('A PanelAlpha Online tunnel serves the name it is attached to', $errors[0]);
        $this->assertStringEndsWith('use the tunnels endpoint for shop.example.com.', $errors[0]);
    }

    public function test_an_online_tunnel_on_its_own_name_or_without_one_is_accepted(): void
    {
        $this->assertSame([], $this->errors('shop.'.DomainPlan::PANELALPHA_ONLINE, DomainPlan::TUNNEL_PANELALPHA));
        $this->assertSame([], $this->errors(null, DomainPlan::TUNNEL_PANELALPHA));
        $this->assertSame([], $this->errors('shop.example.com', DomainPlan::TUNNEL_NONE));
    }

    /** @return list<string> */
    private function errors(?string $domain, string $tunnel): array
    {
        $rules = NewProjectRules::rules('name', $domain, true);

        return Validator::make(['tunnel' => $tunnel], ['tunnel' => $rules['tunnel']])->errors()->get('tunnel');
    }
}
