<?php

namespace App\Console\Commands\Ssl;

use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\ProjectCertificate;
use App\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keep the real certificates real.
 *
 * A Let's Encrypt certificate lasts 90 days, so an engine that issues one and
 * forgets it has simply moved the outage 90 days out — and unlike the first
 * issuance, nobody is watching when it happens. Renewals are exempt from the
 * new-certificate rate limit, so running this daily costs nothing.
 *
 * It renews only what it already issued: a domain still on the self-signed
 * certificate is left alone, because the reason it never got a real one
 * (DNS not pointing here, a shared wildcard zone) is not something a renewal
 * loop can fix and retrying it daily would spend the failure limit forever.
 * `ssl:project-cert:request` is the deliberate first attempt.
 */
class ProjectCertRenew extends Command
{
    /** Let's Encrypt's own advice: renew with a third of the life left. */
    private const RENEW_BELOW_DAYS = 30;

    protected $signature = 'ssl:project-cert:renew
        {--days= : renew certificates with fewer than this many days left (default 30)}
        {--domain= : only this domain}
        {--force : renew regardless of how much life is left}
        {--staging : renew against Let\'s Encrypt staging: untrusted, but spends no production rate limit}
        {--dry-run : list what would be renewed, request nothing}';

    protected $description = 'Renew project domain certificates that are close to expiring';

    public function handle(): int
    {
        $threshold = (int) ($this->option('days') ?: self::RENEW_BELOW_DAYS);
        $only = $this->option('domain');
        $issuer = ProjectCertificate::issuer((bool) $this->option('staging'));

        $query = Domain::query();
        if (is_string($only) && $only !== '') {
            $query->where('domain', strtolower(trim($only)));
        }

        $renewed = 0;
        $failed = 0;

        // Pages, not a cursor: an open cursor pins SQLite's read snapshot, and once
        // anything else writes during the HTTP-01 check, recording details.ssl fails.
        foreach ($query->lazyById() as $model) {
            $name = $model->domain;

            if ($issuer->ineligibleReason($name) !== null) {
                continue;
            }

            $connection = $model->projectDomain();
            if (!$connection->hasSslCertificate()) {
                continue;
            }

            $status = CertificateStatus::of($connection->getSslCertificateInfo(), $name);

            if (!$this->isDue($status, $threshold)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("would renew {$name} ({$status['status']}, {$status['days_remaining']} days left)");
                $renewed++;

                continue;
            }

            try {
                $issuer->issue($connection);
                $connection->rebuild();
                ProjectCertificate::remember($model->user);
                $this->info("renewed {$name}");
                $renewed++;
            } catch (\Throwable $e) {
                // One domain's authority problem is not the next domain's.
                $this->error("could not renew {$name}: " . $e->getMessage());
                Log::warning("Certificate renewal failed for {$name}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->line("{$renewed} renewed, {$failed} failed");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Due when it is running out, already expired, or no longer signed by
     * anyone — never when it is a self-signed certificate this engine put
     * there on purpose.
     *
     * @param array{status: string, self_signed: bool, days_remaining: ?int} $status
     */
    private function isDue(array $status, int $threshold): bool
    {
        if ($status['self_signed'] ?? false) {
            return false;
        }
        if ($this->option('force')) {
            return true;
        }
        if ($status['status'] === CertificateStatus::EXPIRED) {
            return true;
        }

        return $status['days_remaining'] !== null && $status['days_remaining'] < $threshold;
    }
}
