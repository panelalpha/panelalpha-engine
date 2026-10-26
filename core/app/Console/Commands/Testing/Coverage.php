<?php

namespace App\Console\Commands\Testing;

use App\Models\Setting;
use App\System;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Laravel\Sanctum\PersonalAccessToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;
use SebastianBergmann\CodeCoverage\StaticAnalysis\FileAnalyser;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingSourceAnalyser;
use Symfony\Component\Process\Process;

class Coverage extends Command
{
    protected $signature = 'testing:coverage
                            {--start-recording : Enable coverage recording}
                            {--stop-recording : Stop coverage recording}
                            {--generate-html-report : Generate merged HTML report}
                            {--serve-html-report : Serve generated HTML report}
                            {--host= : Host to serve the report on}
                            {--port= : Port to serve the report on}
                            {--cleanup : Remove all temporary files related to the API token}
                            {--api-token-id=}';

    protected $description = 'Manage per-token coverage recording and reports';

    public function handle(): int
    {
        $tokenId = $this->option('api-token-id');
        if (empty($tokenId) || ! is_string($tokenId)) {
            $this->error('Please provide --api-token-id');

            return 1;
        }

        $argc = 0;
        ! empty($this->option('start-recording')) && $argc++;
        ! empty($this->option('stop-recording')) && $argc++;
        ! empty($this->option('generate-html-report')) && $argc++;
        ! empty($this->option('serve-html-report')) && $argc++;
        ! empty($this->option('cleanup')) && $argc++;

        if ($argc === 0) {
            $this->error('Too few arguments');

            return 1;
        }

        if ($argc > 1) {
            $this->error('Too many arguments');

            return 1;
        }

        if (! App::hasDebugModeEnabled()) {
            $this->error('Debug mode is disabled');

            return 1;
        }

        if (! extension_loaded('xdebug')) {
            $this->error('xdebug extension is not loaded');

            return 1;
        }

        if (! PersonalAccessToken::query()->where('id', $tokenId)->exists()) {
            $this->error('Invalid API token ID');

            return 1;
        }

        $dir = config('testing.coverage_dir')."/{$tokenId}";

        $system = new System;

        if ($this->option('start-recording')) {
            $system->exec(['sudo', 'mkdir', '-p', $dir.'/dumps']);
            $system->exec(['sudo', 'chown', '-R', 'www-data:www-data', $dir.'/dumps']);
            $system->exec(['sudo', 'touch', $dir.'/recording']);
            $this->info("Started coverage recording for token {$tokenId}");

            return 0;
        }

        if ($this->option('stop-recording')) {
            $system->exec(['sudo', 'rm', '-rf', $dir.'/recording']);
            $this->info("Stopped coverage recording for token {$tokenId}");

            return 0;
        }

        if ($this->option('generate-html-report')) {
            $this->generateHtmlReport($dir);
            $this->info("HTML report generated for token {$tokenId}");

            return 0;
        }

        if ($this->option('serve-html-report')) {
            if (! is_file($dir.'/html-report/index.html')) {
                $this->error('HTML report is not yet generated');

                return 1;
            }

            $host = $this->option('host') ?? Setting::get('default_ipv4');
            assert(is_string($host));
            $port = $this->option('port') ?? '8000';
            assert(is_string($port));

            $this->info("Serving HTML coverage report at http://{$host}:{$port} ...");
            $process = new Process([
                'sudo',
                'nsenter',
                '--target',
                '1',
                '--all',
                'docker',
                'run',
                '--rm',
                '-it',
                '--network',
                'host',
                '-v',
                config('testing.coverage_host_dir')."/{$tokenId}/html-report:/report",
                'php:8.3',
                'php',
                '-S',
                "{$host}:{$port}",
                '-t',
                '/report',
            ]);

            $process->setTty(true);
            $process->setTimeout(null);
            $process->run(function (string $type, string $buffer) {
                echo $buffer;
            });

            // block until Ctrl+C:
            $process->wait();
        }

        if ($this->option('cleanup')) {
            $system->exec(['sudo', 'rm', '-rf', $dir]);
            $this->info("Removed files related to token {$tokenId}");

            return 0;
        }

        return 0;
    }

    protected function generateHtmlReport(string $dir): void
    {
        $filter = new Filter;
        $filter->includeFiles($this->coverageSourceFiles());

        $driver = (new Selector)->forLineCoverage($filter);
        $coverage = new CodeCoverage($driver, $filter);
        $coverage->includeUncoveredFiles();
        $coverage->enableAnnotationsForIgnoringCode();
        $coverage->ignoreDeprecatedCode();

        /** @psalm-suppress InternalClass, InternalMethod */
        $analyser = new FileAnalyser(
            new ParsingSourceAnalyser,
            true,  // useAnnotationsForIgnoringCode (@codeCoverageIgnore)
            true   // ignoreDeprecatedCode
        );

        foreach ($filter->files() as $file) {
            /** @psalm-suppress InternalClass, InternalMethod */
            $raw = RawCodeCoverageData::fromUncoveredFile($file, $analyser);
            $coverage->append($raw, 'uncovered-'.basename($file));
        }

        // Merge in per-request dumps
        foreach (glob($dir.'/dumps/*.rawcov') as $file) {
            /** @var mixed $raw */
            $raw = unserialize(file_get_contents($file));
            if ($raw instanceof RawCodeCoverageData) {
                $coverage->append($raw, basename($file));
            }
        }

        (new HtmlReport)->process($coverage, $dir.'/html-report');
    }

    /**
     * @return list<string>
     */
    private function coverageSourceFiles(): array
    {
        $appDir = base_path('app');
        $excluded = [
            // $appDir . '/Console',
            // $appDir . '/Providers',
        ];

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            foreach ($excluded as $prefix) {
                if (str_starts_with($path, $prefix.DIRECTORY_SEPARATOR) || $path === $prefix) {
                    continue 2;
                }
            }
            $files[] = $path;
        }

        return $files;
    }
}
