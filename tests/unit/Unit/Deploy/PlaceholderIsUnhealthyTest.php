<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\Health\CheckRunner;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The engine's own "Project not configured" page answering is no application running: the engine's verdict says so,
 * not only the checks beside it.
 */
class PlaceholderIsUnhealthyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_the_placeholder_is_not_healthy(): void
    {
        $verdict = $this->health(404, '<html><title>'.PlaceholderPage::NOT_CONFIGURED_TITLE.'</title></html>')->check();

        $this->assertFalse($verdict['healthy']);
        $this->assertSame(CheckRunner::SERVING_PLACEHOLDER, $verdict['serving']);
    }

    /** Plenty of applications answer 404 on `/`: only the placeholder is no application. */
    public function test_an_applications_own_404_stays_healthy(): void
    {
        $verdict = $this->health(404, '<html><title>Not Found</title><p>No route for /</p></html>')->check();

        $this->assertTrue($verdict['healthy']);
        $this->assertSame(CheckRunner::SERVING_OK, $verdict['serving']);
    }

    private function health(int $code, string $body): AppHealth
    {
        $probe = "8080\thttp\t{$code} 0.001\t\t".base64_encode($body)."\n";

        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('userAppComposeFileToRun')->andReturn('/home/acme/docker-compose.yml');
        $dind->shouldReceive('userAppDirPath')->andReturn('/home/acme/project');
        $dind->shouldReceive('userAppComposeCommand')
            ->andReturn(['docker', 'compose', '-f', '/home/acme/docker-compose.yml', 'ps', '--format', 'json', '--all']);
        $dind->shouldReceive('userModel')->andReturn(new User());
        $dind->shouldReceive('composeFilePath')->andReturn('/home/acme/docker-compose.yml');
        $dind->shouldReceive('homeDirPath')->andReturn('/home/acme');
        $dind->shouldReceive('username')->andReturn('acme');

        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')->andReturnUsing(static function (string|array $cmd) use ($probe): string {
            $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;

            return str_contains($line, 'curl') || str_contains($line, 'bash') ? $probe : '';
        });
        $dind->shouldReceive('system')->andReturn($system);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind));

        return new class ($dind) extends AppHealth {
            public function ports(): array
            {
                return [8080];
            }
        };
    }
}
