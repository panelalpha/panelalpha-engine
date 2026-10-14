<?php

namespace Tests\Unit\Models;

use App\Models\Setting;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The stored mail config is JSON anyone can write with `pae settings:set exim`,
 * so a field holding a list or an object must not reach the string config.
 */
class EximConfigSettingTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_saved_list_or_object_reads_as_the_default(): void
    {
        Setting::set('exim', '{"smtp_host":["a.example","b.example"],"smtp_username":{"name":"x"},"smtp_port":"587"}');

        $config = Setting::getEximConfig();

        $this->assertSame('', $config['smtp_host']);
        $this->assertSame('', $config['smtp_username']);
        $this->assertSame('587', $config['smtp_port']);
    }

    public function test_saved_scalars_are_still_read_as_strings(): void
    {
        Setting::set('exim', '{"smtp_port":587,"smtp_implicit_tls":true,"smtp_host":"smtp.example.com"}');

        $config = Setting::getEximConfig();

        $this->assertSame('587', $config['smtp_port']);
        $this->assertSame('1', $config['smtp_implicit_tls']);
        $this->assertSame('smtp.example.com', $config['smtp_host']);
    }
}
