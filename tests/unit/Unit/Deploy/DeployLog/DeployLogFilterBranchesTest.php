<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rule of shouldSkip(), one line each side of it.
 */
class DeployLogFilterBranchesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function lines(): array
    {
        return [
            'blank' => ['   ', true],
            'empty' => ['', true],
            'image id' => ['  ae74b2c44f40  ', true],
            'image id with capitals' => ['AE74B2C44F40', false],
            'thirteen hex' => ['ae74b2c44f401', false],
            'gitconfig lock' => ['error: could not lock config file /root/.gitconfig: File exists', true],
            'other config lock' => ['error: could not lock config file /etc/app.conf', false],
            'buildkit internal' => ['#3 [internal] load metadata for docker.io/library/node:20', true],
            'buildkit transferring' => ['#5 transferring context: 2.10kB done', true],
            'buildkit resolve' => ['#6 resolve docker.io/library/node:20@sha256:abc', true],
            'buildkit load build context' => ['#7 load build context', true],
            'buildkit lowercase internal' => ['#3 [INTERNAL] load .dockerignore', true],
            'buildkit run step' => ['#8 [2/5] RUN npm ci', false],
            'buildkit instant done' => ['#9 DONE 0.0s', true],
            'buildkit timed done' => ['#9 DONE 0.1s', false],
            'asset js' => ['dist/app.js 12 kB', true],
            'asset mjs' => ['  out/chunk-1.mjs   3.5KB', true],
            'asset cjs' => ['lib/index.cjs 1 MB', true],
            'asset css' => ['public/app.css 900 b', true],
            'asset map' => ['public/app.js.map 2.1 mB', true],
            'asset without size' => ['dist/app.js', false],
            'asset other extension' => ['dist/app.png 12 kB', false],
            'composer downloading' => ['  - Downloading laravel/framework (v11.0.0)', true],
            'composer installing' => ['- installing psr/log (3.0.0): Extracting archive', true],
            'composer upgrading' => ['  - Upgrading psr/log (3.0.0 => 3.0.1)', false],
            'docker df filesystem' => ['Filesystem      Size  Used Avail Use% Mounted on', true],
            'docker prune id' => ['ID      RECLAIMABLE  SIZE', true],
            'reclaimable' => ['Reclaimable: 1.2GB', true],
            'total' => ['Total: 3', true],
            'progress fraction' => ['12/40 [=====>      ] 30%', true],
            'progress without bracket' => ['12/40 done', false],
            'browserslist' => ['Browserslist: browsers data (caniuse-lite) is 7 months old. Please run:', true],
            'browserslist other' => ['Browserslist: caniuse-lite is outdated', false],
            'npm notice' => ['npm notice New major version of npm available!', true],
            'npm warn' => ['npm warn deprecated inflight@1.0.6', false],
            'indented npm notice' => ['  npm notice New version', true],
            'failure' => ['ERROR: failed to solve: process did not complete successfully', false],
        ];
    }

    #[DataProvider('lines')]
    public function test_each_rule_decides_its_own_lines(string $line, bool $skipped): void
    {
        $this->assertSame($skipped, DeployLogFilter::shouldSkip($line));
    }
}
