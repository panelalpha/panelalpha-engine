<?php

namespace Tests\Unit\OpenApi;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PUT /system/update replaces the engine's code and restarts it. Its documentation, which the MCP tool is generated
 * from, has to say so, and the body the panel sends -- none, or only a license key -- stays enough.
 */
class SystemUpdateDocumentationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'storage/framework/testing/openapi-'.bin2hex(random_bytes(4));
        File::makeDirectory(base_path($this->dir.'/spec'), 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->dir));
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        config(['l5-swagger.defaults.paths.docs' => base_path($this->dir.'/spec')]);
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));

        return json_decode(File::get(base_path($this->dir.'/spec/api-docs.json')), true);
    }

    public function test_the_route_says_it_replaces_the_code_and_restarts_the_stack(): void
    {
        $update = $this->spec()['paths']['/system/update']['put'];

        $this->assertStringContainsString("Replaces the engine's code with the newest release", $update['description']);
        $this->assertStringContainsString('restarts the engine stack', $update['description']);
        $this->assertStringContainsString('older than the running one is refused', $update['description']);
    }

    public function test_an_empty_body_is_still_a_valid_request(): void
    {
        $update = $this->spec()['paths']['/system/update']['put'];

        $this->assertFalse($update['requestBody']['required']);
        $this->assertArrayNotHasKey('required', $update['requestBody']['content']['application/json']['schema']);
    }

    public function test_the_run_record_documents_what_the_run_did(): void
    {
        $run = $this->spec()['components']['schemas']['EngineUpdateRun']['properties'];

        $this->assertSame(['installed', 'updated', 'up_to_date', 'refused', 'rolled_back', 'failed'], $run['result']['enum']);
        $this->assertTrue($run['result']['nullable']);
        foreach (['from_version', 'to_version', 'exit_code', 'finished_at', 'reason'] as $field) {
            $this->assertArrayHasKey($field, $run, $field);
        }
    }
}
