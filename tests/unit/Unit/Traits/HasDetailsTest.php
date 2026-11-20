<?php

namespace Tests\Unit\Traits;

use App\Models\FtpAccount;
use PHPUnit\Framework\TestCase;

/** Details read as an empty array until set, and setting merges over what is there. */
class HasDetailsTest extends TestCase
{
    public function test_unset_details_read_as_empty(): void
    {
        $this->assertSame([], (new FtpAccount)->getDetails());
    }

    public function test_set_details_merges_and_overwrites_keys(): void
    {
        $account = new FtpAccount;
        $account->details = ['quota' => 10, 'directory' => '/public_html'];

        $merged = $account->setDetails(['quota' => 20, 'note' => 'backup']);

        $this->assertSame(['quota' => 20, 'directory' => '/public_html', 'note' => 'backup'], $merged);
        $this->assertSame($merged, $account->getDetails());
    }
}
