<?php

namespace Tests\Unit\WordpressMigration;

use App\Exceptions\ProblemException;
use App\Http\Requests\UserStoreRequest;
use App\Lib\Domains\DomainPlan;
use App\Models\User;
use App\System\Project\PhpHosting\WordPress\Migration\ApiMigrationProjectCreator;
use App\System\Project\PhpHosting\WordPress\Migration\ProjectDeployer;
use RuntimeException;

/**
 * ApiMigrationProjectCreator builds a UserStoreRequest (the kept domain with
 * no tunnel, or tunnel=panelalpha for an allocated name) and waits on the
 * in-process deployer; the Docker deploy itself is stubbed.
 */
class ApiMigrationProjectCreatorTest extends WordpressMigrationTestCase
{
    public function test_create_returns_domain_from_deployed_user(): void
    {
        $seen = null;
        $deployer = new class ($seen) implements ProjectDeployer {
            public function __construct(private mixed &$seen)
            {
            }

            public function deploy(UserStoreRequest $request): User
            {
                $this->seen = [
                    'username' => $request->input('username'),
                    'tunnel' => $request->input('tunnel'),
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
        $deployer = new class ($seen) implements ProjectDeployer {
            public function __construct(private mixed &$seen)
            {
            }

            public function deploy(UserStoreRequest $request): User
            {
                $this->seen = $request->only(['username', 'domain', 'tunnel']);

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

    public function test_create_wraps_problem_exception_message(): void
    {
        $deployer = new class implements ProjectDeployer {
            public function deploy(UserStoreRequest $request): User
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
        $deployer = new class implements ProjectDeployer {
            public function deploy(UserStoreRequest $request): User
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
