<?php

namespace Tests\Integration\Project\Create;

use App\Lib\Project\Create\CreateFollowUp;
use App\Lib\Project\Create\FreshWordpress;
use App\Lib\Project\Create\SourceSpec;
use App\Lib\Upload\Uploads;
use App\Models\Upload;
use App\Models\User;
use App\System;
use App\System\Project;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * A typed create that fails after its checks: the upload it claimed is let go once, and a WordPress install that
 * fails takes the new account with it, whether or not removing the account works.
 */
class CreateFollowUpFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_a_failed_create_lets_its_claimed_upload_go_once(): void
    {
        $upload = new Upload;
        $upload->id = 'upl_1';
        $claimedBy = null;
        $uploads = Mockery::mock(Uploads::class);
        $uploads->expects('claimUsable')->with('upl_1', 'source.upload_id', Mockery::type('string'))
            ->andReturnUsing(static function (string $id, string $field, string $by) use (&$claimedBy, $upload): Upload {
                $claimedBy = $by;

                return $upload;
            });
        $uploads->allows('find')->with('upl_1')->andReturn($upload);
        $uploads->expects('release')->once()->withArgs(static function (Upload $released, string $by) use (&$claimedBy, $upload): bool {
            return $released === $upload && $by === $claimedBy;
        });
        $this->app->instance(Uploads::class, $uploads);
        $followUp = CreateFollowUp::for(SourceSpec::of(['type' => 'archive', 'upload_id' => 'upl_1']));

        $followUp->assertUsable();
        $followUp->release();
        $followUp->release();

        $this->assertStringStartsWith('#', (string) $claimedBy);
    }

    public function test_a_wordpress_install_that_fails_removes_the_new_account(): void
    {
        $user = $this->accountWhoseDestroy(null);
        $this->installFails();

        $this->expectExceptionObject(new RuntimeException('wp core download failed'));

        $this->wordpress()->run($user, null, static fn (string $line) => null);
    }

    public function test_an_account_that_cannot_be_removed_is_reported_and_the_install_failure_still_thrown(): void
    {
        $user = $this->accountWhoseDestroy(new RuntimeException('account is busy'));
        $this->installFails();
        $reported = [];
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->allows('report')->andReturnUsing(static function (\Throwable $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });
        $this->app->instance(ExceptionHandler::class, $handler);

        try {
            $this->wordpress()->run($user, null, static fn (string $line) => null);
            $this->fail('the install failure was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('wp core download failed', $e->getMessage());
        }
        $this->assertSame(['account is busy'], $reported);
    }

    private function wordpress(): CreateFollowUp
    {
        return CreateFollowUp::for(SourceSpec::of(['type' => 'wordpress', 'title' => 'Shop', 'admin' => ['user' => 'boss', 'email' => 'b@example.com', 'password' => 'long-enough']]));
    }

    private function installFails(): void
    {
        $fresh = Mockery::mock(FreshWordpress::class);
        $fresh->expects('install')->andThrow(new RuntimeException('wp core download failed'));
        $this->app->instance(FreshWordpress::class, $fresh);
    }

    private function accountWhoseDestroy(?\Throwable $failure): User
    {
        $project = Mockery::mock(Project::class);
        $destroy = $project->expects('destroy')->once();
        if ($failure !== null) {
            $destroy->andThrow($failure);
        }
        $user = new class ($project) extends User {
            public function __construct(private ?Project $fake = null)
            {
                parent::__construct();
            }

            public function project(?System $system = null): Project
            {
                return $this->fake ?? parent::project($system);
            }
        };
        $user->username = 'shop';

        return $user;
    }
}
