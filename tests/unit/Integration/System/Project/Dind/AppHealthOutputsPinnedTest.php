<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\System\Project\Dind\AppHealth;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppHealthOutputsPinnedFixtures;

/**
 * What AppHealth answers, output for output: the probe scripts byte for byte, the parsed
 * port results, the restart, stop and blank-page checks, and every way check() can end.
 */
class AppHealthOutputsPinnedTest extends TestCase
{
    use AppHealthOutputsPinnedFixtures;
    use MockeryPHPUnitIntegration;

    private const COMPOSE_PS_RESTARTING = '{"Name":"project-app-1","Service":"app","State":"restarting","Status":"Restarting (1) 2 seconds ago","ExitCode":1}';

    private const COMPOSE_PS_RUNNING = '{"Name":"project-app-1","Service":"app","State":"running","Status":"Up 3 hours","ExitCode":0}';

    public function test_a_probe_that_cannot_run_is_a_failed_check(): void
    {
        $health = $this->health(static fn (): array => [8000], new \RuntimeException("OCI runtime exec failed\nline two"));

        $this->pin('probe failed', $health->check(3, 2, 1));
        $this->pin('probe failed ran', $this->ranDigest());
    }

    public function test_a_cancelled_probe_is_rethrown(): void
    {
        $health = $this->health(static fn (): array => [8000], new DeployCancelledException('cancelled'));

        $this->expectException(DeployCancelledException::class);
        $health->check();
    }

    public function test_a_restarting_app_adds_the_loop_check(): void
    {
        $health = $this->health(static fn (): array => [8000, 8001], "8000\thttp\t000 0\tconnection refused\n", self::COMPOSE_PS_RESTARTING);

        $this->pin('restarting', $this->normalised($health->check(3, 2, 1, true)));
        $this->pin('restarting ran', $this->ranDigest());
    }

    public function test_a_stopped_app_is_served_as_stopped(): void
    {
        $health = $this->health(static fn (): array => [8000], "8000\thttp\t000 0\tconnection refused\n", self::COMPOSE_PS_STOPPED);

        $this->pin('stopped app', $this->normalised($health->check()));
        $this->pin('stopped app ran', $this->ranDigest());
    }

    public function test_a_running_app_that_answers_nothing_is_asked_what_it_listens_on(): void
    {
        $this->published = '{"Name":"project-app-1","Service":"app","State":"running","Publishers":[{"TargetPort":3000,"PublishedPort":8000,"Protocol":"tcp"}]}';
        $health = $this->health(static fn (): array => [8000], "8000\thttp\t000 0\tconnection refused\n", self::COMPOSE_PS_RUNNING);

        $this->pin('silent', $this->normalised($health->check()));
        $this->pin('silent ran', $this->ranDigest());
    }

    public function test_an_answering_app_is_checked(): void
    {
        $body = base64_encode('<html><head><title>Shop</title></head><body>Welcome</body></html>');
        $health = $this->health(static fn (): array => [8000], "8000\thttp\t200 0.01\t\t{$body}\t/\n", self::COMPOSE_PS_RUNNING, 'shop.example.com');

        $this->pin('answering', $this->normalised($health->check()));
        $this->pin('answering ran', $this->ranDigest());
    }

    /** @return array{count: int, sha256: string} */
    private function ranDigest(): array
    {
        return ['count' => count($this->ran), 'sha256' => hash('sha256', implode("\n", $this->ran))];
    }
}
