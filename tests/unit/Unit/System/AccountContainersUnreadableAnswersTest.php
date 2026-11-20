<?php

namespace Tests\Unit\System;

use App\System\AccountContainers;
use PHPUnit\Framework\TestCase;
use Tests\Support\AccountContainersUnreadableAnswersFixtures;

/** Listings Docker could not give, or gave in a shape the engine does not ask for. */
class AccountContainersUnreadableAnswersTest extends TestCase
{
    use AccountContainersUnreadableAnswersFixtures;

    public function test_a_ps_line_with_missing_fields_is_no_orphan(): void
    {
        $orphans = AccountContainers::orphansIn(
            "half\trunning\nswkorphan\texited\t" . self::USERS . "/swkorphan\n",
            self::USERS,
            [],
        );

        $this->assertSame(['swkorphan'], array_column($orphans, 'name'));
    }
}
