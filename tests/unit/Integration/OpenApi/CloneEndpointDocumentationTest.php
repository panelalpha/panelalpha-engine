<?php

namespace Tests\Integration\OpenApi;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * POST /projects/{username}/clone creates a project, and it does copy the source's
 * MySQL databases. The document says both, and a generated client reads it.
 */
class CloneEndpointDocumentationTest extends TestCase
{
    private string $docs;

    /** @var array<string, mixed>|null */
    private ?array $spec = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docs = 'storage/framework/testing/openapi-clone-'.bin2hex(random_bytes(4));
        File::makeDirectory(base_path($this->docs), 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->docs));
        parent::tearDown();
    }

    public function test_it_documents_201_as_the_create_does(): void
    {
        $responses = $this->operation()['responses'] ?? [];

        $this->assertArrayHasKey('201', $responses);
        $this->assertArrayNotHasKey('200', $responses);
    }

    public function test_it_documents_that_databases_are_copied(): void
    {
        $description = (string) ($this->operation()['description'] ?? '');

        $this->assertStringContainsString('MySQL databases are', $description);
        $this->assertStringNotContainsString('MySQL databases/users and dedicated IP', $description);
        $this->assertStringNotContainsString('databases/users are NOT cloned', $description);
    }

    /** @return array<string, mixed> */
    private function operation(): array
    {
        if ($this->spec === null) {
            config(['l5-swagger.defaults.paths.docs' => base_path($this->docs)]);
            $this->assertSame(0, Artisan::call('l5-swagger:generate'));
            $this->spec = json_decode((string) file_get_contents(base_path($this->docs.'/api-docs.json')), true);
            $this->assertIsArray($this->spec);
        }

        $operation = $this->spec['paths']['/projects/{username}/clone']['post'] ?? null;
        $this->assertIsArray($operation);

        return $operation;
    }
}
