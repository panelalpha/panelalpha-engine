<?php

namespace Tests\Integration\Domains;

use App\Exceptions\NotFoundException;
use App\Lib\Domains\DomainLogFiles;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A domain's webserver logs looked up by file name: one it does not have is not found, and a webserver log is
 * opened only when it can be read.
 */
class DomainLogFilesTest extends TestCase
{
    use InMemoryDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $saved = $this->makeUser('shop', [], 'shop.example.test');
        $this->makeMainDomain($saved, 'shop.example.test');
        $vhost = Mockery::mock(ProjectDomain::class);
        $vhost->allows('listLogFiles')->andReturn([
            ['file' => 'access.log', 'path' => base_path('composer.json'), 'mtime' => 1, 'size' => 1],
            ['file' => 'error.log', 'path' => base_path('no-such-log-file.log'), 'mtime' => 1, 'size' => 0],
        ]);
        $project = Mockery::mock(Project::class);
        $project->allows('domain')->andReturn($vhost);
        $this->user = new class ($project) extends User {
            protected $table = 'users';

            public function getForeignKey(): string
            {
                return 'user_id';
            }

            public function __construct(private ?Project $fake = null)
            {
                parent::__construct();
            }

            public function project(?System $system = null): Project
            {
                return $this->fake ?? parent::project($system);
            }
        };
        $this->user->setRawAttributes($saved->getAttributes(), true);
        $this->user->exists = true;
    }

    public function test_a_log_the_domain_does_not_have_is_not_found(): void
    {
        $logs = DomainLogFiles::findOrFail($this->user, 'shop.example.test');

        $this->assertSame(base_path('composer.json'), $logs->pathOrFail('access.log', false));
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Log file 'other.log' not found for domain 'shop.example.test'.");

        $logs->pathOrFail('other.log', false);
    }

    public function test_a_webserver_log_is_opened_only_when_it_can_be_read(): void
    {
        $logs = DomainLogFiles::findOrFail($this->user, 'shop.example.test');

        $this->assertSame(base_path('composer.json'), $logs->readablePath('access.log', false));
        $this->assertNull($logs->readablePath('error.log', false));
        $this->assertNull($logs->readablePath('other.log', false));
    }
}
