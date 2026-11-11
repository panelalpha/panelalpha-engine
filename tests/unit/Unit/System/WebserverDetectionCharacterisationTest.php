<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Pins how the webserver slug is detected and which reload the broker is asked for per slug.
 */
class WebserverDetectionCharacterisationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-webserver-detect-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        putenv('PA_ROOT_LOCAL_ROOTS='.json_encode(['engine' => $this->dir]));
        $this->forgetWebserver();
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        $this->forgetWebserver();
        @unlink($this->dir.'/docker-compose.yml-webserver');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_the_compose_override_label_names_the_webserver(): void
    {
        $this->composeOverride("services:\n  sites-http:\n    labels:\n      - com.panelalpha.webserver=OpenLiteSpeed\n");
        $host = $this->host();

        $this->assertSame('openlitespeed', $host->webserver()->getCurrentWebserver());
        $this->assertSame([], $host->execs);
    }

    public function test_the_compose_override_label_in_mapping_form(): void
    {
        $this->composeOverride("labels:\n  com.panelalpha.webserver = apache\n");

        $this->assertSame('apache', $this->host()->webserver()->getCurrentWebserver());
    }

    public function test_an_override_without_the_label_asks_the_broker_for_the_container(): void
    {
        $this->composeOverride("services: {}\n");
        $host = $this->host('{"Labels":"com.docker.compose.project=engine,com.panelalpha.webserver=nginx-proxy"}');

        $this->assertSame('nginx-proxy', $host->webserver()->getCurrentWebserver());
        $this->assertSame([
            [LocalRoot::CLIENT, 'compose.serviceContainers', '--', $this->dir.'/docker-compose.yml', 'sites-http'],
        ], $host->requests);
    }

    public function test_a_container_without_the_label_is_unknown(): void
    {
        $this->assertSame('unknown', $this->host('{"Labels":"a=b"}')->webserver()->getCurrentWebserver());
    }

    public function test_an_unparsable_compose_answer_throws(): void
    {
        $this->expectExceptionMessage('Could not parse docker compose response');

        $this->host('not json')->webserver()->getCurrentWebserver();
    }

    public function test_the_slug_is_remembered_until_detected_again(): void
    {
        $host = $this->host('{"Labels":"com.panelalpha.webserver=apache"}');
        $host->webserver()->getCurrentWebserver();
        $host->webserver()->getCurrentWebserver();
        $this->assertCount(1, $host->execs);

        $host->webserver()->detectWebserver();
        $this->assertCount(2, $host->execs);
    }

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function reloads(): array
    {
        return [
            'litespeed' => ['litespeed', false, 'litespeed'],
            'openlitespeed' => ['openlitespeed', true, 'litespeed'],
            'nginx reload' => ['nginx', false, 'nginx'],
            'nginx rebind' => ['nginx', true, 'nginx-rebind'],
            'apache' => ['apache', false, 'apache'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reloads')]
    public function test_each_webserver_asks_the_broker_for_its_own_reload(string $slug, bool $rebind, string $kind): void
    {
        $host = $this->host('{"Labels":"com.panelalpha.webserver='.$slug.'"}');

        $host->webserver()->scheduleWebserverReloadInBackground($rebind);

        $this->assertSame([implode(' ', [
            LocalRoot::CLIENT, '--host', 'webserver.queueReload', '--', $this->dir.'/docker-compose.yml', $kind,
        ])], $host->hostCommands);
    }

    public function test_an_unknown_webserver_queues_nothing(): void
    {
        $host = $this->host('{"Labels":"a=b"}');

        $host->webserver()->scheduleWebserverReloadInBackground();

        $this->assertSame([], $host->hostCommands);
    }

    public function test_a_failed_detection_queues_nothing(): void
    {
        $host = $this->host(null);

        $host->webserver()->scheduleWebserverReloadInBackground(true);

        $this->assertSame([], $host->hostCommands);
    }

    public function test_a_reload_the_broker_refused_is_logged_with_both_outputs(): void
    {
        Log::spy();
        $host = $this->host('{"Labels":"com.panelalpha.webserver=apache"}');
        $host->hostAnswer = FakeProcess::of(1, 'out', 'err');

        $host->webserver()->scheduleWebserverReloadInBackground();

        Log::shouldHaveReceived('warning')->once()->with('Could not queue the webserver reload on the host: err out');
    }

    private function composeOverride(string $contents): void
    {
        file_put_contents($this->dir.'/docker-compose.yml-webserver', $contents);
    }

    private function forgetWebserver(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
    }

    private function host(?string $psAnswer = '{"Labels":""}'): DetectionHost
    {
        return new DetectionHost($this->dir, $psAnswer);
    }
}

final class DetectionHost extends System
{
    /** @var list<string|list<string>> */
    public array $execs = [];

    /** @var list<string> */
    public array $hostCommands = [];

    /** @var list<list<string>> */
    public array $requests = [];

    public ?Process $hostAnswer = null;

    public function __construct(private string $dir, private ?string $psAnswer) {}

    public function engineDirPath(): string
    {
        return $this->dir;
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $argv = is_array($cmd) ? array_values($cmd) : [];
        $this->execs[] = is_array($cmd) ? LocalRoot::plain($argv) : $cmd;
        $this->requests[] = $argv;
        if ($this->psAnswer === null) {
            throw new \RuntimeException('compose is not reachable');
        }

        return $this->psAnswer;
    }

    public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $argv = is_array($cmd) ? array_values($cmd) : [];
        $this->requests[] = $argv;
        $this->hostCommands[] = implode(' ', $argv);

        return $this->hostAnswer ?? FakeProcess::ok();
    }
}
