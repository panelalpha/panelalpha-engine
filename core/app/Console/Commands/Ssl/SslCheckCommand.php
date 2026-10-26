<?php

namespace App\Console\Commands\Ssl;

use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\SslCheck;
use App\Models\Domain;
use Illuminate\Console\Command;

/**
 * The certificate a project domain really serves, read over a TLS handshake.
 *
 * The same thing over the API is `GET /projects/{u}/domains/{d}/ssl-check`; both go through {@see SslCheck}.
 * Exits 0 only when visitors get a trusted certificate, so a script can act on the exit code.
 */
class SslCheckCommand extends Command
{
    protected $signature = 'ssl:check
        {domain : the project domain to check}
        {--json : print the whole result as JSON}';

    protected $description = 'Check the certificate a project domain really serves, over a TLS handshake';

    public function handle(): int
    {
        $name = strtolower(trim((string) $this->argument('domain')));
        $model = Domain::where('domain', $name)->first();

        if (! $model) {
            $this->error("No project domain called {$name} on this engine.");

            return self::FAILURE;
        }

        $result = (new SslCheck)->forDomain($model);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->report($result);
        }

        return $result['status'] === CertificateStatus::TRUSTED ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function report(array $result): void
    {
        $this->line("{$result['domain']}: {$result['status']}");
        $this->line('  signer:  '.$this->signer($result['signer']));
        $this->line('  expires: '.$this->expiry($result));
        $this->line('  dns:     '.$this->dns($result['dns']));
        $this->line('  public:  '.$this->probe($result['public']));
        $this->line('  engine:  '.$this->probe($result['engine']).$this->installed($result['engine']));
    }

    /**
     * @param  ?array{common_name: ?string, organization: ?string, country: ?string}  $signer
     */
    private function signer(?array $signer): string
    {
        if ($signer === null) {
            return '-';
        }

        $parts = array_filter([$signer['organization'], $signer['common_name'], $signer['country']]);

        return $parts === [] ? 'unknown' : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function expiry(array $result): string
    {
        return $result['expires_at'] === null ? '-' : "{$result['expires_at']} ({$result['days_remaining']} days)";
    }

    /**
     * @param  array{addresses: list<string>, expected: list<string>, points_here: bool}  $dns
     */
    private function dns(array $dns): string
    {
        if ($dns['addresses'] === []) {
            return 'does not resolve';
        }

        $where = $dns['points_here'] ? 'points here' : 'expected '.(implode(', ', $dns['expected']) ?: 'none');

        return implode(', ', $dns['addresses'])." ({$where})";
    }

    /**
     * @param  ?array<string, mixed>  $probe
     */
    private function probe(?array $probe): string
    {
        if ($probe === null) {
            return 'not checked';
        }

        $certificate = $probe['certificate'] ?? null;
        if (! is_array($certificate)) {
            return "{$probe['address']}: {$probe['error']}";
        }

        $expiry = isset($certificate['expires_at'])
            ? ", expires {$certificate['expires_at']} ({$certificate['days_remaining']} days)"
            : '';

        return "{$probe['address']}: {$certificate['status']}, issuer ".($certificate['issuer'] ?? 'unknown').$expiry;
    }

    /**
     * @param  ?array<string, mixed>  $engine
     */
    private function installed(?array $engine): string
    {
        return match ($engine['serves_installed'] ?? null) {
            true => ', the installed certificate',
            false => ', NOT the installed certificate (reload the webserver?)',
            default => '',
        };
    }
}
