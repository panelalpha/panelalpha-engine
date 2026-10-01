<?php

namespace Tests\Unit;

use App\Exceptions\ProblemException;
use App\Lib\Project\ProjectCreator;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * engine#8: two creates of one name both pass provision()'s checks, and the
 * loser's insert hit the unique index as an unhandled 500.
 */
class ConcurrentProjectCreateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->unique();
            $table->string('email')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('domain')->index();
            $table->string('type');
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Schema::create('tunnels', function (Blueprint $table) {
            $table->id();
            $table->string('hostname');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tunnels');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function save(User $user, ?\Closure $mainDomain = null, string $nameField = 'username'): Domain
    {
        $mainDomain ??= fn (User $user): Domain => Domain::make([
            'user_id' => $user->id,
            'domain' => $user->domain,
            'type' => 'main',
            'details' => [],
        ]);

        /** @var Domain */
        return (new ReflectionMethod(ProjectCreator::class, 'saveNewProject'))->invoke(null, $user, $mainDomain, $nameField);
    }

    private function user(string $username, string $domain): User
    {
        /** @var User */
        return User::make(['username' => $username, 'domain' => $domain, 'details' => []]);
    }

    /** @return array{field: string, code: string} */
    private function problemOf(\Closure $call): array
    {
        try {
            $call();
        } catch (ProblemException $e) {
            return ['field' => $e->problems[0]['field'], 'code' => $e->problems[0]['code']];
        }
        $this->fail('expected a ProblemException');
    }

    public function test_a_create_saves_the_user_and_its_main_domain(): void
    {
        $domain = $this->save($this->user('race', 'race1.example.test'));

        $this->assertSame(1, User::query()->count());
        $this->assertSame((int) User::query()->value('id'), (int) $domain->user_id);
        $this->assertSame('race1.example.test', $domain->domain);
    }

    public function test_losing_the_race_for_a_name_is_name_taken_not_a_500(): void
    {
        $this->save($this->user('race', 'race1.example.test'));

        $problem = $this->problemOf(fn () => $this->save($this->user('race', 'race2.example.test')));

        $this->assertSame(['field' => 'username', 'code' => 'name_taken'], $problem);
        $this->assertSame(1, User::query()->count());
        $this->assertFalse(Domain::existsByName('race2.example.test'));
    }

    public function test_losing_the_race_reports_the_name_under_the_field_it_was_sent_as(): void
    {
        $this->save($this->user('race', 'race1.example.test'));

        $problem = $this->problemOf(fn () => $this->save($this->user('race', 'race2.example.test'), null, 'name'));

        // Same field a sequential duplicate reports (UserStoreRequest::nameField()).
        $this->assertSame(['field' => 'name', 'code' => 'name_taken'], $problem);
    }

    public function test_losing_the_race_for_a_domain_is_domain_taken(): void
    {
        $this->save($this->user('first', 'same.example.test'));

        $problem = $this->problemOf(fn () => $this->save($this->user('second', 'same.example.test')));

        $this->assertSame(['field' => 'domain', 'code' => 'domain_taken'], $problem);
        $this->assertFalse(User::existsByUsername('second'));
    }

    public function test_a_failed_main_domain_leaves_no_user_behind(): void
    {
        try {
            $this->save($this->user('race', 'race1.example.test'), function (): Domain {
                throw new RuntimeException('domain write failed');
            });
            $this->fail('the failure must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('domain write failed', $e->getMessage());
        }

        $this->assertFalse(User::existsByUsername('race'));
    }
}
