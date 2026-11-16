<?php

namespace Tests\Unit\Findings;

use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Finding;
use PHPUnit\Framework\TestCase;

class FindingTest extends TestCase
{
    public function test_a_stored_finding_comes_back_with_its_facts(): void
    {
        $finding = new Finding(PublicUrl::CERTIFICATE_REFUSED, 'Refused.', ['domain' => 'shop.example.com', 'certificate' => ['status' => 'expired']]);

        $this->assertEquals($finding, Finding::fromStored($finding->toArray()));
        $this->assertSame(
            ['code' => PublicUrl::CERTIFICATE_REFUSED, 'message' => 'Refused.', 'domain' => 'shop.example.com', 'certificate' => ['status' => 'expired']],
            $finding->toArray(),
        );
    }

    public function test_a_sentence_stored_before_findings_had_a_code_is_unclassified(): void
    {
        $this->assertEquals(
            [new Finding(Finding::UNCLASSIFIED, 'The application started but did not answer.')],
            Finding::allStored(['  The application started but did not answer. ', '']),
        );
    }

    public function test_hints_in_what_was_stored_are_not_facts(): void
    {
        $finding = Finding::fromStored(['code' => 'x', 'message' => 'y', 'hints' => [['type' => 'cli']], 'domain' => 'd']);

        $this->assertSame(['domain' => 'd'], $finding?->facts);
    }

    public function test_anything_else_is_dropped(): void
    {
        $this->assertSame([], Finding::allStored([42, ['message' => 'no code'], ['code' => 'no message'], null]));
        $this->assertSame([], Finding::allStored('not a list'));
    }

    public function test_the_sentence_joins_the_messages(): void
    {
        $this->assertSame('One. | Two.', Finding::sentence([new Finding('a', 'One.'), new Finding('b', 'Two.')]));
        $this->assertSame('', Finding::sentence([]));
    }
}
