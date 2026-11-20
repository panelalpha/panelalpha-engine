<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AbstractApplication;
use App\System\Project\Dind\AccountDaemon;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/** The DinD application answers for the account it was made for, and hands container work to its collaborators. */
class AbstractApplicationDelegationTest extends TestCase
{
    public function test_it_is_named_and_rooted_after_its_account(): void
    {
        $application = $this->application();

        $this->assertSame('dind:acme', $application->id());
        $this->assertSame('/home/acme/project', $application->rootPath());
    }

    public function test_its_domains_are_the_account_s(): void
    {
        $domain = new Domain;
        $domain->domain = 'acme.example.test';

        $this->assertSame([$domain], $this->application([$domain])->domains());
    }

    public function test_the_volume_archive_is_made_once(): void
    {
        $application = $this->application();

        $this->assertSame($application->volumeArchive(), $application->volumeArchive());
    }

    public function test_containers_the_daemon_cannot_list_are_none(): void
    {
        $this->assertSame([], $this->application()->getContainers());
    }

    public function test_actions_are_checked_by_the_container_operations(): void
    {
        $application = $this->application();

        foreach ([
            'project' => static fn () => $application->projectAction('explode'),
            'service' => static fn () => $application->serviceAction('app', 'explode'),
            'logs' => static fn () => $application->getServiceLogs('../etc'),
        ] as $name => $call) {
            try {
                $call();
                $this->fail("{$name} accepted what it must refuse");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    /** @param list<Domain> $domains */
    private function application(array $domains = []): AbstractApplication
    {
        $user = new User;
        $user->username = 'acme';
        $user->setRelation('domains', new Collection($domains));

        $system = $this->createStub(System::class);
        $system->method('exec')->willThrowException(new \RuntimeException('Cannot connect to the Docker daemon'));

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn('acme');
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('userModel')->willReturn($user);
        $dind->method('system')->willReturn($system);
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));

        return new AbstractApplication($dind);
    }
}
