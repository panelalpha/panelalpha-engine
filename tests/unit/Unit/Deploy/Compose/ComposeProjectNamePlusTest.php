<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeProjectName;
use PHPUnit\Framework\TestCase;

/** `${VAR+word}` gives the word when the variable is set, even to nothing, and nothing when it is unset. */
class ComposeProjectNamePlusTest extends TestCase
{
    public function test_the_word_follows_whether_the_variable_is_set(): void
    {
        $files = ["name: shop\${STAGE+-staged}\n"];

        $this->assertSame('shop-staged', ComposeProjectName::of('/home/acct/project', $files, ["STAGE=\n"]));
        $this->assertSame('shop', ComposeProjectName::of('/home/acct/project', $files, []));
    }
}
