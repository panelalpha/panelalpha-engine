<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Http\Controllers\SourceInspectionController;
use App\Lib\Deploy\Inspect\SourceResolver;
use Tests\TestCase;

/** Inspect clones with the deploy's limit, so it accepts any repository a deploy would. */
class InspectCloneTimeoutTest extends TestCase
{
    public function test_inspect_clone_follows_the_deploy_clone_timeout(): void
    {
        config(['deploy.clone_timeout' => 600]);
        $this->assertSame(600, $this->resolverTimeout());

        config(['deploy.clone_timeout' => 900]);
        $this->assertSame(900, $this->resolverTimeout());
    }

    public function test_a_non_positive_setting_falls_back_to_the_deploy_default(): void
    {
        config(['deploy.clone_timeout' => 0]);

        $this->assertSame(600, $this->resolverTimeout());
    }

    private function resolverTimeout(): int
    {
        $controller = new SourceInspectionController();
        $resolver = (new \ReflectionMethod($controller, 'resolver'))->invoke($controller);
        $this->assertInstanceOf(SourceResolver::class, $resolver);

        return (new \ReflectionProperty(SourceResolver::class, 'timeout'))->getValue($resolver);
    }
}
