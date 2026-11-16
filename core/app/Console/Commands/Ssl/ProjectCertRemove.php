<?php

namespace App\Console\Commands\Ssl;

use App\Exceptions\ProblemException;
use App\Lib\Ssl\ProjectCertificate;
use App\Models\Domain;
use Illuminate\Console\Command;

/**
 * Take a domain off the certificate installed on it and put it back on the one a new domain gets.
 *
 * What installing a certificate over the API has no counterpart for: the engine keeps no copy of the certificate it
 * replaced, so the only way back is the way forward, a certificate the engine makes or issues itself.
 *
 * The same thing over the API is `DELETE /projects/{u}/domains/{d}/installed-ssl-cert`; both go through
 * {@see ProjectCertificate::remove()}.
 */
class ProjectCertRemove extends Command
{
    protected $signature = 'ssl:project-cert:remove
        {domain : the project domain whose certificate to remove}';

    protected $description = "Remove a project domain's installed certificate and give it the engine's own";

    public function handle(): int
    {
        $name = strtolower(trim((string) $this->argument('domain')));
        $model = Domain::where('domain', $name)->first();

        if (! $model) {
            $this->error("No project domain called {$name} on this engine.");

            return self::FAILURE;
        }

        try {
            $cert = ProjectCertificate::remove($model);
        } catch (ProblemException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Could not put another certificate on {$name}: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$name} is on a certificate the engine made");
        $this->line("  status:  {$cert['status']} (issuer: {$cert['issuer']})");
        $this->line("  expires: {$cert['expires_at']} ({$cert['days_remaining']} days)");

        return self::SUCCESS;
    }
}
