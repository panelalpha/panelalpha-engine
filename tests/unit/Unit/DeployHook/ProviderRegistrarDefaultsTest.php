<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\ProviderRegistrar;
use App\Lib\DeployHook\ProviderRegistrationRefused;
use App\Lib\DeployHook\Registration\CheckoutRemote;
use PHPUnit\Framework\TestCase;

/** The stock registrars, and what happens when none can address the remote or no token is held. */
class ProviderRegistrarDefaultsTest extends TestCase
{
    public function test_a_remote_no_registrar_serves_registers_nothing(): void
    {
        $remote = new CheckoutRemote('https://git.example.com/acme/shop.git', 'tok');
        $registrar = new ProviderRegistrar;

        $this->assertNull($registrar->register($remote, 'https://engine.example.com/hook', 'secret'));
        $this->assertNull($registrar->register($remote, 'https://engine.example.com/hook', 'secret', 'gitea'));
        $this->assertFalse($registrar->refresh($remote, '1', 'https://engine.example.com/hook', 'secret', null));
        $this->assertFalse($registrar->forget($remote, '1', null));
    }

    public function test_without_a_token_a_known_host_is_neither_refreshed_nor_forgotten(): void
    {
        $remote = new CheckoutRemote('https://github.com/acme/shop.git', null);
        $registrar = new ProviderRegistrar;

        $this->assertFalse($registrar->refresh($remote, '1', 'https://engine.example.com/hook', 'secret', null));
        $this->assertFalse($registrar->forget($remote, '1', 'github'));
    }

    public function test_registering_on_a_known_host_without_a_token_is_refused(): void
    {
        $this->expectException(ProviderRegistrationRefused::class);

        (new ProviderRegistrar)->register(new CheckoutRemote('https://gitlab.com/acme/shop.git', ''), 'https://engine.example.com/hook', 'secret');
    }
}
