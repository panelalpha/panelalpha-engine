<?php

namespace Tests\Unit\System\Services;

use App\System;
use App\System\Services\Modsec;
use Tests\TestCase;

class ModsecRulesetListTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-modsec-list-' . bin2hex(random_bytes(4));
        mkdir($this->rulesets() . '/owasp-crs/rules', 0777, true);
        touch($this->rulesets() . '/owasp-crs/rules/REQUEST-901.conf');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_custom_ruleset_is_listed_and_can_be_enabled_before_any_rules_are_saved(): void
    {
        $modsec = $this->modsec();

        $this->assertSame([
            ['name' => 'owasp-crs', 'config_files' => ['REQUEST-901.conf']],
            ['name' => Modsec::CUSTOM_RULESET, 'config_files' => []],
        ], $modsec->getRulesets());
        $this->assertTrue($modsec->rulesetExists(Modsec::CUSTOM_RULESET));
        $this->assertFalse($modsec->rulesetExists('no-such-ruleset'));
    }

    public function test_saved_custom_rules_are_listed_once_after_the_shipped_rulesets(): void
    {
        mkdir($this->rulesets() . '/custom/rules', 0777, true);
        touch($this->rulesets() . '/custom/rules/custom.conf');

        $this->assertSame([
            ['name' => 'owasp-crs', 'config_files' => ['REQUEST-901.conf']],
            ['name' => Modsec::CUSTOM_RULESET, 'config_files' => ['custom.conf']],
        ], $this->modsec()->getRulesets());
    }

    private function rulesets(): string
    {
        return $this->root . '/config/modsecurity/rulesets';
    }

    private function modsec(): Modsec
    {
        return new Modsec(new class ($this->root) extends System {
            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }
        });
    }
}
