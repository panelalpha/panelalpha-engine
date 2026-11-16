<?php

namespace App\Console\Commands\Ssl;

use App\Models\Setting;
use App\System;
use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Obtain the engine's own served certificate (crt/server.cert on :2011) from
 * Let's Encrypt.
 *
 * The work is scripts/letsencrypt-request-cert.sh, run in the host namespace:
 * certbot needs the host's :80 and the compose stack has to be stopped and
 * started around it, neither of which can be done from inside this container.
 * The name comes from --domain, else the `cert_domain` setting, else the
 * address-derived default (203-0-113-7.panelalpha.direct) -- the script
 * decides, and records what it issued back into `cert_domain`.
 */
class EngineCertRequest extends Command
{
    protected $signature = 'ssl:engine-cert:request
        {--domain= : the name to certify; default: the cert_domain setting, else <dashed-public-ip>.panelalpha.direct}
        {--email= : ACME account email; default: the cert_email setting, else register without one}
        {--ip= : the public address, when detection gets it wrong}
        {--staging : Let\'s Encrypt staging: untrusted, but rate-limit free}
        {--force-renewal : reissue even when the current certificate is not due}
        {--skip-dns-check : do not verify the name resolves to this host}
        {--dry-run : full challenge, no certificate}';

    protected $description = "Obtain the engine's Let's Encrypt certificate for :2011 (the cert_domain setting or the .panelalpha.direct default)";

    public function handle(System $system): int
    {
        try {
            $args = Privileged::host()->requestEngineCertificate($system->engineDirPath(), $this->values(), $this->flags());
        } catch (InvalidArgument $refused) {
            $this->error($refused->getMessage());

            return 1;
        }

        $domain = $this->option('domain') ?: Setting::get('cert_domain');
        $this->info($domain
            ? "Requesting a certificate for {$domain}"
            : 'Requesting a certificate for the default name derived from the public address');

        // certbot itself is quick; the stack stop/start around it is not.
        $process = $system->runProcessWithCallbacks($args, [], 900, null, function (string $type, string $buffer): void {
            $type === Process::ERR ? $this->output->write("<comment>{$buffer}</comment>") : $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('The certificate was not issued; the served certificate is unchanged');

            return 1;
        }

        $issued = Setting::get('cert_domain');
        if ($issued && ! $this->option('dry-run')) {
            $this->info("Serving https://{$issued}:2011");
        }

        return 0;
    }

    /** @return array{domain: ?string, email: ?string, ip: ?string} */
    private function values(): array
    {
        $values = [];
        foreach (['domain', 'email', 'ip'] as $name) {
            $value = $this->option($name);
            $values[$name] = is_string($value) ? $value : null;
        }

        return $values;
    }

    /** @return list<string> */
    private function flags(): array
    {
        return array_values(array_filter(
            ['staging', 'force-renewal', 'skip-dns-check', 'dry-run'],
            fn (string $flag): bool => (bool) $this->option($flag)
        ));
    }
}
