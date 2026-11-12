<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Services\Webserver\AbstractWebserver;
use App\System\Services\Webserver\Apache;
use App\System\Services\Webserver\DefaultServerCertificate;
use App\System\Services\Webserver\Litespeed;
use App\System\Services\Webserver\Nginx;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\Openlitespeed;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A deploy renders the webserver config in a queue worker and an update as root; both find the IP certificate through
 * the root broker, so a no-op update after a deploy writes the same file and leaves sites-http running.
 */
class WebserverDefaultCertificateTest extends TestCase
{
    use InMemoryDatabase;

    private string $lineages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->lineages = sys_get_temp_dir().'/pa-default-cert-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->lineages));
        parent::tearDown();
    }

    public function test_with_an_ip_certificate_a_deploy_and_an_update_write_the_same_nginx_conf(): void
    {
        $ip = $this->lineages.'/'.DefaultServerCertificate::IP_LINEAGE;

        [$asRoot, $asWorker] = $this->renders([$ip.'/fullchain.pem', $ip.'/privkey.pem']);

        $this->assertSame($asRoot, $asWorker);
        $this->assertStringContainsString("ssl_certificate {$ip}/fullchain.pem;", $asWorker);
        $this->assertStringContainsString("ssl_certificate_key {$ip}/privkey.pem;", $asWorker);
    }

    public function test_with_a_domain_certificate_both_write_the_served_pair(): void
    {
        $domain = $this->lineages.'/panelalpha-engine-cert';

        [$asRoot, $asWorker] = $this->renders([$domain.'/fullchain.pem', $domain.'/privkey.pem']);

        $this->assertSame($asRoot, $asWorker);
        $this->assertServedPair($asWorker);
    }

    public function test_with_neither_both_write_the_self_signed_pair(): void
    {
        [$asRoot, $asWorker] = $this->renders([]);

        $this->assertSame($asRoot, $asWorker);
        $this->assertServedPair($asWorker);
    }

    public function test_an_ip_lineage_without_its_key_is_not_presented(): void
    {
        $ip = $this->lineages.'/'.DefaultServerCertificate::IP_LINEAGE;

        [$asRoot, $asWorker] = $this->renders([$ip.'/fullchain.pem']);

        $this->assertSame($asRoot, $asWorker);
        $this->assertServedPair($asWorker);
    }

    public function test_every_webserver_presents_the_one_default_certificate(): void
    {
        foreach ([NginxProxy::class, Nginx::class, Apache::class, Litespeed::class, Openlitespeed::class] as $driver) {
            foreach (['getSslCertVars', 'defaultServerCertificate'] as $method) {
                $this->assertSame(
                    AbstractWebserver::class,
                    (new \ReflectionMethod($driver, $method))->getDeclaringClass()->getName(),
                    "{$driver}::{$method}"
                );
            }
        }
    }

    private function assertServedPair(string $conf): void
    {
        $engine = dirname(__DIR__, 4);
        $this->assertStringContainsString("ssl_certificate {$engine}/crt/server.cert;", $conf);
        $this->assertStringContainsString("ssl_certificate_key {$engine}/crt/server.key;", $conf);
        $this->assertStringNotContainsString($this->lineages, $conf);
    }

    /**
     * nginx.conf rendered as root, which reads the lineages' files itself, and as the queue worker, which cannot; the
     * root broker finds the `$files` either way.
     *
     * @param  list<string>  $files
     * @return array{string, string}
     */
    private function renders(array $files): array
    {
        foreach ($files as $file) {
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0700, true);
            }
            file_put_contents($file, 'pem');
        }
        $asRoot = $this->render($files);

        exec('rm -rf '.escapeshellarg($this->lineages));
        $asWorker = $this->render($files);

        return [$asRoot, $asWorker];
    }

    /** @param list<string> $brokerFinds */
    private function render(array $brokerFinds): string
    {
        /** @var \ArrayObject<string, string> $written */
        $written = new \ArrayObject;
        $driver = new class($this->system($brokerFinds, $written), $this->lineages) extends NginxProxy
        {
            public function __construct(System $system, private string $lineages)
            {
                parent::__construct($system);
            }

            public function getAllIpsVars(): array
            {
                return ['ips_v4' => ['203.0.113.7'], 'ips_v6' => []];
            }

            public function restart(): void {}

            protected function defaultServerCertificate(): DefaultServerCertificate
            {
                return new DefaultServerCertificate($this->system, $this->lineages);
            }
        };

        $driver->toggleModsecurity();

        return (string) $written[dirname(__DIR__, 4).'/webserver-config/nginx-proxy/nginx.conf'];
    }

    /**
     * @param  list<string>  $brokerFinds
     * @param  \ArrayObject<string, string>  $written
     */
    private function system(array $brokerFinds, \ArrayObject $written): System
    {
        return new class($brokerFinds, $written) extends System
        {
            /**
             * @param  list<string>  $brokerFinds
             * @param  \ArrayObject<string, string>  $written
             */
            public function __construct(private array $brokerFinds, private \ArrayObject $written) {}

            public function engineDirPath(): string
            {
                return dirname(__DIR__, 4);
            }

            public function filesystem(): Filesystem
            {
                return new class($this, $this->written) extends Filesystem
                {
                    /** @param \ArrayObject<string, string> $written */
                    public function __construct(System $system, private \ArrayObject $written)
                    {
                        parent::__construct($system);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return (string) file_get_contents($path);
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->written[$path] = $contents;
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = (array) $cmd;
                $process = new Process(['sh', '-c', 'exit '.(in_array(end($argv), $this->brokerFinds, true) ? 0 : 2)]);
                $process->run();

                return $process;
            }
        };
    }
}
