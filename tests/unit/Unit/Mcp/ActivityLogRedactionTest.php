<?php

namespace Tests\Unit\Mcp;

use App\Mcp\ToolRegistry;
use App\Models\McpActivityLog;
use Laravel\Mcp\Server\Tool;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * mcp_activity_logs rows are readable through GET /api/mcp-activity-logs, and
 * fourteen tools take a credential as an argument. Nothing may store one.
 */
class ActivityLogRedactionTest extends TestCase
{
    private const CREDENTIAL_WORDS = '/pass|pwd|token|secret|private|credential|api_?key|access_?key|license_?key/i';

    /** Names with a credential word in them that hold none. */
    private const NOT_CREDENTIALS = [
        'clear_secrets' => 'a flag that removes the stored ones',
    ];

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

    /** wp_config_create's `dbpass`, and the short forms WP-CLI and connection strings use. */
    public function test_short_password_names_are_redacted(): void
    {
        $input = ['dbpass' => 'a', 'user_pass' => 'b', 'db_pwd' => 'c', 'secret_key' => 'd', 'aws_access_key' => 'e'];

        $this->assertSame(array_fill_keys(array_keys($input), McpActivityLog::REDACTED), McpActivityLog::redact($input));
        $this->assertSame('wp', McpActivityLog::redact(['dbname' => 'wp'])['dbname']);
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

    /**
     * A failed tool call echoes the caller's own values in its error text, and
     * GET /api/mcp-activity-logs serves it. The model scrubs it so the MCP
     * middleware and POST /api/mcp-activity-logs are both covered.
     */
    public function test_the_stored_error_message_is_scrubbed(): void
    {
        $log = new McpActivityLog([
            'error_message' => 'Downloading https://user:token@host/file.zip answered HTTP 401.',
        ]);

        $this->assertSame(
            'Downloading https://[redacted]@host/file.zip answered HTTP 401.',
            $log->error_message
        );
        $this->assertStringNotContainsString('token@', (string) $log->error_message);
    }

    /**
     * A refused tool call's error text is the API's JSON answer, and a refused deploy-hook
     * registration carries the hook's one-time secret in it. Only secret-named members go.
     */
    public function test_secret_members_of_a_json_error_message_are_masked(): void
    {
        $answer = json_encode(['status' => 422, 'data' => ['problems' => [[
            'code' => 'provider_token_missing',
            'hook' => ['url' => 'https://'.TestNetwork::public(10).'/hooks/abc', 'secret' => 'one-time-"secret\\', 'created' => true],
        ]], 'git_token' => 'ghp_x']], JSON_UNESCAPED_SLASHES);
        $log = new McpActivityLog(['error_message' => $answer]);

        $stored = json_decode((string) $log->error_message, true);
        $this->assertSame(McpActivityLog::REDACTED, $stored['data']['problems'][0]['hook']['secret']);
        $this->assertSame(McpActivityLog::REDACTED, $stored['data']['git_token']);
        $this->assertSame('https://'.TestNetwork::public(10).'/hooks/abc', $stored['data']['problems'][0]['hook']['url']);
        $this->assertSame('provider_token_missing', $stored['data']['problems'][0]['code']);
        $this->assertTrue($stored['data']['problems'][0]['hook']['created']);
    }

    /** The same answer, escaped inside another tool's JSON (execute_tools reports its calls this way). */
    public function test_secret_members_of_json_escaped_in_a_json_string_are_masked(): void
    {
        $inner = json_encode(['hook' => ['secret' => 'one-time-"secret\\', 'url' => 'https://h/x']], JSON_UNESCAPED_SLASHES);
        $log = new McpActivityLog(['error_message' => json_encode(['results' => [['text' => $inner]]], JSON_UNESCAPED_SLASHES)]);

        $stored = json_decode((string) json_decode((string) $log->error_message, true)['results'][0]['text'], true);
        $this->assertSame(McpActivityLog::REDACTED, $stored['hook']['secret']);
        $this->assertSame('https://h/x', $stored['hook']['url']);
    }

    /** A token in a query string survived both the URL and the suffix guards. */
    public function test_secret_query_parameters_are_masked_in_both_fields(): void
    {
        $log = new McpActivityLog([
            'input' => ['git_repo' => 'https://host/repo.git?private_token=abc123&ref=main'],
            'error_message' => 'Cloning https://host/repo.git?private_token=abc123 failed.',
        ]);

        $this->assertSame(
            'https://host/repo.git?private_token=[redacted]&ref=main',
            $log->input['git_repo']
        );
        $this->assertSame(
            'Cloning https://host/repo.git?private_token=[redacted] failed.',
            $log->error_message
        );
    }

    /** A presigned URL carries its credential in a long signature parameter. */
    public function test_presigned_signatures_are_masked(): void
    {
        $log = new McpActivityLog([
            'error_message' => 'GET https://bucket.s3.amazonaws.com/k?X-Amz-Signature=deadbeefdeadbeefdeadbeefdeadbeef&X-Amz-Credential=AKIA answered 403.',
        ]);

        $this->assertSame(
            'GET https://bucket.s3.amazonaws.com/k?X-Amz-Signature=[redacted]&X-Amz-Credential=[redacted] answered 403.',
            $log->error_message
        );
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

    /** A row stored before `dbpass` was a credential name: only such names change, sizes stay. */
    public function test_a_stored_row_loses_only_its_credential_values(): void
    {
        $stored = [
            'dbname' => 'wp',
            'dbpass' => 'hunter2',
            'password' => McpActivityLog::REDACTED,
            'args' => '[redacted] (4 items)',
            'nested' => [['user_pass' => 'hunter2', 'role' => 'editor']],
        ];

        $this->assertSame(
            [
                'dbname' => 'wp',
                'dbpass' => McpActivityLog::REDACTED,
                'password' => McpActivityLog::REDACTED,
                'args' => '[redacted] (4 items)',
                'nested' => [['user_pass' => McpActivityLog::REDACTED, 'role' => 'editor']],
            ],
            McpActivityLog::redactSecretKeys($stored)
        );
    }

    public function test_it_leaves_ordinary_arguments_and_shape_alone(): void
    {
        $input = ['username' => 'johndoe', 'per_page' => 15, 'enabled' => true, 'list' => ['a', 'b']];

        $this->assertSame($input, McpActivityLog::redact($input));
    }

    /**
     * The guard is only worth anything if it covers the arguments the tools
     * actually declare, so take the names from the tools themselves, nested
     * ones included, and look for credential words anywhere in a name rather
     * than reuse the suffixes the guard matches: `dbpass` ended in none.
     */
    public function test_every_credential_argument_a_tool_declares_is_covered(): void
    {
        $uncovered = [];

        foreach (ToolRegistry::all() as $class) {
            $tool = app($class);
            if (! $tool instanceof Tool) {
                continue;
            }

            foreach ($this->argumentNames((array) ($tool->toArray()['inputSchema']['properties'] ?? [])) as $name) {
                if (preg_match(self::CREDENTIAL_WORDS, $name) !== 1 || array_key_exists($name, self::NOT_CREDENTIALS)) {
                    continue;
                }
                if (McpActivityLog::redact([$name => 'x'])[$name] !== McpActivityLog::REDACTED) {
                    $uncovered[] = $tool->name().' -> '.$name;
                }
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            "Tool arguments that look like credentials but are not redacted by McpActivityLog:\n"
                .implode("\n", $uncovered)
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function argumentNames(array $properties): array
    {
        $names = [];
        foreach ($properties as $name => $schema) {
            $names[] = (string) $name;
            $nested = is_array($schema) ? ($schema['properties'] ?? $schema['items']['properties'] ?? []) : [];
            array_push($names, ...$this->argumentNames((array) $nested));
        }

        return $names;
    }
}
