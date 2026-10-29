<?php

namespace App\Console\Commands\Domains;

use App\Lib\Helper;
use App\Lib\Ssl\SharedZones;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * The domain new projects are given a name under.
 *
 * A project created without a domain of its own becomes
 * `<name>.<this setting>` ({@see Helper::generateDomainName()}). It
 * has always defaulted to `<dashed-ip>.sslip.io`, which resolves to this host
 * with no DNS to set up — the reason a fresh install works at all, and the
 * reason those sites can never hold a real certificate: sslip.io is one
 * registered domain shared by everyone, and Let's Encrypt's limit is counted
 * per registered domain. {@see SharedZones}
 *
 * So this is the one setting that turns real certificates on. Point a wildcard
 * A record for a domain you control at this host, set it here, and every
 * project created afterwards gets a name under it that an authority will
 * certify.
 *
 * Existing projects keep the domain they were created with — renaming a live
 * site's domain is a different operation with different consequences, and it
 * is not this one.
 */
class SitesBaseDomain extends Command
{
    private const SETTING = 'default_wildcard_domain';

    protected $signature = 'sites:base-domain
        {domain? : the domain new projects are named under; omit to show the current one}
        {--force : accepted for compatibility; a shared zone is no longer refused}';

    protected $description = 'Show or set the domain new project sites are given a name under';

    public function handle(): int
    {
        $current = (string) (Setting::get(self::SETTING) ?? '');
        $domain = $this->argument('domain');

        if (! is_string($domain) || trim($domain) === '') {
            return $this->show($current);
        }

        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));

        if (! SharedZones::isPubliclyIssuable($domain)) {
            $this->error("{$domain} is not a hostname sites can be named under.");

            return self::FAILURE;
        }

        $shared = SharedZones::covers($domain);

        Setting::set(self::SETTING, $domain);
        $this->info("New project sites will be named under {$domain}");

        if ($shared) {
            $this->warn("{$domain} is a wildcard zone every engine shares.");
            $this->line('Certificates for sites under it come out of one fleet-wide budget:');
            $this->line("Let's Encrypt counts 50 new certificates per registered domain per");
            $this->line('week, renewals exempt. See docs/05-capabilities/domains-and-ssl.md.');
        }

        $this->line('');
        $this->line('For sites there to answer and to be issued certificates:');
        $this->line("  1. point *.{$domain} at this host (a wildcard A record)");
        $this->line('  2. `pae-artisan settings:set ssl_issuer acme` to turn issuance on');
        $this->line('  3. new projects get a real certificate as they are created;');
        $this->line('     `pae-artisan ssl:project-cert:request <domain>` does an existing one');

        return self::SUCCESS;
    }

    private function show(string $current): int
    {
        if ($current === '') {
            $this->line('No base domain set; new projects fall back to <name>.local.');

            return self::SUCCESS;
        }

        $this->line("Sites are named under: {$current}");
        $this->line(SharedZones::covers($current)
            ? '  a wildcard zone the fleet shares — certificates here spend a budget'
            .' shared with every other engine on it'
            : '  a domain of your own — certificates here have their own budget');

        return self::SUCCESS;
    }
}
