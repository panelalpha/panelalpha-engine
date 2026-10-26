<?php

namespace App\Lib\Ssl;

use App\Models\Setting;
use App\System;

/**
 * Running the engine's own certificate request, for the command and the API.
 *
 * The work is `scripts/letsencrypt-request-cert.sh` in the host namespace:
 * certbot needs the host's `:80`, and the compose stack has to be stopped and
 * started around it, neither of which can be done from inside this container.
 *
 * **This takes every hosted site offline for the length of the challenge.**
 * `sites-http` holds `:80` and certbot's standalone listener wants it, so the
 * service is stopped and brought back — usually seconds, but seconds during
 * which nothing on the box answers. That is tolerable for a deliberate,
 * occasional act and is the reason this is not the mechanism project
 * certificates use ({@see AcmeIssuer} serves its challenge through the
 * running webserver instead).
 *
 * It is also why the API method that calls this is worth reading twice before
 * calling.
 */
final class EngineCertificateRequest
{
    /** certbot is quick; the stack stop and start around it is not. */
    public const TIMEOUT = 900;

    /**
     * @param  array{domain?: ?string, email?: ?string, ip?: ?string}  $values
     * @param  list<string>  $flags  any of staging, force-renewal, skip-dns-check, dry-run
     * @return array{successful: bool, output: string, cert_domain: ?string}
     */
    public static function run(System $system, array $values = [], array $flags = []): array
    {
        $script = $system->engineDirPath().'/scripts/letsencrypt-request-cert.sh';
        $args = ['sudo', 'nsenter', '--target', '1', '--all', 'bash', $script];

        foreach (['domain', 'email', 'ip'] as $name) {
            $value = $values[$name] ?? null;
            if (is_string($value) && $value !== '') {
                $args[] = "--{$name}={$value}";
            }
        }
        foreach (['staging', 'force-renewal', 'skip-dns-check', 'dry-run'] as $flag) {
            if (in_array($flag, $flags, true)) {
                $args[] = "--{$flag}";
            }
        }

        $process = $system->runProcess($args, [], self::TIMEOUT);

        return [
            'successful' => $process->isSuccessful(),
            // Both streams: the script reports progress on stdout and
            // certbot's own refusals on stderr, and a caller that only sees
            // one of them is reading half the answer.
            'output' => self::readable($process->getOutput()."\n".$process->getErrorOutput()),
            // Written by the script itself after a successful issue, so it is
            // read back rather than assumed.
            'cert_domain' => Setting::get('cert_domain'),
        ];
    }

    /**
     * The script colours its output for a terminal, and this one is going
     * into JSON. Left in, the reason a request was refused arrives as
     * `\e[31mnot-pointing-here.example.com does not resolve…` — still
     * readable by a person squinting, and noise to everything else.
     */
    public static function readable(string $output): string
    {
        return trim((string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output));
    }
}
