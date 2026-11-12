<?php

namespace App\Lib\Ssl;

use App\Models\Setting;
use App\System;
use App\System\Privileged;
use App\System\Privileged\InvalidArgument;

/**
 * Runs the engine's own certificate request on the host. It stops `sites-http` for the challenge,
 * so every hosted site is offline meanwhile; project certificates use {@see AcmeIssuer} instead.
 */
final class EngineCertificateRequest
{
    /** certbot is quick; the stack stop and start around it is not. */
    public const TIMEOUT = 900;

    /**
     * Output carries both streams, and `cert_domain` is what the script wrote.
     * A value the gateway refuses fails the request and runs nothing.
     *
     * @param  array{domain?: ?string, email?: ?string, ip?: ?string}  $values
     * @param  list<string>  $flags  any of staging, force-renewal, skip-dns-check, dry-run
     * @return array{successful: bool, output: string, cert_domain: ?string}
     */
    public static function run(System $system, array $values = [], array $flags = []): array
    {
        try {
            $args = Privileged::host()->requestEngineCertificate($system->engineDirPath(), $values, $flags);
        } catch (InvalidArgument $refused) {
            return ['successful' => false, 'output' => $refused->getMessage(), 'cert_domain' => Setting::get('cert_domain')];
        }
        $process = $system->runProcess($args, [], self::TIMEOUT);

        return [
            'successful' => $process->isSuccessful(),
            'output' => self::readable($process->getOutput()."\n".$process->getErrorOutput()),
            'cert_domain' => Setting::get('cert_domain'),
        ];
    }

    /** The script's output without the terminal colour codes, since it goes into JSON. */
    public static function readable(string $output): string
    {
        return trim((string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output));
    }
}
