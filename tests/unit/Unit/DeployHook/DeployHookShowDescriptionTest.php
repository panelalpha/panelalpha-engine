<?php

namespace Tests\Unit\DeployHook;

use App\Http\Controllers\User\DeployHistoryController;
use App\Http\Controllers\User\DeployHookController;
use OpenApi\Attributes as OA;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * API and MCP clients cannot run a host command, so the hook description points
 * a delivery's `deploy_id` at the endpoint that reads a kept deploy's log.
 */
class DeployHookShowDescriptionTest extends TestCase
{
    public function test_a_deliverys_deploy_is_read_through_the_api(): void
    {
        $description = $this->operation(DeployHookController::class, 'show')->description;

        $this->assertStringContainsString('/projects/{username}/deploys/{deploy_id}/log', $description);
        $this->assertStringContainsString('deploy_log_read', $description);
        $this->assertStringNotContainsString('artisan', $description);
    }

    public function test_the_named_endpoint_is_the_one_that_reads_a_kept_deploys_log(): void
    {
        $this->assertSame('/projects/{username}/deploys/{deploy}/log', $this->operation(DeployHistoryController::class, 'log')->path);
    }

    private function operation(string $controller, string $method): OA\Get
    {
        return (new ReflectionMethod($controller, $method))->getAttributes(OA\Get::class)[0]->newInstance();
    }
}
