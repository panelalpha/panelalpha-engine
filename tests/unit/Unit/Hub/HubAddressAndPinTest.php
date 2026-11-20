<?php

namespace Tests\Unit\Hub;

use App\Lib\Hub\EngineAddress;
use App\Lib\Hub\HubException;
use App\Lib\Hub\SpkiPin;
use App\Models\Setting;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** The engine's default address when settings cannot be read, and a pin from something that holds no key. */
class HubAddressAndPinTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_an_unreadable_settings_table_gives_no_default_address(): void
    {
        Setting::clearRuntimeSettings();

        $this->assertNull(EngineAddress::default());
    }

    public function test_the_default_address_is_the_trimmed_setting(): void
    {
        Setting::setRuntimeSettings(['default_ipv4' => ' '.TestNetwork::public(7).' ']);
        $this->assertSame(TestNetwork::public(7), EngineAddress::default());

        Setting::setRuntimeSettings(['default_ipv4' => '']);
        $this->assertNull(EngineAddress::default());
    }

    public function test_a_pem_without_a_public_key_is_refused(): void
    {
        $this->expectException(HubException::class);
        $this->expectExceptionMessage('The certificate has no readable public key.');

        SpkiPin::fromPem("-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n");
    }
}
