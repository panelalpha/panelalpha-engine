<?php

namespace App\Console\Commands\Files;

use App\Console\Commands\Concerns\StreamsFileToOutput;
use App\Lib\Domains\DomainLogFiles;
use App\Models\User;
use Illuminate\Console\Command;

class DomainLogFileCommand extends Command
{
    use StreamsFileToOutput;

    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['domain:log:show', 'domains:log-file'];

    protected $signature = 'project:domain:log
                            {project : Project username}
                            {domain : Domain the log belongs to}
                            {filename? : Log file to fetch; omit to list what is available}
                            {--all-webservers : Include log files from every webserver, not just the active one}
                            {--out= : Local file to write (default: stdout)}';

    protected $description = 'List or download a domain log file';

    public function handle(): int
    {
        $filename = $this->argument('filename');
        $out = $this->option('out');
        $all = (bool) $this->option('all-webservers');

        $user = User::findByUsernameOrFail((string) $this->argument('project'));
        $logFiles = DomainLogFiles::findOrFail($user, (string) $this->argument('domain'));

        if (!is_string($filename) || $filename === '') {
            $this->output->write(json_encode(['data' => $logFiles->list($all)], JSON_THROW_ON_ERROR));
            return 0;
        }

        $source = $logFiles->pathOrFail($filename, $all);

        return $this->streamFile($source, is_string($out) && $out !== '' ? $out : null);
    }
}
