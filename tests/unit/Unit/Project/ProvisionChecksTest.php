<?php

namespace Tests\Unit\Project;

use App\Lib\Project\ProvisionChecks;
use App\Lib\Project\ProvisionEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The batched create-request checks, which run before DomainAllocator spends a
 * panelalpha.online label. They were 60 lines inside UserController::provision()
 * and could not be reached without a database and a host.
 */
class ProvisionChecksTest extends TestCase
{
    private function checks(?FakeProvisionEnvironment $env = null): ProvisionChecks
    {
        return new ProvisionChecks($env ?? new FakeProvisionEnvironment());
    }

    /** @return array<string, mixed> */
    private function request(array $overrides = []): array
    {
        return array_merge(['username' => 'alice'], $overrides);
    }

    public function test_a_clean_request_raises_nothing(): void
    {
        $this->assertSame([], $this->checks()->problems($this->request()));
    }

    public function test_a_taken_username_is_reported(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->existingUsernames = ['alice'];

        $this->assertSame(
            [[
                'field' => 'username',
                'code' => 'name_taken',
                'message' => "A project named 'alice' already exists. Choose another name.",
            ]],
            $this->checks($env)->problems($this->request())
        );
    }

    /**
     * A name with no database row but a home directory left behind. Reported
     * before allocation, because a spent label is never released.
     */
    public function test_a_name_the_host_still_holds_is_reported(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->unavailableOnHost = ['alice'];

        $problems = $this->checks($env)->problems($this->request());

        $this->assertSame('name_unavailable', $problems[0]['code']);
    }

    public function test_the_row_check_wins_over_the_host_check(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->existingUsernames = ['alice'];
        $env->unavailableOnHost = ['alice'];

        $problems = $this->checks($env)->problems($this->request());

        $this->assertCount(1, $problems);
        $this->assertSame('name_taken', $problems[0]['code']);
    }

    public function test_a_git_repo_cannot_name_a_template_other_than_dind(): void
    {
        $problems = $this->checks()->problems($this->request([
            'template' => 'wordpress',
            'git_repo' => 'https://example.test/a.git',
        ]));

        $this->assertSame('template_conflicts_with_git', $problems[0]['code']);
    }

    public function test_a_git_repo_may_name_dind_explicitly(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->templates = ['dind'];

        $this->assertSame([], $this->checks($env)->problems($this->request([
            'template' => 'dind',
            'git_repo' => 'https://example.test/a.git',
        ])));
    }

    public function test_an_unknown_template_is_reported(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->templates = ['wordpress'];

        $problems = $this->checks($env)->problems($this->request(['template' => 'joomla']));

        $this->assertSame('template_not_found', $problems[0]['code']);
    }

    public function test_no_template_is_not_a_problem(): void
    {
        $this->assertSame([], $this->checks()->problems($this->request()));
    }

    /** The inline checks read these with empty(), so "0" counts as not given. */
    public function test_a_template_or_domain_of_zero_counts_as_not_given(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->takenDomains = ['0'];

        $this->assertSame([], $this->checks($env)->problems($this->request(['template' => '0', 'domain' => '0'])));
    }

    public function test_a_disk_limit_below_minus_one_is_rejected(): void
    {
        $problems = $this->checks()->problems($this->request(['disk_space_limit' => -5]));

        $this->assertSame(
            [[
                'field' => 'disk_space_limit',
                'code' => 'invalid_value',
                'message' => 'disk_space_limit must be an integer number of MB, or -1 for unlimited.',
                'expected' => 'an integer number of MB, -1 for unlimited',
            ]],
            $problems
        );
    }

    public function test_minus_one_and_zero_are_valid_disk_limits(): void
    {
        foreach ([-1, 0, 1024] as $value) {
            $this->assertSame(
                [],
                $this->checks()->problems($this->request(['disk_space_limit' => $value])),
                "disk_space_limit={$value} should be accepted"
            );
        }
    }

    public function test_a_domain_already_on_this_engine_is_reported(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->takenDomains = ['taken.test'];

        $problems = $this->checks($env)->problems($this->request(['domain' => 'taken.test']));

        $this->assertSame('domain_taken', $problems[0]['code']);
        $this->assertSame('taken.test is already on this engine.', $problems[0]['message']);
    }

    public function test_no_domain_means_the_allocator_will_choose_one(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->takenDomains = ['taken.test'];

        $this->assertSame([], $this->checks($env)->problems($this->request()));
    }

    /**
     * The reason these were batched: a caller with two mistakes used to need
     * two round trips to learn about both.
     */
    public function test_every_problem_is_reported_in_one_pass(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->existingUsernames = ['alice'];
        $env->takenDomains = ['taken.test'];
        $env->templates = ['wordpress'];

        $problems = $this->checks($env)->problems($this->request([
            'template' => 'joomla',
            'disk_space_limit' => -5,
            'domain' => 'taken.test',
        ]));

        $this->assertSame(
            ['name_taken', 'template_not_found', 'invalid_value', 'domain_taken'],
            array_column($problems, 'code')
        );
    }

    public function test_a_dedicated_ip_the_host_cannot_give_is_refused(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->freeFamilies = [4];

        $problems = $this->checks($env)->problems($this->request([
            'dedicated_ipv4' => true,
            'dedicated_ipv6' => true,
        ]));

        $this->assertCount(1, $problems);
        $this->assertSame('dedicated_ipv6', $problems[0]['field']);
        $this->assertSame('no_free_address', $problems[0]['code']);
    }

    public function test_a_dedicated_ip_is_not_looked_for_unless_asked(): void
    {
        $this->assertSame([], $this->checks()->problems($this->request(['dedicated_ipv4' => false])));
    }

    public function test_the_problem_list_is_a_list(): void
    {
        $env = new FakeProvisionEnvironment();
        $env->takenDomains = ['taken.test'];

        $problems = $this->checks($env)->problems($this->request(['domain' => 'taken.test']));

        $this->assertSame(array_keys($problems), range(0, count($problems) - 1));
    }
}

/** @internal */
final class FakeProvisionEnvironment implements ProvisionEnvironment
{
    /** @var list<string> */
    public array $existingUsernames = [];

    /** @var list<string> */
    public array $unavailableOnHost = [];

    /** @var list<string> */
    public array $templates = [];

    /** @var list<string> */
    public array $takenDomains = [];

    /** @var list<int> */
    public array $freeFamilies = [];

    public function usernameRowExists(string $username): bool
    {
        return in_array($username, $this->existingUsernames, true);
    }

    public function usernameAvailableOnHost(string $username): bool
    {
        return !in_array($username, $this->unavailableOnHost, true);
    }

    public function templateExists(string $template): bool
    {
        return in_array($template, $this->templates, true);
    }

    public function domainOrAliasExists(string $domain): bool
    {
        return in_array($domain, $this->takenDomains, true);
    }

    public function freeDedicatedIpExists(int $family): bool
    {
        return in_array($family, $this->freeFamilies, true);
    }
}
