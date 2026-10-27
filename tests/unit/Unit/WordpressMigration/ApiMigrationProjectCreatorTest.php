<?php

namespace Tests\Unit\WordpressMigration;

use App\Exceptions\ProblemException;
use App\Lib\Domains\DomainPlan;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Models\User;
use App\System\Project\PhpHosting\WordPress\Migration\ApiMigrationProjectCreator;
use App\System\Project\PhpHosting\WordPress\Migration\ControllerProjectDeployer;
use App\System\Project\PhpHosting\WordPress\Migration\ProjectDeployer;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ApiMigrationProjectCreator validates the create (the kept domain with no
 * tunnel, or tunnel=panelalpha for an allocated name) and waits on the
 * in-process deployer; the Docker deploy itself is stubbed. The queue
 * worker's request and authenticated user stay as they were.
 */
class ApiMigrationProjectCreatorTest extends WordpressMigrationTestCase
{
    public function test_create_returns_domain_from_deployed_user(): void
    {
        $seen = null;
        $deployer = new class($seen) implements ProjectDeployer
        {
            public function __construct(private mixed &$seen) {}

            public function deploy(NewProjectInput $input): User
            {
                $this->seen = [
                    'username' => $input->params['username'],
                    'tunnel' => $input->params['tunnel'],
                ];

                return User::make([
                    'username' => 'examplecom',
                    'domain' => 'examplecom-a1b2.panelalpha.online',
                ]);
            }
        };

        $domain = (new ApiMigrationProjectCreator($deployer))->create('examplecom');

        $this->assertSame('examplecom-a1b2.panelalpha.online', $domain);
        $this->assertSame([
            'username' => 'examplecom',
            'tunnel' => DomainPlan::TUNNEL_PANELALPHA,
        ], $seen);
    }

    public function test_a_kept_domain_is_requested_with_no_tunnel(): void
    {
        $seen = null;
        $deployer = new class($seen) implements ProjectDeployer
        {
            public function __construct(private mixed &$seen) {}

            public function deploy(NewProjectInput $input): User
            {
                $this->seen = array_intersect_key($input->params, array_flip(['username', 'domain', 'tunnel']));

                return User::make(['username' => 'wpcom', 'domain' => 'wp.com']);
            }
        };

        $domain = (new ApiMigrationProjectCreator($deployer))->create('wpcom', 'wp.com');

        $this->assertSame('wp.com', $domain);
        $this->assertSame([
            'username' => 'wpcom',
            'domain' => 'wp.com',
            'tunnel' => DomainPlan::TUNNEL_NONE,
        ], $seen);
    }

    public function test_the_worker_keeps_its_request_and_authenticated_user(): void
    {
        $creator = new class extends ProjectCreator
        {
            public ?NewProjectInput $input = null;

            public function create(NewProjectInput $input): User
            {
                $this->input = $input;

                return User::make(['username' => 'wpcom', 'domain' => 'wp.com']);
            }
        };
        $this->app->instance(ProjectCreator::class, $creator);
        $request = $this->app->make('request');

        $domain = (new ApiMigrationProjectCreator(new ControllerProjectDeployer))->create('wpcom', 'wp.com');

        $this->assertSame('wp.com', $domain);
        $this->assertSame('wpcom', $creator->input?->params['username']);
        $this->assertSame($request, $this->app->make('request'));
        $this->assertFalse(Auth::hasUser());
    }

    public function test_create_wraps_problem_exception_message(): void
    {
        $deployer = new class implements ProjectDeployer
        {
            public function deploy(NewProjectInput $input): User
            {
                throw ProblemException::one(
                    'username',
                    'name_taken',
                    "A project named 'examplecom' already exists. Choose another name.",
                );
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "A project named 'examplecom' already exists. Choose another name."
        );

        (new ApiMigrationProjectCreator($deployer))->create('examplecom');
    }

    public function test_create_rejects_user_without_domain(): void
    {
        $deployer = new class implements ProjectDeployer
        {
            public function deploy(NewProjectInput $input): User
            {
                return User::make([
                    'username' => 'examplecom',
                    'domain' => '',
                ]);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Project 'examplecom' was created but no domain was assigned."
        );

        (new ApiMigrationProjectCreator($deployer))->create('examplecom');
    }
}
