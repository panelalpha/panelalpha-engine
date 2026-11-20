<?php

namespace Tests\Support;

use App\System;
use App\System\Privileged;
use App\System\Privileged\RootAccountContainer;
use App\System\Privileged\RootCompose;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use Mockery;

trait ShellStepTagFixtures
{
    private const COMPOSE = '/opt/panelalpha/shared-hosting/users/acme/docker-compose.yml';

    private function compose(): RootCompose
    {
        return Privileged::compose(self::COMPOSE);
    }

    private function container(): RootAccountContainer
    {
        return Privileged::accountContainer('acme');
    }

    private function shell(?System $system = null): ShellOperations
    {
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('composeFilePath')->andReturn(self::COMPOSE);
        $dind->shouldReceive('homeDirPath')->andReturn('/home/acme');
        $dind->shouldReceive('username')->andReturn('acme');
        if ($system !== null) {
            $dind->shouldReceive('system')->andReturn($system);
        }

        return new ShellOperations($dind);
    }
}
