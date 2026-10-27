<?php

namespace App\Console\Commands\Ssl;

use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\SslCheck;
use App\Lib\Ssl\SslCheckReport;
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
        $result = (new SslCheck)->forDomainName($name);

        if ($result === null) {
            $this->error("No project domain called {$name} on this engine.");

            return self::FAILURE;
        }

        $lines = $this->option('json') ? [SslCheckReport::json($result)] : SslCheckReport::lines($result);
        foreach ($lines as $line) {
            $this->line($line);
        }

        return $result['status'] === CertificateStatus::TRUSTED ? self::SUCCESS : self::FAILURE;
    }
}
