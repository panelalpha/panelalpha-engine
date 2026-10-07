<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\DomainAllocator;
use App\Models\Setting;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A used-up PanelAlpha Online quota is an engine-wide fact, reported once in
 * `GET /system/info` rather than found project by project.
 */
class DomainAllocatorOnlineErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::setRuntimeSettings([DomainAllocator::SETTING_ONLINE_LAST_ERROR => '']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    private function remember(?string $message): void
    {
        (new ReflectionMethod(DomainAllocator::class, 'rememberOnlineError'))->invoke(null, $message);
    }

    public function test_nothing_is_reported_before_a_failure(): void
    {
        $this->assertNull(DomainAllocator::lastOnlineError());
    }

    public function test_a_refusal_is_kept_with_when_it_happened(): void
    {
        $this->remember('PanelAlpha Online create failed: Sites limit reached for this service (HTTP 200).');

        $error = DomainAllocator::lastOnlineError();
        $this->assertSame('PanelAlpha Online create failed: Sites limit reached for this service (HTTP 200).', $error['message'] ?? null);
        $this->assertNotFalse(strtotime((string) ($error['at'] ?? '')));
    }

    public function test_a_label_sold_afterwards_clears_it(): void
    {
        $this->remember('PanelAlpha Online create failed: Sites limit reached for this service (HTTP 200).');
        $this->remember(null);

        $this->assertNull(DomainAllocator::lastOnlineError());
    }

    public function test_the_allocator_records_a_refusal_and_clears_it_on_a_sale(): void
    {
        // The two paths through allocateOnline() that talk to the proxy.
        $source = (string) file_get_contents(app_path('Lib/Domains/DomainAllocator.php'));

        $this->assertMatchesRegularExpression('/self::rememberOnlineError\(\$e->getMessage\(\)\);\s+if \(\$requestedByCaller\)/', $source);
        $this->assertMatchesRegularExpression('/self::rememberOnlineError\(null\);\s+return new AllocatedDomain\(\s+domain: \$created/', $source);
    }
}
