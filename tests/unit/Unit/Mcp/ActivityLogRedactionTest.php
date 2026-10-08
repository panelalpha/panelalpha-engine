<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\ApiTool;
use App\Models\McpActivityLog;
use Tests\TestCase;

/**
 * mcp_activity_logs rows are readable through GET /api/mcp-activity-logs, and
 * fourteen tools take a credential as an argument. Nothing may store one.
 */
class ActivityLogRedactionTest extends TestCase
{
    public function test_it_redacts_credentials_at_the_top_level(): void
    {
        $redacted = McpActivityLog::redact([
            'username' => 'johndoe',
            'password' => 'hunter2',
            'git_token' => 'ghp_realtoken',
        ]);

        $this->assertSame('johndoe', $redacted['username']);
        $this->assertSame(McpActivityLog::REDACTED, $redacted['password']);
        $this->assertSame(McpActivityLog::REDACTED, $redacted['git_token']);
    }

    public function test_it_redacts_at_every_depth(): void
    {
        $redacted = McpActivityLog::redact([
            'settings' => ['APP_ENV' => 'production', 'secret' => 'shh'],
            'nested' => [['token' => 'abc']],
        ]);

        $this->assertSame('production', $redacted['settings']['APP_ENV']);
        $this->assertSame(McpActivityLog::REDACTED, $redacted['settings']['secret']);
        $this->assertSame(McpActivityLog::REDACTED, $redacted['nested'][0]['token']);
    }

    /** APP_KEY, DATABASE_URL and AWS_SECRET_ACCESS_KEY end in nothing a suffix list names. */
    public function test_env_var_values_never_reach_the_log_but_their_names_do(): void
    {
        $redacted = McpActivityLog::redact([
            'name' => 'shop',
            'env_vars' => [
                'APP_KEY' => 'base64:abc',
                'DATABASE_URL' => 'mysql://u:p@db/shop',
                'AWS_SECRET_ACCESS_KEY' => 'wJalr',
                'APP_ENV' => 'production',
            ],
        ]);

        $this->assertSame('shop', $redacted['name']);
        $this->assertSame(
            ['APP_KEY', 'DATABASE_URL', 'AWS_SECRET_ACCESS_KEY', 'APP_ENV'],
            array_keys($redacted['env_vars'])
        );
        foreach ($redacted['env_vars'] as $value) {
            $this->assertSame(McpActivityLog::REDACTED, $value);
        }
    }

    /** backup_container_create: `secret_access_key` and `passphrase` escaped the suffix list. */
    public function test_backup_credentials_are_redacted_whole(): void
    {
        $redacted = McpActivityLog::redact([
            'driver' => 's3',
            'credentials' => ['access_key_id' => 'AKIA', 'secret_access_key' => 'wJalr', 'region' => 'eu'],
        ]);

        $this->assertSame('s3', $redacted['driver']);
        $this->assertSame(
            ['access_key_id' => McpActivityLog::REDACTED, 'secret_access_key' => McpActivityLog::REDACTED, 'region' => McpActivityLog::REDACTED],
            $redacted['credentials']
        );
        $this->assertSame(McpActivityLog::REDACTED, McpActivityLog::redact(['ssh_passphrase' => 'x'])['ssh_passphrase']);
    }

    /** ssh_run, cron_job_create, wp_cli_run, file_write, file_upload, project_setting_set. */
    public function test_free_form_payloads_keep_only_their_size(): void
    {
        $redacted = McpActivityLog::redact([
            'command' => 'mysql -uroot -phunter2 shop',
            'args' => ['user', 'create', 'bob', '--user_pass=hunter2'],
            'contents' => "DB_PASSWORD=hunter2\n",
            'file_contents' => base64_encode('hunter2'),
            'value' => 'cf-api-token',
        ]);

        $this->assertSame('[redacted] (27 bytes)', $redacted['command']);
        $this->assertSame('[redacted] (4 items)', $redacted['args']);
        $this->assertSame('[redacted] (20 bytes)', $redacted['contents']);
        $this->assertSame('[redacted] (12 bytes)', $redacted['file_contents']);
        $this->assertSame('[redacted] (12 bytes)', $redacted['value']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($redacted));
    }

    /** ssl_cert_install's `key` is a PEM private key; `git_repo` may carry a token. */
    public function test_private_keys_and_url_credentials_are_caught_by_shape(): void
    {
        $redacted = McpActivityLog::redact([
            'key' => "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n",
            'cert' => "-----BEGIN CERTIFICATE-----\nMIIC\n-----END CERTIFICATE-----\n",
            'git_repo' => 'https://x-access-token:ghp_abc@github.com/acme/shop.git',
            'url' => 'https://github.com/acme/shop.git',
        ]);

        $this->assertSame(McpActivityLog::REDACTED, $redacted['key']);
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $redacted['cert']);
        $this->assertSame('https://[redacted]@github.com/acme/shop.git', $redacted['git_repo']);
        $this->assertSame('https://github.com/acme/shop.git', $redacted['url']);
    }

    /** The mutator is the one every writer goes through. */
    public function test_the_stored_input_is_the_redacted_one(): void
    {
        $log = new McpActivityLog(['input' => ['env_vars' => ['APP_KEY' => 'base64:abc'], 'command' => 'ls']]);

        $this->assertSame(
            ['env_vars' => ['APP_KEY' => McpActivityLog::REDACTED], 'command' => '[redacted] (2 bytes)'],
            $log->input
        );
    }

    public function test_it_matches_keys_case_insensitively(): void
    {
        $redacted = McpActivityLog::redact(['Password' => 'hunter2', 'GIT_TOKEN' => 'x', 'Admin_Password' => 'p']);

        $this->assertSame(McpActivityLog::REDACTED, $redacted['Password']);
        $this->assertSame(McpActivityLog::REDACTED, $redacted['GIT_TOKEN']);
        $this->assertSame(McpActivityLog::REDACTED, $redacted['Admin_Password']);
    }

    public function test_it_leaves_ordinary_arguments_and_shape_alone(): void
    {
        $input = ['username' => 'johndoe', 'per_page' => 15, 'enabled' => true, 'list' => ['a', 'b']];

        $this->assertSame($input, McpActivityLog::redact($input));
    }

    /**
     * The guard is only worth anything if it covers the arguments the tools
     * actually declare, so take the names from the generated tools themselves
     * rather than from a list written alongside the guard.
     */
    public function test_every_credential_argument_a_generated_tool_declares_is_covered(): void
    {
        /** @var array<int, class-string<ApiTool>> $classes */
        $classes = require base_path('app/Mcp/Tools/Api/generated-tools.php');

        $uncovered = [];

        foreach ($classes as $class) {
            $tool = new $class();

            if (!$tool instanceof ApiTool) {
                continue;
            }

            $properties = (array)($tool->toArray()['inputSchema']['properties'] ?? []);

            foreach (array_keys($properties) as $name) {
                if (!preg_match('/(password|passwd|passphrase|token|secret|private_key|api_key|license_key)$/i', (string)$name)) {
                    continue;
                }

                if (McpActivityLog::redact([$name => 'x'])[$name] !== McpActivityLog::REDACTED) {
                    $uncovered[] = $tool->name() . ' -> ' . $name;
                }
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            "Tool arguments that look like credentials but are not in McpActivityLog::REDACT_SUFFIXES:\n"
                . implode("\n", $uncovered)
        );
    }
}
