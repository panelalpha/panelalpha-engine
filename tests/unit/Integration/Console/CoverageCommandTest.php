<?php

namespace Tests\Integration\Console;

use App\Console\Commands\Testing\Coverage;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** `pae testing:coverage` refuses a call it cannot act on before it touches anything. */
class CoverageCommandTest extends TestCase
{
    /** @param array<string, mixed> $args */
    private function coverage(array $args): array
    {
        $code = Artisan::call('testing:coverage', $args);

        return [$code, Artisan::output()];
    }

    public function test_a_token_id_is_required(): void
    {
        [$code, $out] = $this->coverage(['--start-recording' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Please provide --api-token-id', $out);
    }

    public function test_one_action_is_required(): void
    {
        [$code, $out] = $this->coverage(['--api-token-id' => '7']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Too few arguments', $out);
    }

    public function test_only_one_action_is_taken(): void
    {
        [$code, $out] = $this->coverage(['--api-token-id' => '7', '--start-recording' => true, '--cleanup' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Too many arguments', $out);
    }

    public function test_it_needs_debug_mode(): void
    {
        config(['app.debug' => false]);

        [$code, $out] = $this->coverage(['--api-token-id' => '7', '--stop-recording' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Debug mode is disabled', $out);
    }

    public function test_it_needs_xdebug(): void
    {
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('xdebug is loaded here');
        }
        config(['app.debug' => true]);

        [$code, $out] = $this->coverage(['--api-token-id' => '7', '--generate-html-report' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('xdebug extension is not loaded', $out);
    }

    public function test_the_report_covers_every_php_file_of_the_app(): void
    {
        $files = (new \ReflectionMethod(Coverage::class, 'coverageSourceFiles'))->invoke(new Coverage());

        $this->assertContains(base_path('app/Console/Commands/Testing/Coverage.php'), $files);
        $this->assertContains(base_path('app/Models/User.php'), $files);
        $this->assertSame([], array_values(array_filter($files, static fn (string $f): bool => ! str_ends_with($f, '.php'))));
        $this->assertSame([], array_values(array_filter($files, static fn (string $f): bool => ! str_starts_with($f, base_path('app') . '/'))));
    }
}
