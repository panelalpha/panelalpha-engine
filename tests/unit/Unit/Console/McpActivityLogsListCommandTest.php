<?php

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `pae mcp:log:list` filters the way GET /api/mcp-activity-logs does: token ids,
 * part of a token name, and a project, combined.
 */
class McpActivityLogsListCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->log(1, 'alice-laptop', 'shop', 'tool_alice_shop');
        $this->log(1, 'alice-laptop', 'blog', 'tool_alice_blog');
        $this->log(2, 'Bob-Desktop', 'shop', 'tool_bob_shop');
        $this->log(3, 'carol', null, 'tool_carol');
    }

    public function test_without_options_it_lists_every_call(): void
    {
        $output = $this->listed(['--limit' => 10]);

        foreach (['tool_alice_shop', 'tool_alice_blog', 'tool_bob_shop', 'tool_carol'] as $tool) {
            $this->assertStringContainsString($tool, $output);
        }
    }

    public function test_token_ids_keep_only_the_calls_of_those_tokens(): void
    {
        $output = $this->listed(['--token-id' => ['2', '3']]);

        $this->assertListsOnly(['tool_bob_shop', 'tool_carol'], $output);
    }

    public function test_token_name_matches_part_of_the_name_ignoring_case(): void
    {
        $output = $this->listed(['--token-name' => 'DESK']);

        $this->assertListsOnly(['tool_bob_shop'], $output);
    }

    public function test_filters_combine(): void
    {
        $output = $this->listed(['--token-id' => ['1', '2'], '--token-name' => 'alice', '--project' => 'shop']);

        $this->assertListsOnly(['tool_alice_shop'], $output);
    }

    public function test_a_token_id_that_is_not_a_positive_number_is_refused(): void
    {
        $exit = Artisan::call('mcp:log:list', ['--token-id' => ['0']]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--token-id takes a positive whole number.', Artisan::output());
    }

    /** @param list<string> $expected */
    private function assertListsOnly(array $expected, string $output): void
    {
        $all = ['tool_alice_shop', 'tool_alice_blog', 'tool_bob_shop', 'tool_carol'];

        foreach ($all as $tool) {
            in_array($tool, $expected, true)
                ? $this->assertStringContainsString($tool, $output)
                : $this->assertStringNotContainsString($tool, $output);
        }
    }

    /** @param array<string, mixed> $options */
    private function listed(array $options): string
    {
        $this->assertSame(0, Artisan::call('mcp:log:list', $options));

        return Artisan::output();
    }

    private function log(int $tokenId, string $tokenName, ?string $project, string $tool): void
    {
        DB::table('mcp_activity_logs')->insert([
            'token_id' => $tokenId,
            'token_name' => $tokenName,
            'project' => $project,
            'tool_name' => $tool,
            'status' => 'success',
        ]);
    }
}
