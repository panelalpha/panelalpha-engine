<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\Networking;
use Tests\TestCase;

/**
 * A compose recipe's own `port:` is the port the site is routed to, even when
 * the port scan refuses it as a datastore's (Qdrant on 6333).
 */
class NetworkingRecipePortTest extends TestCase
{
    /**
     * @param array<string, mixed> $details
     */
    private function user(array $details): User
    {
        $user = new User();
        $user->details = $details;

        return $user;
    }

    public function test_a_compose_recipes_port_wins(): void
    {
        $this->assertSame(6333, Networking::recipeComposePort(
            $this->user(['deploy_strategy' => 'compose', 'deploy_port' => 6333])
        ));
    }

    public function test_a_compose_deploy_without_a_recipe_port_keeps_the_scan(): void
    {
        $this->assertNull(Networking::recipeComposePort($this->user(['deploy_strategy' => 'compose', 'deploy_port' => null])));
        $this->assertNull(Networking::recipeComposePort($this->user(['deploy_strategy' => 'compose'])));
        $this->assertNull(Networking::recipeComposePort($this->user(['deploy_strategy' => 'compose', 'deploy_port' => 70000])));
    }

    /** Other strategies generate their own compose, which the scan reads correctly. */
    public function test_other_strategies_keep_the_scan(): void
    {
        $this->assertNull(Networking::recipeComposePort(
            $this->user(['deploy_strategy' => 'dockerfile', 'deploy_port' => 6333])
        ));
    }

    /** The health check probes the routed port too, or it reports nothing to probe. */
    public function test_the_health_check_probes_the_recipe_port(): void
    {
        $this->assertSame([6333], AppHealth::withRecipePort([], 6333));
        $this->assertSame([6333, 8080], AppHealth::withRecipePort([6333, 8080], 6333));
        $this->assertSame([8080], AppHealth::withRecipePort([8080], null));
    }
}
