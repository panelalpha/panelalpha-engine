<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceRequestAborted;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeSourceHttpClient;

/**
 * Pins how one process-batch answer becomes the batch result, and how failed calls are retried.
 */
class SourceProcessBatchCharacterisationTest extends TestCase
{
    public function test_every_field_of_the_answer_is_read(): void
    {
        $http = $this->answers([200, [
            'finalized' => 1,
            'phase' => 'files',
            'next_phase' => 'cleanup',
            'progress' => ['current_item' => 'a.php', 'next_item' => 'b.php', 'percentage' => '42.5', 'sent_chunks' => '3', 'total_chunks' => 10],
        ]]);

        $this->assertSame([
            'finalized' => true,
            'current_phase' => 'files',
            'current_item' => 'a.php',
            'current_phase_percent' => 42.5,
            'next_item' => 'b.php',
            'next_phase' => 'cleanup',
            'sent_chunks' => 3,
            'total_chunks' => 10,
        ], $this->client($http)->processBatch('mig-1'));
    }

    public function test_an_answer_without_progress_reads_as_empty(): void
    {
        $http = $this->answers([200, ['phase' => 5, 'next_phase' => null, 'progress' => 'none']]);

        $this->assertSame([
            'finalized' => false,
            'current_phase' => null,
            'current_item' => null,
            'current_phase_percent' => 0.0,
            'next_item' => null,
            'next_phase' => null,
            'sent_chunks' => 0,
            'total_chunks' => 0,
        ], $this->client($http)->processBatch('mig-1'));
    }

    public function test_two_failures_are_retried_and_the_third_answer_is_used(): void
    {
        $http = $this->answers([500, ['message' => 'one']], [500, ['message' => 'two']], [200, ['finalized' => true]]);

        $this->assertTrue($this->client($http)->processBatch('mig-1')['finalized']);
        $this->assertCount(3, $http->calls);
        $this->assertSame('{"migration_id":"mig-1"}', $http->calls[2]['body']);
    }

    public function test_the_third_failure_is_thrown(): void
    {
        $http = $this->answers([500, ['message' => 'one']], [500, ['message' => 'two']], [500, ['message' => '<b>three</b>']]);

        try {
            $this->client($http)->processBatch('mig-1');
            $this->fail('the failure was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('three', $e->getMessage());
        }
        $this->assertCount(3, $http->calls);
    }

    public function test_an_aborted_request_is_not_retried(): void
    {
        $http = new FakeSourceHttpClient;
        $http->pushHandler(static fn (string $method, string $url): SourceHttpResponse => throw SourceRequestAborted::at($url));

        try {
            $this->client($http)->processBatch('mig-1');
            $this->fail('the abort was swallowed');
        } catch (SourceRequestAborted) {
        }
        $this->assertCount(1, $http->calls);
    }

    /** @param array{int, array<string, mixed>} ...$answers */
    private function answers(array ...$answers): FakeSourceHttpClient
    {
        $http = new FakeSourceHttpClient;
        foreach ($answers as [$status, $body]) {
            $http->pushHandler(static fn (): SourceHttpResponse => new SourceHttpResponse((string) json_encode($body), $status));
        }

        return $http;
    }

    private function client(FakeSourceHttpClient $http): SourceWordpressClient
    {
        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));
        $client->useSourceToken('token');

        return $client;
    }
}
