<?php

namespace App\Console\Commands\Ssl;

use App\Lib\Ssl\ProjectCertificate;
use App\Models\Domain;
use Illuminate\Console\Command;

/**
 * Obtain a real certificate for one project domain, on demand.
 *
 * The deploy asks for a certificate once, when the domain is created — which
 * is usually before its DNS points at this host, so the first answer is the
 * self-signed one. This is the second answer: run it when the name resolves
 * here and the site starts serving a certificate browsers accept, with no
 * redeploy and no downtime.
 *
 * `--dry-run` reports what would be attempted and why, without spending an
 * authorization against a rate limit that counts failures.
 *
 * The same thing over the API is `POST /projects/{u}/domains/{d}/request-ssl-cert`;
 * both go through {@see ProjectCertificate}.
 */
class ProjectCertRequest extends Command
{
    protected $signature = 'ssl:project-cert:request
        {domain : the project domain to certify}
        {--staging : use Let\'s Encrypt staging: untrusted, but spends no production rate limit}
        {--dry-run : say what would be attempted, request nothing}';

    protected $description = "Obtain a Let's Encrypt certificate for one project domain over HTTP-01";

    public function handle(): int
    {
        $name = strtolower(trim((string) $this->argument('domain')));
        $model = Domain::where('domain', $name)->first();

        if (! $model) {
            $this->error("No project domain called {$name} on this engine.");

            return self::FAILURE;
        }

        $staging = (bool) $this->option('staging');

        if ($this->option('dry-run')) {
            $plan = ProjectCertificate::plan($model, $staging);

            if ($plan['ineligible_reason'] !== null) {
                $this->error("Cannot request a certificate: {$plan['ineligible_reason']}.");

                return self::FAILURE;
            }

            $this->info("Would request a certificate for {$name}");
            $this->line('  authority: '.$plan['authority']);
            $this->line('  account:   '.($plan['account_email'] ?? 'registered without an email address'));
            $this->line('  challenge: '.$plan['challenge'].', served by this host at '.$plan['challenge_url']);

            return self::SUCCESS;
        }

        try {
            $status = ProjectCertificate::request($model, $staging);
        } catch (\Throwable $e) {
            $this->error("Could not obtain a certificate for {$name}: ".$e->getMessage());
            $this->line('The site keeps the certificate it already had.');

            return self::FAILURE;
        }

        $this->info("Installed a certificate for {$name}");
        $this->line("  status:  {$status['status']} (issuer: {$status['issuer']})");
        $this->line("  expires: {$status['expires_at']} ({$status['days_remaining']} days)");

        return self::SUCCESS;
    }
}
