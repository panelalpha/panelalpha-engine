<?php

namespace Tests\Unit\Integrations\GeoLocation;

use App\Integrations\GeoLocation\DbIp;
use App\Integrations\GeoLocation\TermsRequiredException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DbIpTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pa-geolocation-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00', 'UTC'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_missing_file_yields_null_path(): void
    {
        $dbip = $this->driver();

        $this->assertNull($dbip->databasePath());
        $this->assertNull($dbip->lookup('8.8.8.8'));
        Http::assertNothingSent();
    }

    public function test_invalid_mmdb_lookup_returns_null(): void
    {
        mkdir($this->root.'/geolocation', 0775, true);
        file_put_contents($this->mmdbPath(), 'not-a-real-mmdb');
        $dbip = $this->driver();

        $this->assertNull($dbip->lookup('8.8.8.8'));
        Http::assertNothingSent();
    }

    public function test_update_without_terms_does_not_write(): void
    {
        $dbip = $this->driver();

        try {
            $dbip->updateDatabase();
            $this->fail('Expected terms-required error');
        } catch (TermsRequiredException $e) {
            $this->assertStringContainsString('CC BY', $e->getMessage());
            $this->assertStringContainsString('db-ip.com', $e->getMessage());
        }

        $this->assertNull($dbip->databasePath());
        $this->assertFileDoesNotExist($this->mmdbPath());
        Http::assertNothingSent();
    }

    public function test_successful_update_stores_readable_city_mmdb(): void
    {
        $payload = $this->gzip("MMDB\x00fixture");
        Http::fake(['*' => Http::response($payload, 200)]);
        $dbip = $this->driver();
        $dbip->acceptTerms();
        $dbip->updateDatabase();

        $path = $dbip->databasePath();
        $this->assertSame($this->mmdbPath(), $path);
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertSame("MMDB\x00fixture", (string) file_get_contents($path));
        $this->assertDownloadedThisMonth();
    }

    public function test_download_failure_keeps_previous_mmdb(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->gzip('first-mmdb'), 200)
                ->push('nope', 500),
        ]);
        $dbip = $this->driver();
        $dbip->acceptTerms();
        $dbip->updateDatabase();
        $this->assertSame('first-mmdb', (string) file_get_contents((string) $dbip->databasePath()));

        try {
            $dbip->updateDatabase(true);
            $this->fail('Expected fetch failure');
        } catch (RuntimeException) {
        }

        $this->assertSame($this->mmdbPath(), $dbip->databasePath());
        $this->assertSame('first-mmdb', (string) file_get_contents((string) $dbip->databasePath()));
    }

    public function test_bad_gzip_keeps_previous_mmdb(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->gzip('first-mmdb'), 200)
                ->push('this is not gzip', 200),
        ]);
        $dbip = $this->driver();
        $dbip->acceptTerms();
        $dbip->updateDatabase();

        try {
            $dbip->updateDatabase(true);
            $this->fail('Expected decode failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('gzip', $e->getMessage());
        }

        $this->assertSame($this->mmdbPath(), $dbip->databasePath());
        $this->assertSame('first-mmdb', (string) file_get_contents((string) $dbip->databasePath()));
    }

    public function test_same_month_is_a_success_noop(): void
    {
        Http::fake(['*' => Http::response($this->gzip('once'), 200)]);
        $dbip = $this->driver();
        $dbip->acceptTerms();
        $dbip->updateDatabase();
        $dbip->updateDatabase();

        $this->assertDownloadedThisMonth();
        $this->assertSame('once', (string) file_get_contents((string) $dbip->databasePath()));
    }

    public function test_force_downloads_even_when_this_month_is_stored(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->gzip('v1'), 200)
                ->push($this->gzip('v2'), 200),
        ]);
        $dbip = $this->driver();
        $dbip->acceptTerms();
        $dbip->updateDatabase();
        $dbip->updateDatabase(true);

        Http::assertSentCount(2);
        $this->assertSame('v2', (string) file_get_contents((string) $dbip->databasePath()));
    }

    public function test_404_fails_with_this_month_url_and_does_not_try_previous_month(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $dbip = $this->driver();
        $dbip->acceptTerms();

        try {
            $dbip->updateDatabase();
            $this->fail('Expected 404');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'https://download.db-ip.com/free/dbip-city-lite-2026-09.mmdb.gz',
                $e->getMessage()
            );
            $this->assertStringNotContainsString('2026-08', $e->getMessage());
        }

        $this->assertDownloadedThisMonth();
        $this->assertNull($dbip->databasePath());
    }

    private function driver(): DbIp
    {
        return new DbIp($this->root);
    }

    private function assertDownloadedThisMonth(): void
    {
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url()
            === 'https://download.db-ip.com/free/dbip-city-lite-2026-09.mmdb.gz');
    }

    private function gzip(string $bytes): string
    {
        $encoded = gzencode($bytes, 9);
        $this->assertNotFalse($encoded);

        return $encoded;
    }

    private function mmdbPath(): string
    {
        return $this->root.'/geolocation/dbip-city-lite.mmdb';
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
