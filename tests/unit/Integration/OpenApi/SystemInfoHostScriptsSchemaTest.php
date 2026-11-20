<?php

namespace Tests\Integration\OpenApi;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** GET /system/info reports each script the root broker runs on the host in one shape, read back from a generation. */
class SystemInfoHostScriptsSchemaTest extends TestCase
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

    public function test_both_firewalls_the_broker_applies_are_reported_alike(): void
    {
        config(['l5-swagger.defaults.paths.docs' => base_path($this->dir.'/spec')]);
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));

        $schemas = json_decode(File::get(base_path($this->dir.'/spec/api-docs.json')), true)['components']['schemas'];
        $info = $schemas['SystemInfo']['properties'];

        foreach (['tenant_network_firewall', 'host_firewall'] as $field) {
            $this->assertSame([['$ref' => '#/components/schemas/HostScriptReport']], $info[$field]['allOf'], $field);
            $this->assertTrue($info[$field]['nullable'], $field);
        }
        $this->assertSame('boolean', $schemas['HostScriptReport']['properties']['failing']['type']);
        $this->assertTrue($schemas['HostScriptReport']['properties']['warning']['nullable']);
    }
}
