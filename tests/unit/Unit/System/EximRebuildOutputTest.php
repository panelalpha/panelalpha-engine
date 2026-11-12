<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Services\Exim;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** Every file rebuildEximConfig() writes, for each provider, and the order it reads, writes and applies them in. */
class EximRebuildOutputTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-exim-out-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config/exim', 0777, true);
        mkdir($this->root . '/templates/config', 0777, true);
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => $this->root]));
        foreach (['update-conf', 'passwd', 'rewrite', 'transport'] as $name) {
            copy(
                dirname(base_path()) . "/templates/config/exim-{$name}.blade.php",
                $this->root . "/templates/config/exim-{$name}.blade.php"
            );
        }
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        foreach (glob($this->root . '/{config/exim,templates/config}/*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        foreach (['/config/exim', '/config', '/templates/config', '/templates', ''] as $dir) {
            rmdir($this->root . $dir);
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: array<string, string>, 1: string, 2: string, 3: string, 4: string}> */
    public static function providers(): array
    {
        return [
            'mailchannels' => [
                ['smarthost_provider' => 'mailchannels', 'mailchannels_username' => 'mc-user', 'mailchannels_password' => 'mc-pass', 'sender_domain' => 'mail.example.org'],
                'smarthost',
                'smtp.mailchannels.net::25',
                "*:mc-user:mc-pass\n",
                '',
            ],
            'sendgrid' => [
                ['smarthost_provider' => 'sendgrid', 'sendgrid_api_token' => 'sg-token', 'sender_domain' => 'example.com'],
                'smarthost',
                'smtp.sendgrid.net::587',
                "*:apikey:sg-token\n",
                '',
            ],
            'amazon_ses' => [
                ['smarthost_provider' => 'amazon_ses', 'amazon_ses_smtp_endpoint' => 'email-smtp.example.com', 'amazon_ses_starttls_port' => '2587', 'amazon_ses_smtp_username' => 'ses-user', 'amazon_ses_smtp_password' => 'ses-pass'],
                'smarthost',
                'email-smtp.example.com::2587',
                "*:ses-user:ses-pass\n",
                '',
            ],
            'smtp' => [
                ['smarthost_provider' => 'smtp', 'smtp_host' => 'relay.example.net', 'smtp_port' => '465', 'smtp_username' => 'relay', 'smtp_password' => 'pw', 'smtp_implicit_tls' => '1'],
                'smarthost',
                'relay.example.net::465',
                "*:relay:pw\n",
                '1',
            ],
            'unknown provider' => [
                ['smarthost_provider' => 'postmark', 'smtp_username' => 'ignored', 'sender_domain' => 'example.com'],
                'internet',
                '',
                "*::\n",
                '',
            ],
        ];
    }

    /** @param  array<string, string>  $config */
    #[DataProvider('providers')]
    public function test_each_provider_writes_these_files_in_this_order(
        array $config,
        string $type,
        string $smarthost,
        string $passwd,
        string $implicitTls,
    ): void {
        $system = $this->system();
        $config = $this->config($config);

        $this->exim($system, $config)->rebuildEximConfig();

        $exim = $this->root . '/config/exim';
        $templates = $this->root . '/templates/config';
        $this->assertSame([
            "read {$templates}/exim-update-conf.blade.php",
            "write {$exim}/update-exim4.conf.conf",
            "read {$templates}/exim-passwd.blade.php",
            "write {$exim}/passwd.client",
            "read {$templates}/exim-rewrite.blade.php",
            "write {$exim}/rewrite.conf",
            "read {$templates}/exim-transport.blade.php",
            "write {$exim}/transport.conf",
            'exec compose.eximUpdateConfig',
            'exec compose.eximRestart',
        ], $system->journal);

        $this->assertSame(
            "dc_readhost='{$config['sender_domain']}'\n"
            . "dc_eximconfig_configtype='{$type}'\n"
            . "dc_smarthost='{$smarthost}'\n"
            . "dc_other_hostnames=''\n"
            . "dc_relay_domains=''\n"
            . "dc_minimaldns='false'\n"
            . "CFILEMODE='644'\n"
            . "dc_use_split_config='true'\n"
            . "dc_hide_mailname='true'\n"
            . "dc_mailname_in_oh='true'\n"
            . "dc_local_interfaces='" . TestNetwork::LOOPBACK . ' ; ' . Exim::FALLBACK_BRIDGE_GATEWAY . "'\n"
            . "dc_relay_nets='" . TestNetwork::LOOPBACK_CIDR . ' ; ' . TestNetwork::PRIVATE_172_CIDR . "'",
            file_get_contents("{$exim}/update-exim4.conf.conf")
        );
        $this->assertSame($passwd, file_get_contents("{$exim}/passwd.client"));
        $this->assertSame(
            $config['sender_domain'] !== ''
                ? '*  ${local_part}_at_${domain}@' . $config['sender_domain'] . " Ffrs\n"
                : "* \${address:\$header_from:} F\n",
            file_get_contents("{$exim}/rewrite.conf")
        );
        $this->assertSame(
            $implicitTls !== '' ? "REMOTE_SMTP_SMARTHOST_PROTOCOL = smtps\n" : '',
            file_get_contents("{$exim}/transport.conf")
        );
    }

    public function test_a_missing_template_stops_before_anything_is_written(): void
    {
        unlink($this->root . '/templates/config/exim-passwd.blade.php');
        $system = $this->system();

        try {
            $this->exim($system, $this->config(['smarthost_provider' => 'sendgrid']))->rebuildEximConfig();
            $this->fail('a missing template should fail the rebuild');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cannot read ' . $this->root . '/templates/config/exim-passwd.blade.php', $e->getMessage());
        }

        $this->assertSame([
            'read ' . $this->root . '/templates/config/exim-update-conf.blade.php',
            'write ' . $this->root . '/config/exim/update-exim4.conf.conf',
            'read ' . $this->root . '/templates/config/exim-passwd.blade.php',
        ], $system->journal);
    }

    /**
     * @param  array<string, string>  $config
     * @return array<string, string>
     */
    private function config(array $config): array
    {
        return array_merge([
            'smarthost_provider' => '',
            'sendgrid_api_token' => '',
            'mailchannels_username' => '',
            'mailchannels_password' => '',
            'amazon_ses_smtp_endpoint' => '',
            'amazon_ses_starttls_port' => '',
            'amazon_ses_smtp_username' => '',
            'amazon_ses_smtp_password' => '',
            'smtp_host' => '',
            'smtp_port' => '',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_implicit_tls' => '',
            'sender_domain' => '',
        ], $config);
    }

    /** @param array<string, string> $config */
    private function exim(System $system, array $config): Exim
    {
        return new class ($system, $config) extends Exim {
            /** @param array<string, string> $config */
            public function __construct(System $system, private array $config)
            {
                parent::__construct($system);
            }

            protected function networkAddresses(string $network): array
            {
                return [];
            }

            protected function eximConfig(): array
            {
                return $this->config;
            }
        };
    }

    /** @return System&object{journal: list<string>} */
    private function system(): System
    {
        return new class ($this->root) extends System {
            /** @var list<string> */
            public array $journal = [];

            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function composeFilePath(): string
            {
                return $this->root . '/docker-compose.yml';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;
                $this->journal[] = 'exec '.(LocalRoot::action($argv) ?? implode(' ', $argv));

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \LogicException('nothing here runs a process: ' . (is_array($cmd) ? implode(' ', $cmd) : $cmd));
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private System $recording)
                    {
                        parent::__construct($recording);
                    }

                    public function fileGetContents(string $path): string
                    {
                        $this->recording->journal[] = "read {$path}";
                        $contents = @file_get_contents($path);
                        if ($contents === false) {
                            throw new \RuntimeException("Cannot read {$path}");
                        }

                        return $contents;
                    }

                    public function directoryExists(string $path): bool
                    {
                        return is_dir($path);
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->recording->journal[] = "write {$path}";
                        file_put_contents($path, $contents);
                    }
                };
            }
        };
    }
}
