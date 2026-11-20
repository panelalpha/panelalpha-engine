<?php

namespace Tests\Integration\System\Firewall;

use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallException;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\Ufw\UfwFirewall;
use Tests\TestCase;

/** How the factory registers, memoises and refuses providers, word for word. */
class FirewallFactoryRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        FirewallFactory::reset();
        parent::tearDown();
    }

    public function test_an_unknown_provider_names_every_registered_one_in_order(): void
    {
        FirewallFactory::register(' PF ', fn (): Firewall => $this->createStub(Firewall::class));

        try {
            FirewallFactory::make('  NFTables ');
            $this->fail('An unknown provider was made.');
        } catch (FirewallException $e) {
            $this->assertSame(FirewallException::class, $e::class);
            $this->assertSame('Unknown firewall provider "nftables"; available: pf, ufw', $e->getMessage());
        }
    }

    public function test_a_provider_is_made_once_until_it_is_registered_again(): void
    {
        $made = 0;
        $factory = function () use (&$made): Firewall {
            $made++;

            return $this->createStub(Firewall::class);
        };
        FirewallFactory::register('fake', $factory);

        $first = FirewallFactory::make('fake');
        $this->assertSame($first, FirewallFactory::make(' FAKE'));
        $this->assertSame(1, $made);

        FirewallFactory::register('Fake ', $factory);
        $this->assertNotSame($first, FirewallFactory::make('fake'));
        $this->assertSame(2, $made);
    }

    public function test_registrations_come_before_the_default_and_reset_forgets_them(): void
    {
        FirewallFactory::register('b', fn (): Firewall => $this->createStub(Firewall::class));
        FirewallFactory::register('a', fn (): Firewall => $this->createStub(Firewall::class));

        $this->assertSame(['b', 'a', 'ufw'], FirewallFactory::names());

        FirewallFactory::reset();

        $this->assertSame(['ufw'], FirewallFactory::names());
        $this->expectExceptionMessage('Unknown firewall provider "a"; available: ufw');
        FirewallFactory::make('a');
    }

    public function test_no_name_is_ufw_and_is_made_once(): void
    {
        $ufw = FirewallFactory::make();

        $this->assertInstanceOf(UfwFirewall::class, $ufw);
        $this->assertSame($ufw, FirewallFactory::make(null));
        $this->assertSame($ufw, FirewallFactory::make(' UFW '));
    }

    public function test_the_configured_name_is_trimmed_and_lowercased_and_blank_is_ufw(): void
    {
        foreach ([' FirewallD ' => 'firewalld', '' => 'ufw', '   ' => 'ufw'] as $configured => $name) {
            config(['env.FIREWALL_PROVIDER' => $configured]);
            $this->assertSame($name, FirewallFactory::configuredName());
        }
        foreach ([null, 5, ['ufw']] as $configured) {
            config(['env.FIREWALL_PROVIDER' => $configured]);
            $this->assertSame('ufw', FirewallFactory::configuredName());
        }
    }
}
