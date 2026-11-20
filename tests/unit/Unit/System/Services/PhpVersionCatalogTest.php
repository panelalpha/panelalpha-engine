<?php

namespace Tests\Unit\System\Services;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Services\Php;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The PHP versions a project can pick come from the shipped project template, the first one being the default. */
class PhpVersionCatalogTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../../../templates/user/default/project';

    public function test_the_default_is_the_first_version_the_template_lists(): void
    {
        $listed = file(self::TEMPLATE.'/php/versions-available', FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
        $php = new Php($this->system(self::TEMPLATE));

        $this->assertSame($listed, $php->listAvailablePhpVersions());
        $this->assertSame($listed[0], $php->defaultPhpVersion());
    }

    public function test_the_list_is_read_once_per_catalog(): void
    {
        $system = $this->system(self::TEMPLATE);
        $php = new Php($system);

        $first = $php->listAvailablePhpVersions();
        $system->template = '/nonexistent';

        $this->assertSame($first, $php->listAvailablePhpVersions());
    }

    public function test_a_template_without_a_version_list_has_no_default(): void
    {
        $php = new Php($this->system('/nonexistent'));

        $this->assertSame([], $php->listAvailablePhpVersions());
        $this->assertNull($php->defaultPhpVersion());
    }

    private function system(string $template): System
    {
        return new class ($template) extends System {
            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                throw new \LogicException('No process may start from this test.');
            }

            public function __construct(public string $template) {}

            public function projectFilesTemplateDirPath(?string $template = null): string
            {
                return $this->template;
            }
        };
    }
}
