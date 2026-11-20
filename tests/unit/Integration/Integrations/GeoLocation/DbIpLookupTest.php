<?php

namespace Tests\Integration\Integrations\GeoLocation;

use App\Integrations\GeoLocation\DbIp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * Looking addresses up in a City database, here a one-node MaxMind DB built by the test,
 * and a download that never arrives or cannot be put in place.
 */
class DbIpLookupTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-geolocation-lookup-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/geolocation', 0775, true);
        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00', 'UTC'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_an_address_in_the_database_reads_as_its_country_and_region(): void
    {
        $known = TestNetwork::public(7);
        $this->install($known, 'DBIP-City-Lite', [
            'country' => ['iso_code' => 'PL', 'names' => ['en' => 'Poland']],
            'subdivisions' => [['iso_code' => '14', 'names' => ['en' => 'Mazovia']]],
        ]);
        $dbip = new DbIp($this->root);

        $expected = ['country' => 'Poland', 'country_code' => 'PL', 'region' => 'Mazovia', 'region_code' => '14'];
        $this->assertSame($expected, $dbip->lookup($known));
        $this->assertNull($dbip->lookup($this->outside($known)));
        $this->assertSame($expected, $dbip->lookup($known));
    }

    public function test_a_country_without_regions_has_an_empty_region(): void
    {
        $known = TestNetwork::public(8);
        $this->install($known, 'DBIP-City-Lite', ['country' => ['iso_code' => 'MC', 'names' => ['en' => 'Monaco']]]);

        $this->assertSame(
            ['country' => 'Monaco', 'country_code' => 'MC', 'region' => '', 'region_code' => ''],
            (new DbIp($this->root))->lookup($known)
        );
    }

    public function test_a_database_that_is_not_a_city_database_places_nobody(): void
    {
        $known = TestNetwork::public(9);
        $this->install($known, 'DBIP-Country-Lite', ['country' => ['iso_code' => 'PL', 'names' => ['en' => 'Poland']]]);

        $this->assertNull((new DbIp($this->root))->lookup($known));
    }

    public function test_a_download_that_cannot_connect_names_the_reason(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $dbip = new DbIp($this->root);
        $dbip->acceptTerms();

        try {
            $dbip->updateDatabase();
            $this->fail('an unreachable download must raise');
        } catch (RuntimeException $e) {
            $this->assertSame('Failed to download DB-IP City Lite: cURL error 28: timed out', $e->getMessage());
        }

        $this->assertNull($dbip->databasePath());
    }

    public function test_a_database_that_cannot_be_put_in_place_leaves_no_temporary_file(): void
    {
        Http::fake(['*' => Http::response((string) gzencode('mmdb-bytes'), 200)]);
        mkdir($this->root.'/geolocation/dbip-city-lite.mmdb/occupied', 0775, true);
        $dbip = new DbIp($this->root);
        $dbip->acceptTerms();

        try {
            $dbip->updateDatabase();
            $this->fail('a database that cannot replace the old one must raise');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not replace geolocation file.', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->root.'/geolocation/dbip-city-lite.mmdb.tmp');
        $this->assertDirectoryExists($this->root.'/geolocation/dbip-city-lite.mmdb/occupied');
    }

    /**
     * A database whose single node sends every address that starts with $known's first bit to
     * $record, and every other address nowhere.
     *
     * @param  array<string, mixed>  $record
     */
    private function install(string $known, string $type, array $record): void
    {
        $nodeCount = 1;
        $toRecord = $nodeCount + 16;
        [$zero, $one] = self::firstBit($known) === 0 ? [$toRecord, $nodeCount] : [$nodeCount, $toRecord];
        $tree = substr(pack('N', $zero), 1).substr(pack('N', $one), 1);
        $metadata = self::encode([
            'binary_format_major_version' => 2,
            'binary_format_minor_version' => 0,
            'build_epoch' => 1758196800,
            'database_type' => $type,
            'description' => ['en' => 'test'],
            'ip_version' => 4,
            'languages' => ['en'],
            'node_count' => $nodeCount,
            'record_size' => 24,
        ]);

        file_put_contents(
            $this->root.'/geolocation/dbip-city-lite.mmdb',
            $tree.str_repeat("\0", 16).self::encode($record)."\xAB\xCD\xEFMaxMind.com".$metadata
        );
    }

    /** An address whose first bit differs from $address's. */
    private function outside(string $address): string
    {
        return self::firstBit($address) === 0 ? TestNetwork::MULTICAST : TestNetwork::LOOPBACK;
    }

    private static function firstBit(string $address): int
    {
        return ((int) explode('.', $address)[0]) >> 7;
    }

    /** One value in the MaxMind DB data format: strings, unsigned ints, maps and arrays. */
    private static function encode(mixed $value): string
    {
        if (is_string($value)) {
            return self::control(2, strlen($value)).$value;
        }
        if (is_int($value)) {
            $bytes = ltrim(pack('N', $value), "\0");

            return self::control(6, strlen($bytes)).$bytes;
        }
        if (array_is_list($value)) {
            return self::control(11, count($value)).implode('', array_map(self::encode(...), $value));
        }
        $pairs = '';
        foreach ($value as $key => $item) {
            $pairs .= self::encode((string) $key).self::encode($item);
        }

        return self::control(7, count($value)).$pairs;
    }

    private static function control(int $type, int $size): string
    {
        if ($size >= 29) {
            throw new \LogicException('the test database only needs short values');
        }

        return $type <= 7 ? chr(($type << 5) | $size) : chr($size).chr($type - 7);
    }
}
