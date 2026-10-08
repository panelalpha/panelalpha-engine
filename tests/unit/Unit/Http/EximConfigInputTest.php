<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Mcp\Tools\Api\System\SystemEximConfigSetTool;
use App\Mcp\Tools\Api\System\SystemTestEmailSendTool;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request as McpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The Exim config both exim endpoints save and apply. Hosts, ports and the
 * sender domain go through update-exim4.conf's sed, so anything else is
 * refused before the config is saved, and nothing here reaches the mail
 * container. Valid values are sent beside one bad field: they must not be
 * among the errors.
 */
class EximConfigInputTest extends TestCase
{
    use InMemoryDatabase;

    private const SAVED = '{"smarthost_provider":"smtp","smtp_host":"smtp.example.com","smtp_port":"587"}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Setting::set('exim', self::SAVED);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function badValues(): array
    {
        return [
            // Ended update-exim4.conf's `s|...|...|`: a raw sed error, answered 500.
            'host with a pipe' => ['smtp_host', 'smtp.example.com| cat /etc/passwd'],
            // update-exim4.conf's list separator: a second smarthost.
            'host with a semicolon' => ['smtp_host', 'smtp.example.com; rm -rf /'],
            'host with command substitution' => ['smtp_host', 'smtp.example.com$(whoami)'],
            'host with backticks' => ['smtp_host', 'smtp.example.com`whoami`'],
            'host with a newline' => ['smtp_host', "smtp.example.com\n/bin/sh"],
            // Joined as <host>::<port>: the port goes in smtp_port.
            'host with a port' => ['smtp_host', 'smtp.example.com:587'],
            'port with a pipe' => ['smtp_port', '587|x'],
            'port that is a word' => ['smtp_port', 'submission'],
            'port 0' => ['smtp_port', '0'],
            'port above 65535' => ['smtp_port', '65536'],
            'SES endpoint with a pipe' => ['amazon_ses_smtp_endpoint', 'email-smtp.eu-west-1.amazonaws.com|x'],
            'SES port with a slash' => ['amazon_ses_starttls_port', '587/x'],
            'sender domain with a pipe' => ['sender_domain', 'example.com|x'],
            'sender domain with a space' => ['sender_domain', 'example.com rm'],
            // Senders are rewritten to `<local>_at_<domain>@<sender_domain>`.
            'sender domain that is an IPv4 address' => ['sender_domain', '192.0.2.10'],
            'sender domain that is an IPv6 address' => ['sender_domain', '2001:db8::1'],
        ];
    }

    #[DataProvider('badValues')]
    public function test_the_config_refuses_it_before_saving_anything(string $field, string $value): void
    {
        $this->putJson('/api/system/exim-config', ['smarthost_provider' => 'smtp', $field => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(self::SAVED, $this->saved());
    }

    #[DataProvider('badValues')]
    public function test_the_test_email_refuses_it_before_saving_anything(string $field, string $value): void
    {
        $this->postJson('/api/system/exim-send-test-email', [
            'email' => 'ops@example.test',
            'config' => ['smarthost_provider' => 'smtp', $field => $value],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(["config.{$field}"]);

        $this->assertSame(self::SAVED, $this->saved());
    }

    public function test_what_an_operator_types_passes(): void
    {
        $valid = [
            'smtp_host' => 'email-smtp.eu-west-1.amazonaws.com',
            'smtp_port' => '587',
            'amazon_ses_smtp_endpoint' => '2001:db8::25',
            'amazon_ses_starttls_port' => '65535',
            'sender_domain' => 'mail.example.com',
        ];
        $overrides = [
            ['smtp_host' => '192.0.2.10', 'smtp_port' => '25'],
            ['smtp_host' => 'mail_relay', 'smtp_port' => '2525'],
            // An absolute name, accepted before: stored without its dot.
            ['smtp_host' => 'smtp.example.com.', 'amazon_ses_smtp_endpoint' => 'email-smtp.eu-west-1.amazonaws.com.', 'sender_domain' => 'mail.example.com.'],
            [],
        ];
        foreach ($overrides as $override) {
            $errors = $this->putJson('/api/system/exim-config', [...$valid, ...$override, 'smtp_implicit_tls' => 'not a boolean'])
                ->assertStatus(422)
                ->json('errors');

            $this->assertSame(['smtp_implicit_tls'], array_keys($errors), json_encode($override));
        }
    }

    /** Exim::sendTestEmail() refuses what FILTER_VALIDATE_EMAIL refuses; that used to be its exception, a 500. */
    public function test_a_recipient_the_mail_code_would_refuse_is_a_422(): void
    {
        $this->postJson('/api/system/exim-send-test-email', ['email' => str_repeat('x', 200) . '@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function untrimmedOverMcp(): array
    {
        $config = ['smarthost_provider' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => '587'];

        return [
            'smtp_port " 587"' => ['config', [...$config, 'smtp_port' => ' 587'], 'smtp_port'],
            'smtp_port "\\n587"' => ['config', [...$config, 'smtp_port' => "\n587"], 'smtp_port'],
            'smtp_port "587\\n"' => ['config', [...$config, 'smtp_port' => "587\n"], 'smtp_port'],
            'smtp_port "+587"' => ['config', [...$config, 'smtp_port' => '+587'], 'smtp_port'],
            'smtp_host " smtp.example.com"' => ['config', [...$config, 'smtp_host' => ' smtp.example.com'], 'smtp_host'],
            'smtp_host "smtp.example.com\\n"' => ['config', [...$config, 'smtp_host' => "smtp.example.com\n"], 'smtp_host'],
            'sender_domain "mail.example.com\\n"' => ['config', [...$config, 'sender_domain' => "mail.example.com\n"], 'sender_domain'],
            'test email config.smtp_port "\\n587"' => ['test', ['email' => 'ops@example.test', 'config' => [...$config, 'smtp_port' => "\n587"]], 'config.smtp_port'],
            'test email recipient "ops@example.test\\n"' => ['test', ['email' => "ops@example.test\n"], 'email'],
            'test email recipient " ops@example.test"' => ['test', ['email' => ' ops@example.test'], 'email'],
        ];
    }

    /**
     * MCP reaches the controller through Router::dispatch(), past the global
     * middleware, so TrimStrings never runs: whitespace has to be refused by
     * the rules themselves.
     *
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('untrimmedOverMcp')]
    public function test_untrimmed_values_from_mcp_are_refused(string $tool, array $arguments, string $field): void
    {
        $this->withoutMiddleware();
        $tool = $tool === 'config' ? new SystemEximConfigSetTool() : new SystemTestEmailSendTool();

        $result = json_decode((string) $tool->handle(new McpRequest($arguments))->content(), true);

        $this->assertSame(422, $result['status'] ?? null, json_encode($result));
        $this->assertArrayHasKey($field, $result['data']['errors'] ?? []);
        $this->assertSame(self::SAVED, $this->saved());
    }

    private function saved(): mixed
    {
        return DB::table('settings')->where('name', 'exim')->value('value');
    }
}
