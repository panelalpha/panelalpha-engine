<?php

namespace App\Console\Commands\Testing;

use App\System;
use App\System\Privileged;
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

class Coverage extends Command
{
    protected $signature = 'testing:coverage
                            {--start-recording : Enable coverage recording}
                            {--stop-recording : Stop coverage recording}
                            {--generate-html-report : Generate merged HTML report}
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

        if (! $this->xdebugLoaded()) {
            $this->error('xdebug extension is not loaded');

            return 1;
        }

        if (! PersonalAccessToken::query()->where('id', $tokenId)->exists()) {
            $this->error('Invalid API token ID');

            return 1;
        }

        $dir = config('testing.coverage_dir')."/{$tokenId}";

        $system = app(System::class);

        if ($this->option('start-recording')) {
            $system->exec(Privileged::files()->makeDirectories($dir.'/dumps'));
            $system->exec(Privileged::files()->changeOwnerRecursively('www-data:www-data', $dir.'/dumps'));
            $system->exec(Privileged::files()->touch($dir.'/recording'));
            $this->info("Started coverage recording for token {$tokenId}");

            return 0;
        }

        if ($this->option('stop-recording')) {
            $system->exec(Privileged::files()->removeTree($dir.'/recording'));
            $this->info("Stopped coverage recording for token {$tokenId}");

            return 0;
        }

        if ($this->option('generate-html-report')) {
            $this->generateHtmlReport($dir);
            $this->info("HTML report generated for token {$tokenId}: ".config('testing.coverage_host_dir')."/{$tokenId}/html-report/index.html on the host");

            return 0;
        }

        if ($this->option('cleanup')) {
            $system->exec(Privileged::files()->removeTree($dir));
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

    protected function xdebugLoaded(): bool
    {
        return extension_loaded('xdebug');
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
