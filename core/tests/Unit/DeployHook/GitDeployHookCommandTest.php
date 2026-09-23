<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\DeployHooks;
use App\Models\DeployHook;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `php artisan git:deploy-hook` -- the CLI spelling of the REST management
 * surface. It runs the route in-process as the root admin, so these tests go
 * through the real router and controller.
 */
class GitDeployHookCommandTest extends DeployHookTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('admins', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::table('admins')->insert(['name' => 'root', 'email' => 'root@example.test']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('admins');
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{int, string}
     */
    private function artisanRun(array $arguments): array
    {
        $exit = Artisan::call('git:deploy-hook', $arguments);

        return [$exit, Artisan::output()];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $output): array
    {
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'The command must print JSON, got: ' . $output);

        return $decoded;
    }

    public function test_without_flags_it_creates_the_hook_and_prints_the_secret_once(): void
    {
        $this->user('main');

        [$exit, $output] = $this->artisanRun(['username' => 'alice']);

        $this->assertSame(0, $exit);
        $data = $this->decode($output)['data'];
        $this->assertTrue($data['created']);
        $this->assertSame('project', $data['path']);
        $this->assertSame(DeployHook::firstOrFail()->secret(), $data['secret']);
        $this->assertSame(DeployHooks::FORCE_PULL_WARNING, $data['warning']);
    }

    public function test_asking_again_prints_the_same_url_and_no_secret(): void
    {
        $this->user('main');
        [, $first] = $this->artisanRun(['username' => 'alice']);

        [$exit, $second] = $this->artisanRun(['username' => 'alice']);

        $this->assertSame(0, $exit);
        $data = $this->decode($second)['data'];
        $this->assertFalse($data['created']);
        $this->assertSame($this->decode($first)['data']['url'], $data['url']);
        $this->assertArrayNotHasKey('secret', $data);
    }

    public function test_provider_is_accepted_on_create(): void
    {
        $this->user('main');

        [$exit] = $this->artisanRun(['username' => 'alice', '--provider' => 'github']);

        $this->assertSame(0, $exit);
        $this->assertSame(1, DeployHook::count());
    }

    public function test_rotate_prints_a_new_url_and_secret(): void
    {
        $hook = $this->hook($this->user('main'), 'the-old-secret-of-some-length');

        [$exit, $output] = $this->artisanRun(['username' => 'alice', '--rotate' => true]);

        $this->assertSame(0, $exit);
        $data = $this->decode($output)['data'];
        $this->assertTrue($data['rotated']);
        $this->assertNotSame($hook->url(), $data['url']);
        $this->assertNotSame('the-old-secret-of-some-length', $data['secret']);
        $this->assertSame($data['secret'], DeployHook::firstOrFail()->secret());
    }

    public function test_delete_removes_the_hook_and_says_so(): void
    {
        $this->hook($this->user('main'));

        [$exit, $output] = $this->artisanRun(['username' => 'alice', '--delete' => true]);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->decode($output)['data']['deleted']);
        $this->assertSame(0, DeployHook::count());
    }

    public function test_path_selects_the_checkout(): void
    {
        $user = $this->user('main');
        $managed = $this->hook($user, 'secret-of-the-managed-checkout');
        $site = $this->hook($user, 'secret-of-the-site-checkout', 'wp-content/themes/mine');

        [$exit] = $this->artisanRun(['username' => 'alice', '--rotate' => true, '--path' => 'wp-content/themes/mine']);

        $this->assertSame(0, $exit);
        $this->assertSame($managed->public_id, $managed->fresh()->public_id);
        $this->assertNotSame($site->public_id, $site->fresh()->public_id);
    }

    public function test_delete_with_a_path_removes_only_that_checkouts_hook(): void
    {
        $user = $this->user('main');
        $managed = $this->hook($user, 'secret-of-the-managed-checkout');
        $this->hook($user, 'secret-of-the-site-checkout', 'wp-content/themes/mine');

        [$exit, $output] = $this->artisanRun(['username' => 'alice', '--delete' => true, '--path' => 'wp-content/themes/mine']);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->decode($output)['data']['deleted']);
        $this->assertSame([$managed->id], DeployHook::pluck('id')->all());
    }

    public function test_rotate_and_delete_together_are_refused_and_change_nothing(): void
    {
        $hook = $this->hook($this->user('main'));

        [$exit] = $this->artisanRun(['username' => 'alice', '--rotate' => true, '--delete' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame($hook->public_id, DeployHook::firstOrFail()->public_id);
    }

    public function test_an_error_from_the_api_is_a_failure_exit(): void
    {
        $this->user('main', ['git_repo' => '']);

        [$exit] = $this->artisanRun(['username' => 'alice']);

        $this->assertSame(1, $exit);
        $this->assertSame(0, DeployHook::count());
    }

    public function test_rotating_a_missing_hook_fails(): void
    {
        $this->user('main');

        [$exit] = $this->artisanRun(['username' => 'alice', '--rotate' => true]);

        $this->assertSame(1, $exit);
    }
}
