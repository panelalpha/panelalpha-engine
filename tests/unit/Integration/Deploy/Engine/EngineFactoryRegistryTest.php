<?php

namespace Tests\Integration\Deploy\Engine;

use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineFactory;
use App\Lib\Deploy\Engine\UnknownEngineException;
use Tests\TestCase;

/** How the factory registers, memoises and refuses engines, word for word. */
class EngineFactoryRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        EngineFactory::reset();
        parent::tearDown();
    }

    public function test_an_unknown_engine_names_every_registered_one_in_order(): void
    {
        EngineFactory::register(' Podman ', fn (): ContainerEngine => $this->createStub(ContainerEngine::class));

        try {
            EngineFactory::make('  LXC ');
            $this->fail('An unknown engine was made.');
        } catch (UnknownEngineException $e) {
            $this->assertSame("Unknown container engine 'lxc'. Registered: podman, dind", $e->getMessage());
        }
    }

    public function test_an_engine_is_made_once_until_it_is_registered_again(): void
    {
        $made = 0;
        $factory = function () use (&$made): ContainerEngine {
            $made++;

            return $this->createStub(ContainerEngine::class);
        };
        EngineFactory::register('fake', $factory);

        $first = EngineFactory::make('fake');
        $this->assertSame($first, EngineFactory::make(' FAKE'));
        $this->assertSame(1, $made);

        EngineFactory::register('Fake ', $factory);
        $this->assertNotSame($first, EngineFactory::make('fake'));
        $this->assertSame(2, $made);
    }

    public function test_registrations_come_before_the_default_and_reset_forgets_them(): void
    {
        EngineFactory::register('b', fn (): ContainerEngine => $this->createStub(ContainerEngine::class));
        EngineFactory::register('a', fn (): ContainerEngine => $this->createStub(ContainerEngine::class));

        $this->assertSame(['b', 'a', 'dind'], EngineFactory::names());
        $this->assertTrue(EngineFactory::has(' A'));

        EngineFactory::reset();

        $this->assertSame(['dind'], EngineFactory::names());
        $this->assertFalse(EngineFactory::has('a'));
        $this->assertTrue(EngineFactory::has(' DIND '));
        $this->expectExceptionMessage("Unknown container engine 'a'. Registered: dind");
        EngineFactory::make('a');
    }

    public function test_no_name_is_dind_and_is_made_once(): void
    {
        $dind = EngineFactory::make();

        $this->assertInstanceOf(DindEngine::class, $dind);
        $this->assertSame($dind, EngineFactory::make(null));
        $this->assertSame($dind, EngineFactory::make(' DIND '));
    }

    public function test_the_configured_name_is_trimmed_and_lowercased_and_blank_is_dind(): void
    {
        foreach ([' Podman ' => 'podman', '' => 'dind', '   ' => 'dind'] as $configured => $name) {
            config(['env.DEPLOY_ENGINE' => $configured]);
            $this->assertSame($name, EngineFactory::configuredName());
        }
        foreach ([null, 5, ['dind']] as $configured) {
            config(['env.DEPLOY_ENGINE' => $configured]);
            $this->assertSame('dind', EngineFactory::configuredName());
        }
    }
}
