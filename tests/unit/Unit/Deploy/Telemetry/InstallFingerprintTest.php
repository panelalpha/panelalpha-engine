<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\InstallFingerprint;
use PHPUnit\Framework\TestCase;

class InstallFingerprintTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-pin-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function pinPath(): string
    {
        return $this->dir . '/install-id';
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(array $overrides = []): array
    {
        return array_replace([
            'docker_id' => 'YQDE:5KWO:3ZPT:6LTF:2XKB:JQ7A:4RMD:PL9C:VN2H:8SGE:WT6Y:BX3Q',
            'machine_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'hostname' => 'engine-host',
            'cpu_model' => 'AMD EPYC 7443P 24-Core Processor',
            'cpu_cores' => 24,
            'mem_total_kb' => 65811628,
            'public_ip' => '203.0.113.7',
        ], $overrides);
    }

    public function test_the_same_machine_derives_the_same_id(): void
    {
        $this->assertSame(
            InstallFingerprint::derive($this->facts()),
            InstallFingerprint::derive($this->facts())
        );
    }

    public function test_a_different_machine_derives_a_different_id(): void
    {
        $this->assertNotSame(
            InstallFingerprint::derive($this->facts()),
            InstallFingerprint::derive($this->facts(['docker_id' => 'OTHER:DAEMON:ID']))
        );
    }

    public function test_the_id_reveals_none_of_the_facts_it_was_made_from(): void
    {
        $id = InstallFingerprint::derive($this->facts());

        foreach (['203.0.113.7', 'engine-host', 'EPYC', 'a1b2c3d4'] as $fact) {
            $this->assertStringNotContainsString($fact, $id);
        }
    }

    public function test_the_id_is_fixed_width_hex(): void
    {
        $this->assertTrue(InstallFingerprint::isValidId(InstallFingerprint::derive($this->facts())));
    }

    /**
     * Without this, every install that cannot probe its own machine would hash
     * the same empty string and merge into one phantom installation.
     */
    public function test_refuses_to_mint_an_id_when_every_fact_is_missing(): void
    {
        $this->assertSame('', InstallFingerprint::derive([]));
        $this->assertSame('', InstallFingerprint::derive([
            'docker_id' => '',
            'machine_id' => null,
            'hostname' => '  ',
        ]));
    }

    public function test_a_single_surviving_fact_is_enough(): void
    {
        $this->assertNotSame('', InstallFingerprint::derive(['machine_id' => 'abc']));
    }

    public function test_pin_round_trips(): void
    {
        $id = InstallFingerprint::derive($this->facts());

        $this->assertTrue(InstallFingerprint::writePin($id, $this->pinPath()));
        $this->assertSame($id, InstallFingerprint::readPin($this->pinPath()));
    }

    public function test_reading_a_missing_or_corrupt_pin_returns_null(): void
    {
        $this->assertNull(InstallFingerprint::readPin($this->pinPath()));

        file_put_contents($this->pinPath(), "not-an-id\n");
        $this->assertNull(InstallFingerprint::readPin($this->pinPath()));
    }

    public function test_refuses_to_pin_a_value_that_is_not_an_id(): void
    {
        $this->assertFalse(InstallFingerprint::writePin('../../etc/passwd', $this->pinPath()));
        $this->assertFileDoesNotExist($this->pinPath());
    }

    public function test_pinning_into_a_missing_directory_fails_quietly(): void
    {
        $id = InstallFingerprint::derive($this->facts());

        $this->assertFalse(InstallFingerprint::writePin($id, $this->dir . '/nope/install-id'));
    }
}
