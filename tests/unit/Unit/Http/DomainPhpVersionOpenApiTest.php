<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\DomainController;
use OpenApi\Attributes as OA;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Both operations on `/domains/{domain}/php-version` look the domain up first
 * and answer 404 for one that does not exist, so both describe that answer.
 */
class DomainPhpVersionOpenApiTest extends TestCase
{
    public function test_setting_the_version_documents_the_unknown_domain_answer(): void
    {
        $this->assertContains(404, $this->documentedStatuses('setPhpVersion', OA\Put::class));
    }

    public function test_reading_the_version_documents_the_unknown_domain_answer(): void
    {
        $this->assertContains(404, $this->documentedStatuses('getPhpVersion', OA\Get::class));
    }

    /**
     * @param  class-string  $operation
     * @return list<int>
     */
    private function documentedStatuses(string $method, string $operation): array
    {
        $attribute = (new ReflectionMethod(DomainController::class, $method))->getAttributes($operation)[0];

        return array_map(
            static fn (OA\Response $response): int => (int) $response->response,
            $attribute->newInstance()->responses
        );
    }
}
