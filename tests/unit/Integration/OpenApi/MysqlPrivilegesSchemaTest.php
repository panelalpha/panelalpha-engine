<?php

namespace Tests\Integration\OpenApi;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * GET /projects/{username}/mysql/privileges/{dbuser}/{dbname} answers the
 * privilege list, not the GRANT statement, and its schema says so.
 */
class MysqlPrivilegesSchemaTest extends TestCase
{
    private string $docs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docs = 'storage/framework/testing/openapi-schema-' . bin2hex(random_bytes(4));
        File::makeDirectory(base_path($this->docs), 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->docs));
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function privilegesDataProperty(): array
    {
        config(['l5-swagger.defaults.paths.docs' => base_path($this->docs)]);
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));

        $spec = json_decode((string) file_get_contents(base_path($this->docs . '/api-docs.json')), true);
        $this->assertIsArray($spec);

        $property = $spec['components']['schemas']['MysqlPrivileges']['properties']['data'] ?? null;
        $this->assertIsArray($property);

        return $property;
    }

    public function test_the_example_is_a_privilege_list_not_a_grant_statement(): void
    {
        $example = (string) ($this->privilegesDataProperty()['example'] ?? '');

        $this->assertStringNotContainsString('GRANT', $example);
        $this->assertMatchesRegularExpression(
            '/^[A-Z][A-Z, ]*$/',
            $example,
            'The example must be the privilege list the endpoint returns.'
        );
    }

    public function test_the_description_names_the_privilege_list(): void
    {
        $description = (string) ($this->privilegesDataProperty()['description'] ?? '');

        $this->assertStringContainsString('privilege', strtolower($description));
        $this->assertStringNotContainsString('GRANT', $description);
    }
}
