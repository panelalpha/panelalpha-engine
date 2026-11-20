<?php

namespace Tests\Integration\OpenApi;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The published document is generated from the #[OA\...] attributes, so the
 * subnet schema is read back from a generation rather than from the class.
 */
class IpSubnetSchemaTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'storage/framework/testing/openapi-' . bin2hex(random_bytes(4));
        File::makeDirectory(base_path($this->dir . '/spec'), 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->dir));
        parent::tearDown();
    }

    public function test_the_subnet_schema_documents_the_prefix_length_and_the_shared_flag(): void
    {
        config(['l5-swagger.defaults.paths.docs' => base_path($this->dir . '/spec')]);
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));

        $document = json_decode(File::get(base_path($this->dir . '/spec/api-docs.json')), true);
        $properties = $document['components']['schemas']['IpSubnet']['properties'];

        $this->assertSame('integer', $properties['mask']['type']);
        $this->assertSame('boolean', $properties['is_shared']['type']);
    }
}
