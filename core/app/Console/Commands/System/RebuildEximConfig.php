<?php

namespace App\Console\Commands\System;

use App\System;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class RebuildEximConfig extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:rebuild-exim-config'];

    protected $signature = 'system:exim:rebuild
        {--networks : Only re-read what the engine decides for Exim: where it listens and relays, the sending domain, and the senders each project may use}
        {--senders : Only re-read the addresses and domains each project may send mail as}';

    protected $description = 'Recreate configuration files for exim4 from database.';

    /**
     * Any failure leaves the listen/relay addresses stale, so it exits non-zero
     * and says why on stderr for the caller (bootstrap-from-source.sh) to see.
     */
    public function handle(): int
    {
        try {
            $system = app(System::class);
            if ($this->option('senders')) {
                $system->exim()->refreshSenderMap();
            } elseif ($this->option('networks')) {
                $system->exim()->rebuildNetworks();
            } else {
                $system->exim()->rebuildEximConfig();
            }
        } catch (Throwable $e) {
            $this->getOutput()->getErrorStyle()->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
