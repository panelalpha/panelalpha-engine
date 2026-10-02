<?php

use App\System\Firewall\CsfRenames;
use App\Support\EnvFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The CSF API became the firewall API, and its MCP tools were renamed. A
 * setting or a token that names an old tool or route would quietly stop
 * matching: MCP_DENIED_TOOLS=csf_disable would let firewall_disable through,
 * a token limited to csf_status would lose the firewall. Both are renamed here,
 * in the update's own migrate step.
 */
return new class extends Migration
{
    private const ENV_LISTS = ['MCP_TOOLS', 'MCP_DENIED_TOOLS', 'MCP_DIRECT_TOOLS'];

    public function up(): void
    {
        DB::table('personal_access_tokens')->whereNotNull('abilities')->orderBy('id')
            ->each(function (object $token): void {
                $abilities = json_decode((string) $token->abilities, true);
                if (!is_array($abilities)) {
                    return;
                }
                $renamed = array_map(static fn (mixed $a): mixed => is_string($a) ? CsfRenames::ability($a) : $a, $abilities);
                if ($renamed !== $abilities) {
                    DB::table('personal_access_tokens')->where('id', $token->id)
                        ->update(['abilities' => json_encode(array_values(array_unique($renamed, SORT_REGULAR)))]);
                }
            });

        // Only the file the engine boots from: anywhere else (a test run) it
        // is not the operator's configuration.
        $env = EnvFile::current();
        if (!$env->isCoreMount() || $env->suspicious() !== null) {
            return;
        }
        $changes = [];
        foreach (self::ENV_LISTS as $key) {
            $value = $env->get($key);
            if ($value !== null && $value !== ($renamed = CsfRenames::toolList($value))) {
                $changes[$key] = $renamed;
            }
        }
        $toolsets = $env->get('MCP_TOOLSETS');
        if ($toolsets !== null && $toolsets !== ($renamed = CsfRenames::toolsetList($toolsets))) {
            $changes['MCP_TOOLSETS'] = $renamed;
        }
        $regex = $env->get('MCP_DENIED_TOOLS_REGEX');
        if ($regex !== null && $regex !== ($renamed = CsfRenames::regex($regex))) {
            $changes['MCP_DENIED_TOOLS_REGEX'] = $renamed;
        }
        if ($changes !== []) {
            $env->set($changes);
        }
    }

    public function down(): void
    {
        // The old names belong to an API that no longer exists.
    }
};
