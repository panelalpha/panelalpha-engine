<?php

namespace Tests\Unit\Mcp;

use App\Mcp\ToolRegistry;
use Tests\TestCase;

/**
 * The full tool list size the assistant guide states matches the engine's.
 */
class DocumentedToolCountTest extends TestCase
{
    private const GUIDE = 'docs/04-connecting-your-ai/your-assistant.md';

    public function test_the_guide_states_the_number_of_tools_the_engine_offers(): void
    {
        $guide = (string) file_get_contents(dirname(base_path()).'/'.self::GUIDE);

        $this->assertSame(1, preg_match('/The full list is (\d+) tools/u', $guide, $matches), 'the guide no longer states how large the full list is');
        $this->assertSame(
            count(ToolRegistry::all()),
            (int) $matches[1],
            self::GUIDE.' states a tool count the engine no longer has'
        );
    }
}
