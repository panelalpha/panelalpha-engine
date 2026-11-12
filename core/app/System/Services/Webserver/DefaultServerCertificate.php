<?php

namespace App\System\Services\Webserver;

use App\Lib\Ssl\EngineCertificate;
use App\System;
use App\System\Privileged;

/**
 * The engine's IP certificate while its Let's Encrypt lineage holds both files, else the pair it serves on :2011.
 * Looked up through the root broker, as /etc/letsencrypt/live is root's, so a queue worker and an update agree.
 */
final class DefaultServerCertificate
{
    public const IP_LINEAGE = 'panelalpha-engine-ip-cert';

    public function __construct(
        private readonly System $system,
        private readonly string $lineagesDir = ProxyRuleCertificate::LETSENCRYPT_LIVE,
    ) {}

    /** @return array{ssl_cert_file: string, ssl_cert_key_file: string} */
    public function templateVars(): array
    {
        $lineage = $this->lineagesDir.'/'.self::IP_LINEAGE;
        if ($this->exists($lineage.'/fullchain.pem') && $this->exists($lineage.'/privkey.pem')) {
            return ['ssl_cert_file' => $lineage.'/fullchain.pem', 'ssl_cert_key_file' => $lineage.'/privkey.pem'];
        }

        $engine = new EngineCertificate($this->system);

        return ['ssl_cert_file' => $engine->certificatePath(), 'ssl_cert_key_file' => $engine->keyPath()];
    }

    /** A lineage's file is a link into its archive; the broker lists it only when what it links to is there. */
    private function exists(string $file): bool
    {
        return $this->system->runProcess(Privileged::files()->listNames($file))->isSuccessful();
    }
}
