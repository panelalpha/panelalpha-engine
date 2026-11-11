<?php

namespace App\Mcp;

use Illuminate\Support\Facades\Cache;

/**
 * The Mcp-Session-Id values this engine issued, so one it never issued can be
 * refused as the transport spec asks instead of being served.
 */
class McpSessions
{
    /** A session id is remembered for a week; after that the client initializes again. */
    private const TTL_SECONDS = 604800;

    private const KEY = 'mcp:session:';

    public function remember(string $id): void
    {
        Cache::put(self::KEY.$id, true, self::TTL_SECONDS);
    }

    public function knows(string $id): bool
    {
        return Cache::has(self::KEY.$id);
    }
}
