<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\ModSecurity\ModsecAuditLogDownloadTool;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The download tool returned only the first window of the file and could not
 * page: the endpoint's OpenAPI response had no `format: binary`, so the
 * generator never marked it `returnsFile()` and the offset/length arguments
 * (and their validation rules) were left out entirely.
 */
class ModsecAuditLogDownloadToolTest extends TestCase
{
    private function invoke(ModsecAuditLogDownloadTool $tool, string $method): mixed
    {
        $m = new ReflectionMethod($tool, $method);
        $m->setAccessible(true);

        return $m->invoke($tool);
    }

    public function test_it_is_a_file_tool_so_the_window_arguments_are_added(): void
    {
        $this->assertTrue($this->invoke(new ModsecAuditLogDownloadTool(), 'returnsFile'));
    }

    public function test_it_exposes_offset_and_length(): void
    {
        $properties = (array) (new ModsecAuditLogDownloadTool())->toArray()['inputSchema']['properties'];

        $this->assertArrayHasKey('offset', $properties);
        $this->assertArrayHasKey('length', $properties);
    }

    public function test_offset_and_length_survive_validation(): void
    {
        $rules = $this->invoke(new ModsecAuditLogDownloadTool(), 'rules');

        $this->assertArrayHasKey('offset', $rules);
        $this->assertArrayHasKey('length', $rules);
    }
}
