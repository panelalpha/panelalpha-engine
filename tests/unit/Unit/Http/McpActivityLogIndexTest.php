<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\McpActivityLogController;
use App\Http\Requests\McpActivityLogIndexRequest;
use App\Models\Admin;
use App\Models\McpActivityLog;
use App\Models\PersonalAccessToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A panel serving several users lists one user's calls by the ids of that user's
 * tokens, deleted ones included, and searches by part of a token name.
 */
class McpActivityLogIndexTest extends TestCase
{
    use InMemoryDatabase;

    private const MIGRATION = '2026_10_15_000000_keep_token_id_on_mcp_activity_logs.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_token_ids_keep_only_the_calls_of_those_tokens(): void
    {
        $a = $this->log(['token_id' => 1]);
        $b = $this->log(['token_id' => 2]);
        $this->log(['token_id' => 3]);
        $this->log(['token_id' => null]);

        $this->assertSame([$a, $b], $this->listedIds(['token_ids' => ['1', '2']]));
    }

    public function test_token_name_matches_part_of_the_name_ignoring_case(): void
    {
        $exact = $this->log(['token_name' => 'alice-laptop']);
        $upper = $this->log(['token_name' => 'ALICE-desktop']);
        $this->log(['token_name' => 'bob-laptop']);

        $this->assertSame([$exact, $upper], $this->listedIds(['token_name' => 'Alice']));
        $this->assertSame([$exact], $this->listedIds(['token_name' => 'alice-laptop']));
    }

    public function test_like_wildcards_in_the_token_name_are_taken_literally(): void
    {
        $percent = $this->log(['token_name' => 'ci 100% green']);
        $this->log(['token_name' => 'plain']);
        $underscore = $this->log(['token_name' => 'a_b']);
        $this->log(['token_name' => 'axb']);
        $bang = $this->log(['token_name' => 'wow!']);

        $this->assertSame([$percent], $this->listedIds(['token_name' => '%']));
        $this->assertSame([$underscore], $this->listedIds(['token_name' => 'a_b']));
        $this->assertSame([$bang], $this->listedIds(['token_name' => '!']));
    }

    public function test_project_keeps_only_the_calls_that_named_it(): void
    {
        $alice = $this->log(['project' => 'alice']);
        $this->log(['project' => 'bob']);
        $this->log(['project' => null]);

        $this->assertSame([$alice], $this->listedIds(['project' => 'alice']));
    }

    public function test_filters_combine_with_and(): void
    {
        $match = $this->log(['token_id' => 1, 'token_name' => 'alice-laptop', 'project' => 'shop']);
        $this->log(['token_id' => 1, 'token_name' => 'alice-laptop', 'project' => 'blog']);
        $this->log(['token_id' => 1, 'token_name' => 'bob', 'project' => 'shop']);
        $this->log(['token_id' => 2, 'token_name' => 'alice-laptop', 'project' => 'shop']);

        $this->assertSame(
            [$match],
            $this->listedIds(['token_ids' => [1], 'token_name' => 'LAPTOP', 'project' => 'shop'])
        );
    }

    public function test_no_filter_lists_every_call(): void
    {
        $ids = [$this->log(['token_id' => 1]), $this->log(['token_id' => null])];

        $this->assertSame($ids, $this->listedIds([]));
    }

    public function test_pagination_links_keep_the_filters(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->log(['token_id' => 7]);
        }

        $page = $this->list(['token_ids' => [7], 'token_name' => 'pan']);

        $this->assertSame(21, $page['total']);
        $this->assertStringContainsString('token_ids', urldecode((string) $page['next_page_url']));
        $this->assertStringContainsString('token_name=pan', (string) $page['next_page_url']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidQueries(): iterable
    {
        yield 'a token id of zero' => [['token_ids' => [0]]];
        yield 'a negative token id' => [['token_ids' => [-3]]];
        yield 'a token id that is not a number' => [['token_ids' => ['abc']]];
        yield 'token_ids not given as a list' => [['token_ids' => '5']];
        yield 'more than 1000 token ids' => [['token_ids' => range(1, 1001)]];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_token_ids_are_refused(array $query): void
    {
        $this->expectException(ValidationException::class);

        $this->list($query);
    }

    public function test_deleting_a_token_keeps_its_id_on_the_rows_it_wrote(): void
    {
        $token = $this->token('alice-laptop');
        $row = McpActivityLog::create(['token_id' => $token->id, 'token_name' => 'alice-laptop', 'tool_name' => 'ping', 'status' => 'success']);

        $token->delete();

        $row->refresh();
        $this->assertSame($token->id, $row->token_id);
        $this->assertNull($row->token);
        $this->assertSame([$row->id], $this->listedIds(['token_ids' => [$token->id]]));
    }

    public function test_the_migration_drops_the_foreign_key_and_keeps_the_index(): void
    {
        $this->assertSame([], Schema::getForeignKeys('mcp_activity_logs'));
        $this->assertContains(['token_id'], array_column(Schema::getIndexes('mcp_activity_logs'), 'columns'));
    }

    public function test_rolling_the_migration_back_restores_the_foreign_key_and_nulls_orphans(): void
    {
        $live = $this->token('live');
        $kept = $this->log(['token_id' => $live->id]);
        $orphan = $this->log(['token_id' => 999]);

        $this->migration()->down();

        $this->assertSame(['token_id'], Schema::getForeignKeys('mcp_activity_logs')[0]['columns']);
        $this->assertSame($live->id, McpActivityLog::findOrFail($kept)->token_id);
        $this->assertNull(McpActivityLog::findOrFail($orphan)->token_id);

        $this->migration()->up();

        $this->assertSame([], Schema::getForeignKeys('mcp_activity_logs'));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function token(string $name): PersonalAccessToken
    {
        return PersonalAccessToken::forceCreate([
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => $name,
            'token' => hash('sha256', $name),
            'abilities' => ['*'],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function log(array $attributes): int
    {
        return DB::table('mcp_activity_logs')->insertGetId($attributes + [
            'token_name' => 'panel',
            'tool_name' => 'ping',
            'status' => 'success',
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function listedIds(array $query): array
    {
        $ids = array_column($this->list($query)['data'], 'id');
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function list(array $query): array
    {
        $this->app->instance('request', Request::create('/api/mcp-activity-logs', 'GET', $query));

        return (new McpActivityLogController)->index($this->app->make(McpActivityLogIndexRequest::class))->getData(true);
    }
}
