<?php

namespace Tests\Integration\Console;

use App\Models\Domain;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * system:example:delete handed project:delete a `username` argument it does
 * not have, so removing the example project always failed.
 */
class ExampleDomainDeleteCommandTest extends TestCase
{
    use InMemoryDatabase;

    private RecordingProjectDelete $projectDelete;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->seedExampleProject();
        $this->projectDelete = new RecordingProjectDelete;
        $this->app->make(Kernel::class)->registerCommand($this->projectDelete);
    }

    public function test_the_example_project_is_deleted_by_name(): void
    {
        $tester = $this->runForced();

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['example'], $this->projectDelete->projects);
        $this->assertTrue($this->projectDelete->forced);
        foreach (['example-domain-created', 'vhost-default-ip-domain', 'default_wildcard_domain'] as $name) {
            $this->assertSame('', (string) Setting::get($name), $name);
        }
    }

    public function test_a_failed_delete_keeps_the_example_marked_as_created(): void
    {
        $this->projectDelete->exitCode = 1;

        $tester = $this->runForced();

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame('1', (string) Setting::get('example-domain-created'));
        $this->assertSame('example.test', Setting::get('vhost-default-ip-domain'), 'A failed delete leaves the domain naming settings alone.');
    }

    private function runForced(): CommandTester
    {
        $tester = new CommandTester($this->app->make(Kernel::class)->all()['system:example:delete']);
        $tester->execute(['--force' => true], ['interactive' => false]);

        return $tester;
    }

    private function seedExampleProject(): void
    {
        $example = $this->makeUser('example', ['template' => 'dind']);
        Domain::query()->create([
            'user_id' => $example->id,
            'domain' => 'example.test',
            'type' => 'main',
            'details' => ['is_example_domain' => true],
        ]);
        Setting::set('example-domain-created', '1');
        Setting::set('vhost-default-ip-domain', 'example.test');
        Setting::set('default_wildcard_domain', '10-0-0-1.sslip.io');
    }
}

final class RecordingProjectDelete extends Command
{
    protected $signature = 'project:delete {project?*} {--force}';

    /** @var list<string> */
    public array $projects = [];

    public bool $forced = false;

    public int $exitCode = 0;

    public function handle(): int
    {
        $this->projects = $this->argument('project');
        $this->forced = (bool) $this->option('force');

        return $this->exitCode;
    }
}
