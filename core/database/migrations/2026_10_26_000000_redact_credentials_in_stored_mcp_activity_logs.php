<?php

use App\Models\McpActivityLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `wp_config_create` names its database password `dbpass`, which the activity
 * log did not redact, so rows written before it did hold that password in
 * plain text. Redact every credential-named key in the rows already stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mcp_activity_logs')
            ->whereNotNull('input')
            ->select(['id', 'input'])
            ->chunkById(500, fn (Collection $rows) => $rows->each(fn (object $row) => $this->redact($row)));
    }

    public function down(): void {}

    private function redact(object $row): void
    {
        $input = json_decode((string) $row->input, true);
        if (! is_array($input)) {
            return;
        }

        $redacted = McpActivityLog::redactSecretKeys($input);
        if ($redacted !== $input) {
            DB::table('mcp_activity_logs')->where('id', $row->id)->update(['input' => json_encode($redacted)]);
        }
    }
};
