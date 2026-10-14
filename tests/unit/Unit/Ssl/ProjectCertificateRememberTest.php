<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\ProjectCertificate;
use App\Models\User;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\AppCertificate;
use App\System\Project\PhpHosting;
use Mockery;
use Tests\TestCase;

/**
 * After a certificate is issued or renewed, a DinD project's details.ssl is
 * refreshed through the project's runtime, not the System\Project wrapper.
 */
class ProjectCertificateRememberTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_dind_project_records_its_new_certificate(): void
    {
        // AppCertificate is final: a real one whose remember() reads the account once.
        $account = Mockery::mock(User::class)->makePartial();
        $account->shouldReceive('getMainDomain')->once()->andReturnNull();
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('userModel')->once()->andReturn($account);
        $dind->shouldReceive('appCertificate')->once()->andReturn(new AppCertificate($dind));

        ProjectCertificate::remember($this->user($dind));
        $this->addToAssertionCount(1);
    }

    public function test_a_php_hosting_project_records_nothing(): void
    {
        $runtime = Mockery::mock(PhpHosting::class);
        $runtime->shouldNotReceive('appCertificate');

        ProjectCertificate::remember($this->user($runtime));
        $this->addToAssertionCount(1);
    }

    public function test_a_domain_without_a_user_records_nothing(): void
    {
        ProjectCertificate::remember(null);
        $this->addToAssertionCount(1);
    }

    private function user(object $runtime): User
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runtime')->andReturn($runtime);

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }
}
