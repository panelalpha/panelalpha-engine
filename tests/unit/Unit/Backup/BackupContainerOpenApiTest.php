<?php

namespace Tests\Unit\Backup;

use App\Http\Controllers\BackupContainerController;
use OpenApi\Attributes as OA;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A created resource is answered 201, so the operation must document that
 * answer rather than a 200 it never returns.
 */
class BackupContainerOpenApiTest extends TestCase
{
    public function test_creating_a_container_documents_the_answer_the_endpoint_gives(): void
    {
        $statuses = $this->documentedStatuses(BackupContainerController::class, 'store', OA\Post::class);

        $this->assertContains(201, $statuses);
        $this->assertNotContains(200, $statuses);
    }

    /**
     * @param  class-string  $controller
     * @param  class-string  $operation
     * @return list<int>
     */
    private function documentedStatuses(string $controller, string $method, string $operation): array
    {
        $attribute = (new ReflectionMethod($controller, $method))->getAttributes($operation)[0];

        return array_map(
            static fn (OA\Response $response): int => (int) $response->response,
            $attribute->newInstance()->responses
        );
    }
}
