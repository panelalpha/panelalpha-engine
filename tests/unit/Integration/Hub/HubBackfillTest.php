<?php

namespace Tests\Integration\Hub;

use App\Lib\Hub\HubBackfill;
use App\Lib\Hub\HubBackfillWindow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/** History uploads for a window the Hub asked for: under its size limit, the window unchanged, the last one done. */
class HubBackfillTest extends HubTestCase
{
    private const METRICS = self::HUB.'/api/engine/v1/metrics';

    private const WINDOW = ['from' => '2026-11-22T10:00:00Z', 'to' => '2026-11-24T10:00:00Z', 'step' => 60];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_a_large_window_is_split_under_the_limit_with_the_window_unchanged(): void
    {
        $this->project('shop');
        $this->minutes('shop', 2880, containers: 40);
        $window = HubBackfillWindow::from(self::WINDOW);
        $this->assertNotNull($window);

        $uploads = (new HubBackfill)->uploads($window);

        $this->assertGreaterThan(1, count($uploads));
        foreach ($uploads as $i => $upload) {
            $this->assertLessThan(1024 * 1024, strlen((string) json_encode($upload)), "upload {$i}");
            $this->assertSame(self::WINDOW, $upload['window']);
            $this->assertSame($i === array_key_last($uploads), $upload['done']);
        }
        $point = $uploads[0]['projects']->shop['total'][0];
        $this->assertSame(['period', 'cpu_percent', 'memory_bytes', 'memory_limit_bytes'], array_keys($point));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:00$/', $point['period'], 'on a whole step');
        $this->assertArrayHasKey('svc0', (array) $uploads[0]['projects']->shop['containers'], 'keyed by compose service');
    }

    public function test_a_window_with_no_history_still_closes(): void
    {
        $window = HubBackfillWindow::from(self::WINDOW);
        $this->assertNotNull($window);

        $uploads = (new HubBackfill)->uploads($window);

        $this->assertCount(1, $uploads);
        $this->assertTrue($uploads[0]['done']);
        $this->assertSame('{}', json_encode($uploads[0]['projects']), 'an object, never a list');
    }

    public function test_the_last_answer_names_the_next_window_and_a_closed_window_stops(): void
    {
        $connection = $this->pushConnection();
        $next = ['from' => '2026-10-25T10:00:00Z', 'to' => '2026-11-22T10:00:00Z', 'step' => 3600];
        Http::fake([self::METRICS => Http::sequence()
            ->push(['data' => ['backfill' => $next, 'kept' => 10]])
            ->push(['message' => 'No open window.', 'code' => 'no_open_window'], 409)]);
        $window = HubBackfillWindow::from(self::WINDOW);
        $this->assertNotNull($window);

        $this->assertSame($next, (new HubBackfill)->send($connection, $window)?->toArray());
        $this->assertSame('done', $connection->refresh()->last_backfill['result']);

        $this->assertNull((new HubBackfill)->send($connection, $window));
        $this->assertSame('closed by the Hub', $connection->refresh()->last_backfill['result']);
        Http::assertSentCount(2);
    }

    public function test_a_failing_upload_is_tried_three_times_then_given_up(): void
    {
        $connection = $this->pushConnection();
        Http::fake([self::METRICS => Http::response(['message' => 'down'], 503)]);
        $window = HubBackfillWindow::from(self::WINDOW);
        $this->assertNotNull($window);

        $this->assertNull((new HubBackfill)->send($connection, $window));

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Signature'));
        $this->assertSame('gave up', $connection->refresh()->last_backfill['result']);
    }

    public function test_only_windows_the_hub_could_have_handed_out_are_taken(): void
    {
        $this->assertNull(HubBackfillWindow::from(['from' => '2026-11-24T10:00:00Z', 'to' => '2026-11-22T10:00:00Z', 'step' => 60]));
        $this->assertNull(HubBackfillWindow::from(['from' => '2026-11-22T10:00:00Z', 'to' => '2026-11-24T10:00:00Z', 'step' => 300]));
        $this->assertNull(HubBackfillWindow::from('2026-11-22'));
    }

    private function minutes(string $username, int $count, int $containers): void
    {
        $start = Carbon::parse(self::WINDOW['from']);
        $rows = [];
        $inner = [];
        for ($i = 0; $i < $count; $i++) {
            $at = $start->copy()->addMinutes($i)->format('Y-m-d H:i:s');
            $rows[] = ['timestamp' => $at, 'username' => $username, 'cpu_percent' => 1.5, 'memory_bytes' => 1000, 'memory_limit_bytes' => 4000];
            for ($c = 0; $c < $containers; $c++) {
                $inner[] = ['timestamp' => $at, 'username' => $username, 'container_id' => str_pad((string) $c, 64, 'a'),
                    'name' => "shop-svc{$c}-1", 'service' => "svc{$c}", 'cpu_percent' => 0.5, 'memory_bytes' => 500, 'memory_limit_bytes' => null];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('project_metrics')->insert($chunk);
        }
        foreach (array_chunk($inner, 500) as $chunk) {
            DB::table('project_container_metrics')->insert($chunk);
        }
    }
}
