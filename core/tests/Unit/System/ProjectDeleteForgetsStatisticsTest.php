<?php

namespace Tests\Unit\System;

use App\Integrations\Statistics\Statistics;
use App\Models\User;
use App\System;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Unit\Integrations\Statistics\FakeStatistics;

// Deleting a project left its domains' AWStats config and data behind, and the
// next project given the same domain was shown the old one's traffic.
class ProjectDeleteForgetsStatisticsTest extends TestCase
{
    public function test_destroy_forgets_the_statistics_of_every_domain_it_deletes(): void
    {
        $source = (string) file_get_contents(app_path('System/Project.php'));
        $this->assertMatchesRegularExpression(
            '/\$user->domains\(\)->delete\(\);[\s\S]{0,200}\$this->forgetDomainsStatistics\(\$domainNames\);/',
            $source
        );
    }

    public function test_every_domain_is_forgotten_even_after_one_fails(): void
    {
        $fake = new FakeStatistics();
        $fake->failures['broken.example'] = new \RuntimeException('Permission denied');
        $this->app->instance(Statistics::class, $fake);

        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'goneapp';
        $user->shouldReceive('hasGitProject')->andReturn(false);
        $user->shouldReceive('getTemplate')->andReturn('default');
        $project = (new System())->project($user);

        $method = new ReflectionMethod($project, 'forgetDomainsStatistics');
        $method->invoke($project, ['goneapp.example', 'broken.example', 'www.goneapp.example']);

        $this->assertSame(['goneapp.example', 'www.goneapp.example'], $fake->forgotten);
    }
}
