<?php

namespace Tests\Unit\Rules;

use App\Rules\EximSecret;
use App\Rules\GitAccessToken;
use App\Rules\GitRepositoryUrl;
use App\Rules\InspectableSource;
use App\Rules\RedirectUrl;
use App\Rules\SshPublicKey;
use App\Rules\UpstreamHost;
use App\Rules\WpCliArgument;
use Illuminate\Contracts\Validation\ValidationRule;
use PHPUnit\Framework\TestCase;

/** What each rule says about a value it refuses, and the values it leaves alone. */
class RuleRefusalsTest extends TestCase
{
    public function test_an_upstream_host_longer_than_a_hostname_may_be_is_refused(): void
    {
        $label = str_repeat('a', 60);
        $long = implode('.', [$label, $label, $label, $label, 'example-test']);

        $this->assertGreaterThan(253, strlen($long));
        $this->assertFalse(UpstreamHost::isValid($long));
        $this->assertSame(['The :attribute must be a hostname or an IP address.'], $this->failures(new UpstreamHost(), $long));
    }

    public function test_an_exim_secret_must_be_one_line_of_text(): void
    {
        $this->assertSame([], $this->failures(new EximSecret(), null));
        $this->assertSame([], $this->failures(new EximSecret(), 's3cret'));
        $this->assertSame(['The :attribute must be a string.'], $this->failures(new EximSecret(), 42));
        $this->assertSame(['The :attribute must not contain a line break or a null byte.'], $this->failures(new EximSecret(), "s3cret\nX-Injected: 1"));
    }

    public function test_a_redirect_url_must_be_text_without_config_metacharacters(): void
    {
        $this->assertSame([], $this->failures(new RedirectUrl(), ''));
        $this->assertSame([], $this->failures(new RedirectUrl(), 'https://example.com/landing'));
        $this->assertSame(['The :attribute must be a URL.'], $this->failures(new RedirectUrl(), ['https://example.com']));
        $this->assertSame(
            ['The :attribute must not contain whitespace, a quote, a backslash, `$`, `;`, `{`, `}` or a backtick.'],
            $this->failures(new RedirectUrl(), 'https://example.com/; return 200'),
        );
    }

    public function test_a_wp_cli_argument_outside_its_grammar_is_refused(): void
    {
        $rule = new WpCliArgument(WpCliArgument::SLUG);

        $this->assertSame([], $this->failures($rule, 'akismet'));
        $this->assertSame([], $this->failures($rule, 7));
        $this->assertSame(['The :attribute format is invalid.'], $this->failures($rule, 'akismet plugin'));
        $this->assertNull($rule->problem());
    }

    public function test_a_repository_url_that_is_not_text_is_malformed(): void
    {
        $rule = new GitRepositoryUrl();

        $this->assertSame([], $this->failures($rule, null));
        $this->assertSame([], $this->failures($rule, ''));
        $this->assertSame(['A repository URL must be a string.'], $this->failures($rule, 12));
        $this->assertSame('git_repo_malformed', $rule->problem()['code']);
    }

    public function test_a_repository_url_the_input_rules_refuse_carries_their_problem(): void
    {
        $rule = new GitRepositoryUrl();

        $failures = $this->failures($rule, 'ftp://example.com/acme/app.git');

        $this->assertCount(1, $failures);
        $this->assertSame('git_repo', $rule->problem()['field']);
        $this->assertSame($rule->problem()['message'], $failures[0]);
    }

    public function test_a_git_token_that_is_not_text_is_malformed(): void
    {
        $rule = new GitAccessToken();

        $this->assertSame([], $this->failures($rule, ''));
        $this->assertSame(['A Git token must be a string.'], $this->failures($rule, ['ghp']));
        $this->assertSame('git_token_malformed', $rule->problem()['code']);
    }

    public function test_a_blank_git_token_is_refused_before_the_repository_is_looked_at(): void
    {
        $rule = (new GitAccessToken())->setData(['git_repo' => 'git@github.com:acme/app.git']);

        $this->assertSame(['A Git token must not be blank.'], $this->failures($rule, '   '));
        $this->assertSame('git_token_blank', $rule->problem()['code']);
    }

    public function test_an_inspectable_source_ignores_a_value_that_is_not_text(): void
    {
        $rule = new InspectableSource();

        $this->assertSame([], $this->failures($rule, ['https://github.com/acme/app.git']));
        $this->assertSame([], $this->failures($rule, '  '));
        $this->assertNull($rule->problem());
    }

    public function test_a_key_whose_data_stops_inside_a_length_prefix_is_not_valid(): void
    {
        $blob = pack('N', 11) . 'ssh-ed25519' . "\0\0";

        $this->assertFalse(SshPublicKey::isValid('ssh-ed25519 ' . base64_encode($blob)));
    }

    /** @return list<string> */
    private function failures(ValidationRule $rule, mixed $value): array
    {
        $failures = [];
        $rule->validate($rule instanceof GitAccessToken ? 'git_token' : 'git_repo', $value, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        return $failures;
    }
}
