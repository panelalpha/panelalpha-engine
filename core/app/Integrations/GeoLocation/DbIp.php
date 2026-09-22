<?php

namespace App\Integrations\GeoLocation;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use JsonException;
use MaxMind\Db\Reader\InvalidDatabaseException;
use RuntimeException;
use Throwable;

final class DbIp implements AcceptsDatabaseTerms, GeoLocation
{
    public const string TERMS = <<<'TEXT'
DB-IP IP to City Lite is licensed under Creative Commons Attribution 4.0 (CC BY 4.0).
Downloading this database means you accept those terms. Attribution (a visible
backlink to https://db-ip.com) is still required wherever humans see country,
continent, or region results. Confirming here only allows the download.
See https://db-ip.com and https://creativecommons.org/licenses/by/4.0/.
TEXT;

    public const string ATTRIBUTION = 'IP Geolocation by DB-IP (https://db-ip.com)';

    private ?Reader $cityReader = null;

    private bool $cityUnreadable = false;

    public function __construct(
        private string $dataRoot,
    ) {}

    /**
     * @throws JsonException
     */
    public function acceptTerms(): void
    {
        $stamp = $this->readStamp();
        $stamp['accepted_at'] = Carbon::now()->toIso8601String();
        $this->writeStamp($stamp);
    }

    public function updateDatabase(bool $force = false): void
    {
        if (! $this->termsAccepted()) {
            throw new TermsRequiredException(self::TERMS);
        }

        $month = Carbon::now()->format('Y-m');
        if (! $force && $this->storedMonth() === $month && $this->databasePath() !== null) {
            return;
        }

        $url = 'https://download.db-ip.com/free/dbip-city-lite-'.$month.'.mmdb.gz';
        try {
            $response = Http::timeout(120)->get($url);
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to download DB-IP City Lite: '.$e->getMessage(), 0, $e);
        }

        $status = $response->status();
        $body = (string) $response->body();
        if ($status === 404) {
            throw new RuntimeException("DB-IP City Lite is not available yet: {$url}");
        }
        if ($status !== 200) {
            throw new RuntimeException("Failed to download DB-IP City Lite ({$status}): {$url}");
        }

        $decoded = @gzdecode($body);
        if ($decoded === false) {
            throw new RuntimeException("Failed to decode DB-IP City Lite gzip: {$url}");
        }

        $this->replaceMmdb($decoded);
        $stamp = $this->readStamp();
        $stamp['year_month'] = $month;
        $this->writeStamp($stamp);
    }

    public function databasePath(): ?string
    {
        $path = $this->mmdbPath();
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return $path;
    }

    public function lookup(string $ip): ?array
    {
        $reader = $this->cityDatabase();
        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->city($ip);
        } catch (AddressNotFoundException) {
            return null;
        } catch (Throwable) {
            return null;
        }

        return [
            'country' => $record->country->name ?? '',
            'country_code' => $record->country->isoCode ?? '',
            'region' => $record->mostSpecificSubdivision->name ?? '',
            'region_code' => $record->mostSpecificSubdivision->isoCode ?? '',
        ];
    }

    private function termsAccepted(): bool
    {
        $accepted = $this->readStamp()['accepted_at'] ?? '';

        return is_string($accepted) && $accepted !== '';
    }

    private function storedMonth(): ?string
    {
        $month = $this->readStamp()['year_month'] ?? null;

        return is_string($month) && $month !== '' ? $month : null;
    }

    /**
     * @return array{accepted_at?: string, year_month?: string}
     */
    private function readStamp(): array
    {
        $path = $this->stampPath();
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{accepted_at?: string, year_month?: string} $stamp
     * @throws JsonException
     */
    private function writeStamp(array $stamp): void
    {
        $dir = $this->dir();
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create geolocation directory.');
        }
        $tmp = $this->stampPath().'.tmp';
        $json = json_encode($stamp, JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Could not write geolocation terms stamp.');
        }
        $this->replaceFile($tmp, $this->stampPath());
    }

    private function replaceMmdb(string $bytes): void
    {
        $dir = $this->dir();
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create geolocation directory.');
        }
        $tmp = $this->mmdbPath().'.tmp';
        if (file_put_contents($tmp, $bytes) === false) {
            throw new RuntimeException('Could not write geolocation database.');
        }
        $this->replaceFile($tmp, $this->mmdbPath());
        $this->cityReader = null;
        $this->cityUnreadable = false;
    }

    private function replaceFile(string $from, string $to): void
    {
        // Windows cannot rename over an existing file. copy() overwrites in
        // place so a failed replace still leaves the previous MMDB.
        if (PHP_OS_FAMILY === 'Windows') {
            if (! @copy($from, $to)) {
                @unlink($from);
                throw new RuntimeException('Could not replace geolocation file.');
            }
            @unlink($from);

            return;
        }
        if (! @rename($from, $to)) {
            @unlink($from);
            throw new RuntimeException('Could not replace geolocation file.');
        }
    }

    private function cityDatabase(): ?Reader
    {
        if ($this->cityUnreadable) {
            return null;
        }
        if ($this->cityReader instanceof Reader) {
            return $this->cityReader;
        }
        $path = $this->databasePath();
        if ($path === null) {
            $this->cityUnreadable = true;

            return null;
        }

        try {
            $this->cityReader = new Reader($path);
        } catch (InvalidDatabaseException) {
            $this->cityUnreadable = true;

            return null;
        }

        return $this->cityReader;
    }

    private function dir(): string
    {
        return $this->dataRoot.'/geolocation';
    }

    private function mmdbPath(): string
    {
        return $this->dir().'/dbip-city-lite.mmdb';
    }

    private function stampPath(): string
    {
        return $this->dir().'/dbip-city-lite.stamp';
    }
}
