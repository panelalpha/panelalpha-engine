<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmApacheStack;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\LiteSpeedStack;
use App\System\Project\PhpHosting\PhpStackResolver;
use App\System\Services\Webserver;
use PHPUnit\Framework\TestCase;

class PhpStackResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        parent::tearDown();
    }

    public function test_resolves_fpm_stack_for_nginx_and_apache(): void
    {
        $model = $this->userModel();
        $system = new System();
        $project = $this->phpHosting($system, $model);

        foreach (['nginx', 'apache'] as $slug) {
            $this->setCurrentWebserver($slug);
            $stack = PhpStackResolver::resolve($system, $model, $project);
            $this->assertInstanceOf(FpmStack::class, $stack);
            $this->assertNotInstanceOf(LiteSpeedStack::class, $stack);
        }
    }

    public function test_resolves_litespeed_stacks_without_public_project_types(): void
    {
        $model = $this->userModel();
        $system = new System();
        $project = $this->phpHosting($system, $model);

        foreach (['litespeed', 'openlitespeed'] as $slug) {
            $this->setCurrentWebserver($slug);
            $stack = PhpStackResolver::resolve($system, $model, $project);
            $this->assertInstanceOf(LiteSpeedStack::class, $stack);
        }
    }

    public function test_resolves_fpm_apache_stack_for_nginx_proxy(): void
    {
        $model = $this->userModel();
        $system = new System();
        $project = $this->phpHosting($system, $model);

        $this->setCurrentWebserver('nginx-proxy');
        $stack = PhpStackResolver::resolve($system, $model, $project);
        $this->assertInstanceOf(FpmApacheStack::class, $stack);
    }

    private function phpHosting(System $system, ModelsUser $model): PhpHosting
    {
        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(PhpHosting::class, $runtime);

        return $runtime;
    }

    private function userModel(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'default']);

        return $model;
    }

    private function setCurrentWebserver(?string $webserver): void
    {
        $property = (new \ReflectionClass(Webserver::class))->getProperty('currentWebserver');
        $property->setAccessible(true);
        $property->setValue(null, $webserver);
    }
}
