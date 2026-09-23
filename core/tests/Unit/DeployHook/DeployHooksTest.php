<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\DeployHooks;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Support\Facades\DB;

/**
 * Creating a hook: what it hands back, that asking again is safe, and that
 * only a checkout the engine deploys from can have one.
 */
class DeployHooksTest extends DeployHookTestCase
{
    private function git(bool $deployManaged = true, string $pathKey = 'project', ?bool $connected = null): ProjectGit
    {
        $git = $this->createStub(ProjectGit::class);
        $git->method('isDeployManaged')->willReturn($deployManaged);
        $git->method('isConnected')->willReturn($connected ?? $deployManaged);
        $git->method('pathKey')->willReturn($pathKey);

        return $git;
    }

    public function test_create_returns_a_url_on_the_engines_address_and_a_secret(): void
    {
        $creation = (new DeployHooks())->create($this->user(), $this->git());

        $this->assertTrue($creation->created);
        $this->assertMatchesRegularExpression(
            '#^https://203\.0\.113\.10/hooks/[0-9a-f]{40}$#',
            $creation->hook->url(),
        );
        $this->assertNotNull($creation->secret);
        $this->assertGreaterThanOrEqual(32, strlen($creation->secret));
    }

    public function test_the_url_never_says_whose_project_it_is(): void
    {
        $user = $this->user(username: 'alice');

        $url = (new DeployHooks())->create($user, $this->git())->hook->url();

        $this->assertStringNotContainsString('alice', $url);
        $this->assertStringNotContainsString($user->domain, $url);
    }

    public function test_the_secret_is_stored_encrypted_and_still_checks_out(): void
    {
        $creation = (new DeployHooks())->create($this->user(), $this->git());

        $stored = (string) DB::table('deploy_hooks')->value('secret_encrypted');

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString((string) $creation->secret, $stored);
        $this->assertSame($creation->secret, DeployHook::find($creation->hook->id)?->secret());
    }

    public function test_asking_again_returns_the_same_hook_without_the_secret(): void
    {
        $user = $this->user();
        $service = new DeployHooks();

        $first = $service->create($user, $this->git());
        $second = $service->create($user, $this->git());

        $this->assertFalse($second->created);
        $this->assertNull($second->secret);
        $this->assertSame($first->hook->id, $second->hook->id);
        $this->assertSame($first->hook->url(), $second->hook->url());
        $this->assertSame(1, DeployHook::count());
        // Asking again must not have replaced the secret the client already holds.
        $this->assertSame($first->secret, $second->hook->secret());
    }

    public function test_two_projects_get_different_urls(): void
    {
        $service = new DeployHooks();

        $a = $service->create($this->user(username: 'alice'), $this->git());
        $b = $service->create($this->user(username: 'bob'), $this->git());

        $this->assertNotSame($a->hook->url(), $b->hook->url());
        $this->assertNotSame($a->secret, $b->secret);
    }

    public function test_a_site_git_checkout_can_have_a_hook_too(): void
    {
        $creation = (new DeployHooks())->create($this->user(), $this->git(deployManaged: false, pathKey: 'public_html', connected: true));

        $this->assertTrue($creation->created);
        $this->assertSame('public_html', $creation->hook->path_key);
        $this->assertNotNull($creation->secret);
    }

    public function test_only_a_deploy_managed_checkout_is_warned_that_pushes_force_it(): void
    {
        $service = new DeployHooks();

        $this->assertSame(DeployHooks::FORCE_PULL_WARNING, $service->warningFor($this->git()));
        $this->assertNull($service->warningFor($this->git(deployManaged: false, pathKey: 'public_html', connected: true)));
    }

    public function test_a_checkout_that_is_not_connected_to_git_is_refused_with_422(): void
    {
        try {
            (new DeployHooks())->create($this->user(), $this->git(deployManaged: false));
            $this->fail('A hook was created for a checkout the engine does not deploy from.');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringContainsString('not connected to git', $e->getMessage());
        }

        $this->assertSame(0, DeployHook::count());
    }

    public function test_show_finds_the_hook_of_that_checkout_only(): void
    {
        $user = $this->user();
        $service = new DeployHooks();
        $managed = $service->create($user, $this->git())->hook;

        $this->assertSame($managed->id, $service->show($user, $this->git())?->id);
        $this->assertNull($service->show($user, $this->git(pathKey: 'site')));
        $this->assertNull($service->show($this->user(username: 'bob'), $this->git()));
    }

    public function test_show_does_not_need_the_checkout_to_still_be_connected(): void
    {
        $user = $this->user();
        $hook = $this->hook($user);

        $this->assertSame($hook->id, (new DeployHooks())->show($user, $this->git(deployManaged: false))?->id);
    }

    public function test_rotate_changes_the_address_and_the_secret_and_keeps_the_row(): void
    {
        $user = $this->user();
        $service = new DeployHooks();
        $created = $service->create($user, $this->git());

        $rotation = $service->rotate($user, $this->git());

        $this->assertNotNull($rotation);
        $this->assertSame($created->hook->id, $rotation->hook->id);
        $this->assertNotSame($created->hook->public_id, $rotation->hook->public_id);
        $this->assertNotSame($created->secret, $rotation->secret);
        $this->assertSame($rotation->secret, DeployHook::findOrFail($created->hook->id)->secret());
        $this->assertSame(0, DeployHook::where('public_id', $created->hook->public_id)->count());
    }

    public function test_rotate_without_a_hook_creates_nothing(): void
    {
        $this->assertNull((new DeployHooks())->rotate($this->user(), $this->git()));
        $this->assertSame(0, DeployHook::count());
    }

    public function test_delete_removes_that_checkouts_hook_only(): void
    {
        $user = $this->user();
        $this->hook($user);
        $site = $this->hook($user, pathKey: 'site');
        $service = new DeployHooks();

        $this->assertTrue($service->delete($user, $this->git()));
        $this->assertFalse($service->delete($user, $this->git()));
        $this->assertSame([$site->id], DeployHook::pluck('id')->all());
    }

    public function test_disconnect_and_forget_removes_that_checkouts_hook_once_disconnected(): void
    {
        $user = $this->user();
        $this->hook($user);
        $site = $this->hook($user, pathKey: 'site');
        $git = $this->git();
        $git->method('disconnect')->willReturn(['connected' => false]);

        $status = (new DeployHooks())->disconnectAndForget($user, $git);

        $this->assertSame(['connected' => false], $status);
        $this->assertSame([$site->id], DeployHook::pluck('id')->all(), 'only the disconnected checkout loses its hook');
    }

    public function test_disconnect_and_forget_does_not_forget_the_hook_when_disconnect_fails(): void
    {
        $user = $this->user();
        $this->hook($user);
        $git = $this->git();
        $git->method('disconnect')->willThrowException(new GitException('Working tree is dirty.', 422));

        try {
            (new DeployHooks())->disconnectAndForget($user, $git);
            $this->fail('Expected the disconnect failure to propagate.');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        $this->assertSame(1, DeployHook::count(), 'a hook must survive a disconnect that never happened');
    }

    public function test_create_registers_the_hook_under_the_engines_current_address(): void
    {
        $hook = (new DeployHooks())->create($this->user(), $this->git())->hook;

        $this->assertSame($hook->url(), $hook->registered_url);
        $this->assertFalse($hook->addressChanged());
    }

    public function test_a_hook_reports_the_address_changed_once_the_engines_url_moves_on(): void
    {
        $hook = (new DeployHooks())->create($this->user(), $this->git())->hook;
        $registeredUrl = $hook->registered_url;

        config(['app.url' => 'https://deploys.example.test']);

        $this->assertTrue($hook->addressChanged());
        $this->assertSame($registeredUrl, $hook->registered_url, 'registered_url is what was registered, not the current address');
        $this->assertNotSame($registeredUrl, $hook->url());
    }

    public function test_rotate_re_registers_the_hook_under_the_current_address(): void
    {
        $service = new DeployHooks();
        $user = $this->user();
        $service->create($user, $this->git());

        config(['app.url' => 'https://deploys.example.test']);
        $rotation = $service->rotate($user, $this->git());

        $this->assertSame($rotation->hook->url(), $rotation->hook->registered_url);
        $this->assertFalse($rotation->hook->addressChanged());
    }

    public function test_a_hook_from_before_this_was_tracked_reports_no_address_change(): void
    {
        $hook = $this->hook($this->user());

        $this->assertNull($hook->registered_url);
        $this->assertFalse($hook->addressChanged());
    }

    public function test_the_response_warns_that_pushes_overwrite_the_checkout(): void
    {
        $warning = DeployHooks::FORCE_PULL_WARNING;

        $this->assertStringContainsString('force', strtolower($warning));
        $this->assertStringContainsString('match the repository', $warning);
    }

    /**
     * `Project::destroy()` deletes a project's hooks through this relation
     * (`$user->deployHooks()->delete()`) before it deletes the user row.
     * `hook_deliveries.deploy_hook_id` cascades from `deploy_hooks` at the DB
     * level, so removing the hooks this way must take their deliveries with
     * them -- proof that deleting a project leaves no trace of its hooks.
     */
    public function test_deleting_a_users_deploy_hooks_takes_their_deliveries_with_them(): void
    {
        $user = $this->user();
        $hook = $this->hook($user);
        $other = $this->hook($this->user(username: 'bob'));
        HookDelivery::create([
            'deploy_hook_id' => $hook->id,
            'provider' => 'github',
            'outcome' => HookDelivery::OUTCOME_QUEUED,
        ]);

        $user->deployHooks()->delete();

        $this->assertSame(0, DeployHook::where('user_id', $user->id)->count());
        $this->assertSame(0, HookDelivery::where('deploy_hook_id', $hook->id)->count());
        $this->assertSame([$other->id], DeployHook::pluck('id')->all(), 'another project\'s hook is untouched');
    }
}
