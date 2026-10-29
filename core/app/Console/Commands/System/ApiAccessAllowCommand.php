<?php

namespace App\Console\Commands\System;

use App\Exceptions\ProblemException;
use App\Lib\Project\ApiAccess;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Allows a project to use the Engine API, or updates the scope of one already
 * allowed. The token carries the scope, so an update mints a new one.
 */
class ApiAccessAllowCommand extends Command
{
    protected $signature = 'system:api-access:allow
        {project : The project to allow}
        {--scope=self : self, all, or a comma-separated list of projects}
        {--mcp : Let the token call MCP tools too}';

    protected $description = 'Let a project reach the Engine API, and hand it the URL and a token';

    public function handle(): int
    {
        $user = User::findByUsername((string) $this->argument('project'));
        if ($user === null) {
            $this->error("There is no project '{$this->argument('project')}' on this engine.");

            return 1;
        }

        $access = new ApiAccess;
        $before = $access->entryFor($user);

        try {
            $entry = $access->allow(
                $user,
                self::scope((string) $this->option('scope')),
                (bool) $this->option('mcp')
            );
        } catch (ProblemException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        if ($before !== null && $before['token_id'] === $entry['token_id']) {
            $this->line(sprintf(
                '%s already uses the Engine API with scope %s; nothing changed, and its token (id %s) is the same.',
                $user->username,
                ApiAccess::scopeLabel($entry['scope']),
                (string) $entry['token_id']
            ));

            return 0;
        }

        $this->info(sprintf(
            'Allowed %s to use the Engine API, scope %s%s, token id %s. Its containers may reach the API port, '
            .'and the URL, token and certificate are applied to the project now and delivered again on every deploy.',
            $user->username,
            ApiAccess::scopeLabel($entry['scope']),
            ($entry['mcp'] ?? false) ? ' including MCP tools' : '',
            (string) $entry['token_id']
        ));

        return 0;
    }

    /**
     * @return string|list<string>
     */
    private static function scope(string $option): string|array
    {
        $value = trim($option);
        if ($value === '' || $value === ApiAccess::SCOPE_SELF) {
            return ApiAccess::SCOPE_SELF;
        }
        if ($value === ApiAccess::SCOPE_ALL) {
            return ApiAccess::SCOPE_ALL;
        }

        $names = array_map('trim', explode(',', $value));

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }
}
