<?php

namespace Tests\Unit\Helpers;

use App\Lib\Helper;
use App\Models\Setting;
use Tests\TestCase;

/** Each name generator tries its base name, where it has one, and then exactly ten random suffixes. */
class HelperNameGeneratorsTest extends TestCase
{
    /** @var list<string> */
    private array $checked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setRuntimeSettings(['default_wildcard_domain' => 'sites.example.test']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_username_tries_the_base_name_and_ten_suffixes(): void
    {
        $this->assertNull(Helper::generateUsernameFrom('alice', $this->nothingFree(...)));

        $this->assertSame('alice', $this->checked[0]);
        $this->assertTenSuffixed(array_slice($this->checked, 1), '/^alice\d{4}$/');
    }

    public function test_a_domain_tries_the_base_name_and_ten_suffixes(): void
    {
        $this->assertNull(Helper::generateDomainName('alice', $this->nothingFree(...)));

        $this->assertSame('alice.sites.example.test', $this->checked[0]);
        $this->assertTenSuffixed(array_slice($this->checked, 1), '/^alice\d{4}\.sites\.example\.test$/');
    }

    public function test_a_clone_username_tries_ten_suffixes(): void
    {
        $this->assertNull(Helper::generateCloneUsername('alice', $this->nothingFree(...)));

        $this->assertTenSuffixed($this->checked, '/^alice\d{4}$/');
    }

    public function test_a_clone_domain_tries_the_staging_name_and_ten_suffixes(): void
    {
        $this->assertNull(Helper::generateCloneDomain('shop.example.test', $this->nothingFree(...)));

        $this->assertSame('staging.shop.example.test', $this->checked[0]);
        $this->assertTenSuffixed(array_slice($this->checked, 1), '/^staging\d{4}\.shop\.example\.test$/');
    }

    public function test_a_clone_domain_tries_the_clone_name_and_ten_suffixes(): void
    {
        $this->assertNull(Helper::generateCloneDomain('shop.example.test', $this->nothingFree(...), 'clone'));

        $this->assertSame('clone.shop.example.test', $this->checked[0]);
        $this->assertTenSuffixed(array_slice($this->checked, 1), '/^clone\d{4}\.shop\.example\.test$/');
    }

    private function nothingFree(string $name): bool
    {
        $this->checked[] = $name;

        return false;
    }

    /** @param  list<string>  $names */
    private function assertTenSuffixed(array $names, string $pattern): void
    {
        $this->assertCount(10, $names);
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression($pattern, $name);
        }
    }
}
