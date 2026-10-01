<?php

namespace App\Console\Commands\Files;

use App\Console\Commands\Concerns\StreamsFileToOutput;
use App\Lib\Modsec\AuditLogFiles;
use Illuminate\Console\Command;

class ModsecAuditLogCommand extends Command
{
    use StreamsFileToOutput;

    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['modsec:audit-log'];

    protected $signature = 'modsec:log:show
                            {filename? : Audit log file to fetch; omit to list what is available}
                            {--tail : Read the tail of the file instead of downloading the whole of it}
                            {--out= : Local file to write (default: stdout)}';

    protected $description = 'List or download a ModSecurity audit log file';

    public function handle(): int
    {
        $filename = $this->argument('filename');
        $out = $this->option('out');
        $logs = new AuditLogFiles();

        if (!is_string($filename) || $filename === '') {
            $this->output->write(json_encode(['data' => $logs->list()], JSON_THROW_ON_ERROR));
            return 0;
        }

        if ($this->option('tail')) {
            // The tail is JSON, so --out does not apply to it.
            $this->output->write(json_encode(['data' => $logs->tailOrFail($filename)], JSON_THROW_ON_ERROR));
            return 0;
        }

        return $this->streamFile($logs->pathOrFail($filename), is_string($out) && $out !== '' ? $out : null);
    }
}
