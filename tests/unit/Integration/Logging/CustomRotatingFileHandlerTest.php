<?php

namespace Tests\Integration\Logging;

use App\Logging\CustomRotatingFileHandler;
use App\System\Privileged\Root;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A log the web user cannot open is handed to it through the root broker and the record
 * written on the second try, instead of the request failing over its own log line.
 */
class CustomRotatingFileHandlerTest extends TestCase
{
    private string $dir;

    private string $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/pa-rotating-log-'.bin2hex(random_bytes(4));
        $this->dir = $base.'/logs';
        $this->broker = $base.'/broker';
        mkdir($this->dir, 0775, true);
        mkdir($this->broker);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        exec('rm -rf '.escapeshellarg(dirname($this->dir)));
        parent::tearDown();
    }

    public function test_a_writable_log_is_written_without_asking_root(): void
    {
        $this->brokerFixing(false);
        $handler = new CustomRotatingFileHandler($this->dir.'/engine.log');

        $handler->handle($this->record('first line'));
        $handler->close();

        $this->assertStringContainsString('first line', (string) file_get_contents($handler->getUrl()));
        $this->assertFileDoesNotExist($this->broker.'/requests.log');
    }

    public function test_a_log_that_cannot_be_opened_is_handed_to_the_web_user_and_written(): void
    {
        $this->brokerFixing(true);
        $handler = new CustomRotatingFileHandler($this->dir.'/engine.log');
        mkdir($handler->getUrl());

        $handler->handle($this->record('after the repair'));
        $handler->close();

        $this->assertStringContainsString('after the repair', (string) file_get_contents($handler->getUrl()));
        $request = (string) file_get_contents($this->broker.'/requests.log');
        $this->assertStringStartsWith('files.changeOwnerRecursively', $request);
        $this->assertStringContainsString('www-data:www-data', $request);
        $this->assertStringContainsString($this->dir, $request);
    }

    public function test_a_log_root_cannot_repair_either_fails_the_write(): void
    {
        $this->brokerFixing(false);
        $handler = new CustomRotatingFileHandler($this->dir.'/engine.log');
        mkdir($handler->getUrl());

        $this->expectException(\UnexpectedValueException::class);

        $handler->handle($this->record('lost'));
    }

    /** A pa-root that logs each request and, when $repairs, clears the directory standing where a log should be. */
    private function brokerFixing(bool $repairs): void
    {
        $client = $this->broker.'/pa-root';
        $log = escapeshellarg($this->broker.'/requests.log');
        $repair = $repairs
            ? 'for a in "$@"; do if [ -d "$a" ]; then for f in "$a"/*.log; do [ -d "$f" ] && rmdir "$f"; done; fi; done'
            : '';
        file_put_contents($client, "#!/bin/sh\necho \"\$@\" >> {$log}\n{$repair}\nexit 0\n");
        chmod($client, 0755);
        Root::answerWith($client);
    }

    private function record(string $message): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable, 'engine', Level::Error, $message);
    }
}
