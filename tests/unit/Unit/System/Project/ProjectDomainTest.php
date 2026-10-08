<?php

namespace Tests\Unit\System\Project;

use App\Integrations\Statistics\Statistics;
use App\Models\Domain as DomainModel;
use App\Models\Setting;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Project\Domain as DomainCollaborator;
use App\System\Services\Webserver;
use App\System\Services\Webserver\WebserverInterface;
use Tests\TestCase;
use Tests\Unit\Integrations\Statistics\FakeStatistics;

class ProjectDomainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // create() configures statistics for the new domain; keep that off this machine's awstats directory.
        $this->app->instance(Statistics::class, new FakeStatistics());
    }

    public function test_project_domain_returns_collaborator(): void
    {
        $user = new ModelsUser();
        $user->username = 'alice';
        $domainModel = $this->createStub(DomainModel::class);

        $system = new class extends System {
            public function engineDirPath(): string
            {
                return sys_get_temp_dir();
            }
        };

        $project = new Project($system, $user);
        $collaborator = $project->domain($domainModel);

        $this->assertInstanceOf(DomainCollaborator::class, $collaborator);
        $this->assertSame($domainModel, $collaborator->model());
        $this->assertSame($project, $collaborator->project());
    }

    public function test_dind_create_wires_host_webserver_and_skips_in_container_config(): void
    {
        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $user->method('getUid')->willReturn(1000);
        $user->method('getGid')->willReturn(1000);
        $user->method('hasGitProject')->willReturn(false);
        $user->method('getTemplate')->willReturn('dind');

        $domainModel = $this->createStub(DomainModel::class);
        $domainModel->method('sslEnabled')->willReturn(false);
        $domainModel->method('getDocumentRoot')->willReturn('/public_html');
        $domainModel->domain = 'app.example.test';

        $driver = $this->createMock(WebserverInterface::class);
        $driver->expects($this->once())->method('addDomain')->with($domainModel);
        $driver->expects($this->once())->method('reload')->with(false);

        $tmpRoot = sys_get_temp_dir() . '/pa-domain-dind-' . bin2hex(random_bytes(4));
        $home = $tmpRoot . '/home/alice';
        mkdir($home . '/public_html', 0777, true);

        $system = new class ($tmpRoot, $home, $driver) extends System {
            public function __construct(
                private string $engineRoot,
                private string $aliceHome,
                private WebserverInterface $driver,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return dirname($this->aliceHome);
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->aliceHome;
            }

            public function webserver(): Webserver
            {
                $driver = $this->driver;

                return new class ($driver) extends Webserver {
                    public function __construct(private WebserverInterface $driver)
                    {
                    }

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this->driver;
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private object $outer)
                    {
                    }

                    public function isDir(string $target): bool
                    {
                        return is_dir($target);
                    }

                    public function makeDirFromTemplate(
                        string $dir,
                        string $templateDir,
                        array $templateVars,
                        ?string $chown = null,
                        ?string $chmod = null,
                        array $exclude = [],
                    ): void {
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                    }
                };
            }
        };

        try {
            $project = new Project($system, $user);
            $project->domain($domainModel)->create();
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_php_fpm_create_wires_fpm_apache_in_container_config(): void
    {
        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'bob' : null
        );
        $user->method('getUid')->willReturn(1000);
        $user->method('getGid')->willReturn(1000);
        $user->method('hasGitProject')->willReturn(false);
        $user->method('getTemplate')->willReturn('default');

        $domainModel = new class extends DomainModel {
            public function sslEnabled(): bool
            {
                return false;
            }

            public function getDocumentRoot(): string
            {
                return '/public_html';
            }

            public function getVhostAltNames(): array
            {
                return [];
            }

            public function getPhpVersion(): ?string
            {
                return '8.3';
            }
        };
        $domainModel->domain = 'php.example.test';

        $driver = $this->createMock(WebserverInterface::class);
        $driver->expects($this->once())->method('addDomain')->with($domainModel);
        $driver->method('reload');

        $tmpRoot = sys_get_temp_dir() . '/pa-domain-fpm-' . bin2hex(random_bytes(4));
        $projectDir = $tmpRoot . '/users/bob';
        mkdir($projectDir . '/apache-sites', 0777, true);
        mkdir($tmpRoot . '/home/bob/public_html', 0777, true);
        file_put_contents($projectDir . '/docker-compose.yml', "services:\n  php:\n    image: test\n");

        $system = new class ($tmpRoot, $driver) extends System {
            public int $templateWrites = 0;

            public function __construct(
                private string $engineRoot,
                private WebserverInterface $driver,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->engineRoot . '/home';
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->homesDirPath() . '/' . $username;
            }

            public function templatesDirPath(): string
            {
                return $this->engineRoot . '/templates';
            }

            public function webserver(): Webserver
            {
                $driver = $this->driver;

                return new class ($driver) extends Webserver {
                    public function __construct(private WebserverInterface $driver)
                    {
                    }

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this->driver;
                    }

                    public function getCurrentWebserver(): string
                    {
                        return 'nginx-proxy';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function filesystem(): \App\System\Filesystem
            {
                $outer = $this;

                return new class ($outer) extends \App\System\Filesystem {
                    public function __construct(private object $outer)
                    {
                    }

                    public function isDir(string $target): bool
                    {
                        return is_dir($target);
                    }

                    public function makeFileFromTemplate(
                        string $file,
                        string $templateFile,
                        array $templateVars,
                        ?string $chown = null,
                        ?string $chmod = null,
                    ): void {
                        $this->outer->templateWrites++;
                        file_put_contents($file, 'vhost');
                    }

                    public function makeDirFromTemplate(
                        string $dir,
                        string $templateDir,
                        array $templateVars,
                        ?string $chown = null,
                        ?string $chmod = null,
                        array $exclude = [],
                    ): void {
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                    }
                };
            }
        };

        try {
            $project = new Project($system, $user);
            $project->domain($domainModel)->create();

            $this->assertSame(1, $system->templateWrites);
            $this->assertFileExists($projectDir . '/apache-sites/php.example.test.conf');
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_php_hosting_domain_root_is_home_domain_public_html_owned_by_project_user(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-wp-root-' . bin2hex(random_bytes(4));
        $home = $tmpRoot . '/home/alice';
        mkdir($home, 0777, true);
        $system = $this->phpHostingDomainRootSystem($tmpRoot);

        try {
            $project = new Project($system, $this->phpHostingAlice());
            $project->domain($this->phpHostingAliceDomain())->createDomainRootDir();

            $domainDir = $home . '/alice.example.test';
            $publicHtml = $domainDir . '/public_html';

            $this->assertDirectoryExists($publicHtml);
            $this->assertContains('sudo mkdir -p ' . $domainDir, $system->journal);
            $this->assertContains('sudo chown 1001:1001 ' . $domainDir, $system->journal);
            $this->assertContains('isDir:' . $publicHtml, $system->journal);
            $this->assertContains('template:' . $publicHtml . ':1001:1001', $system->journal);
            $this->assertContains('sudo mkdir -p ' . $publicHtml, $system->journal);
            $this->assertContains('sudo chown 1001:1001 ' . $publicHtml, $system->journal);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_php_hosting_domain_root_chowns_existing_domain_dir_and_creates_missing_public_html(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-wp-chown-' . bin2hex(random_bytes(4));
        $home = $tmpRoot . '/home/alice';
        $domainDir = $home . '/alice.example.test';
        mkdir($domainDir, 0777, true);
        $system = $this->phpHostingDomainRootSystem($tmpRoot);

        try {
            $project = new Project($system, $this->phpHostingAlice());
            $project->domain($this->phpHostingAliceDomain())->createDomainRootDir();

            $publicHtml = $domainDir . '/public_html';
            $this->assertDirectoryExists($publicHtml);
            $this->assertContains('sudo mkdir -p ' . $domainDir, $system->journal);
            $this->assertContains('sudo chown 1001:1001 ' . $domainDir, $system->journal);
            $this->assertContains('template:' . $publicHtml . ':1001:1001', $system->journal);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_php_hosting_domain_root_still_owns_domain_dir_when_public_html_already_exists(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-wp-exists-' . bin2hex(random_bytes(4));
        $home = $tmpRoot . '/home/alice';
        $domainDir = $home . '/alice.example.test';
        $publicHtml = $domainDir . '/public_html';
        mkdir($publicHtml, 0777, true);
        $system = $this->phpHostingDomainRootSystem($tmpRoot);

        try {
            $project = new Project($system, $this->phpHostingAlice());
            $project->domain($this->phpHostingAliceDomain())->createDomainRootDir();

            $this->assertContains('sudo mkdir -p ' . $domainDir, $system->journal);
            $this->assertContains('sudo chown 1001:1001 ' . $domainDir, $system->journal);
            $this->assertContains('sudo mkdir -p ' . $publicHtml, $system->journal);
            $this->assertContains('sudo chown 1001:1001 ' . $publicHtml, $system->journal);
            $templateLines = array_values(array_filter(
                $system->journal,
                static fn (string $line): bool => str_starts_with($line, 'template:')
            ));
            $this->assertSame([], $templateLines);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_put_certificate_stores_leaf_key_chain_and_pem_under_project_ssl_certs(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-ssl-' . bin2hex(random_bytes(4));
        mkdir($tmpRoot . '/users/alice', 0777, true);

        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => 'app.example.test']);

        $system = $this->systemWithDirectHostOps($tmpRoot);
        $project = new Project($system, $user);
        $domain = $project->domain($domainModel);

        try {
            $domain->putCertificate("-----BEGIN CERT LEAF-----", "-----BEGIN KEY-----", "-----BEGIN CERT CA-----");

            $certDir = $tmpRoot . '/users/alice/ssl-certs';
            $this->assertSame("-----BEGIN CERT LEAF-----\n", file_get_contents($certDir . '/app.example.test.crt'));
            $this->assertSame("-----BEGIN KEY-----\n", file_get_contents($certDir . '/app.example.test.key'));
            $this->assertSame("-----BEGIN CERT CA-----\n", file_get_contents($certDir . '/app.example.test.ca'));
            $this->assertSame(
                "-----BEGIN CERT LEAF-----\n-----BEGIN CERT CA-----\n",
                file_get_contents($certDir . '/app.example.test.pem')
            );
            $this->assertTrue($domain->hasSslCertificate());
            $this->assertTrue($domain->hasServableCertificate());
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_generate_self_signed_certificate_writes_key_and_copied_chain_files(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-selfsign-' . bin2hex(random_bytes(4));
        mkdir($tmpRoot . '/users/alice', 0777, true);

        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => 'self.example.test']);

        $system = $this->systemWithDirectHostOps($tmpRoot);
        $domain = (new Project($system, $user))->domain($domainModel);

        try {
            $domain->generateSelfSignedCertificate();

            $certDir = $tmpRoot . '/users/alice/ssl-certs';
            $this->assertSame("-----BEGIN CERT-----\n", file_get_contents($certDir . '/self.example.test.crt'));
            $this->assertSame("-----BEGIN KEY-----\n", file_get_contents($certDir . '/self.example.test.key'));
            $this->assertSame("-----BEGIN CERT-----\n", file_get_contents($certDir . '/self.example.test.ca'));
            $this->assertSame("-----BEGIN CERT-----\n", file_get_contents($certDir . '/self.example.test.pem'));
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_get_ssl_certificate_info_reads_installed_pem_without_lib_domain(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-info-' . bin2hex(random_bytes(4));
        $certDir = $tmpRoot . '/users/alice/ssl-certs';
        mkdir($certDir, 0777, true);

        $pem = $this->selfSignedPem('info.example.test');
        file_put_contents($certDir . '/info.example.test.crt', $pem);
        file_put_contents($certDir . '/info.example.test.ca', $pem);

        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => 'info.example.test']);
        $domain = (new Project($this->systemWithDirectHostOps($tmpRoot), $user))->domain($domainModel);

        try {
            $info = $domain->getSslCertificateInfo();

            $this->assertSame($pem, $info['certificate']);
            $this->assertSame($pem, $info['cabundle']);
            $this->assertSame('info.example.test', $info['common_name']);
            $this->assertTrue($info['covers_domain']);
            $this->assertTrue($info['self_signed']);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_list_log_files_returns_access_and_error_logs_for_host_webserver(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-logs-' . bin2hex(random_bytes(4));
        $logsDir = $tmpRoot . '/webserver-logs/nginx-proxy/logs.example.test';
        mkdir($logsDir, 0777, true);
        file_put_contents($logsDir . '/access.log', 'ok');
        file_put_contents($logsDir . '/error.log', 'err');
        file_put_contents($logsDir . '/other.log', 'skip');

        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => 'logs.example.test']);
        $domain = (new Project($this->systemWithDirectHostOps($tmpRoot), $user))->domain($domainModel);

        try {
            $files = $domain->listLogFiles();
            $names = array_column($files, 'file');
            $this->assertContains('access.log', $names);
            $this->assertContains('error.log', $names);
            $this->assertNotContains('other.log', $names);
            $this->assertSame($logsDir . '/access.log', $files[array_search('access.log', $names, true)]['path']);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_list_webserver_log_files_includes_nginx_alias_and_apache_container_logs(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-wslogs-' . bin2hex(random_bytes(4));
        $hostLogs = $tmpRoot . '/webserver-logs/nginx-proxy/ws.example.test';
        $apacheLogs = $tmpRoot . '/users/alice/log/apache2';
        mkdir($hostLogs, 0777, true);
        mkdir($apacheLogs, 0777, true);
        file_put_contents($hostLogs . '/access.log', 'host');
        file_put_contents($apacheLogs . '/error.log', 'apache');

        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => 'ws.example.test']);
        $domain = (new Project($this->systemWithDirectHostOps($tmpRoot), $user))->domain($domainModel);

        try {
            $files = $domain->listWebserverLogFiles();
            $names = array_column($files, 'file');
            $this->assertContains('access.log', $names);
            $this->assertContains('nginx_access.log', $names);
            $this->assertContains('apache_error.log', $names);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_generate_certificate_copies_engine_cert_for_default_ip_domain(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-default-ip-' . bin2hex(random_bytes(4));
        mkdir($tmpRoot . '/users/alice', 0777, true);
        mkdir($tmpRoot . '/crt', 0777, true);
        file_put_contents($tmpRoot . '/crt/server.cert', "-----BEGIN SERVER CERT-----\n");
        file_put_contents($tmpRoot . '/crt/server.key', "-----BEGIN SERVER KEY-----\n");

        Setting::setRuntimeSettings(['vhost-default-ip-domain' => 'ip.example.test']);

        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => 'ip.example.test']);
        $domain = (new Project($this->systemWithDirectHostOps($tmpRoot), $user))->domain($domainModel);

        try {
            $domain->generateCertificate();

            $certDir = $tmpRoot . '/users/alice/ssl-certs';
            $this->assertSame("-----BEGIN SERVER CERT-----\n", file_get_contents($certDir . '/ip.example.test.crt'));
            $this->assertSame("-----BEGIN SERVER KEY-----\n", file_get_contents($certDir . '/ip.example.test.key'));
            $this->assertSame("-----BEGIN SERVER CERT-----\n", file_get_contents($certDir . '/ip.example.test.ca'));
            $this->assertSame("-----BEGIN SERVER CERT-----\n", file_get_contents($certDir . '/ip.example.test.pem'));
        } finally {
            Setting::clearRuntimeSettings();
            $this->removeTree($tmpRoot);
        }
    }

    public function test_delete_apache_config_removes_in_container_vhost_file(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-apache-del-' . bin2hex(random_bytes(4));
        $conf = $tmpRoot . '/users/alice/apache-sites/del.example.test.conf';
        mkdir(dirname($conf), 0777, true);
        file_put_contents($conf, 'vhost');

        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => 'del.example.test']);
        $domain = (new Project($this->systemWithDirectHostOps($tmpRoot), $user))->domain($domainModel);

        try {
            $domain->deleteApacheConfig();
            $this->assertFileDoesNotExist($conf);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_delete_nginx_config_removes_nginx_proxy_vhost_when_present(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-nginx-del-' . bin2hex(random_bytes(4));
        $conf = $tmpRoot . '/webserver-config/nginx-proxy/vhosts/ngx.example.test.conf';
        mkdir(dirname($conf), 0777, true);
        file_put_contents($conf, 'vhost');

        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => 'ngx.example.test']);
        $domain = (new Project($this->systemWithDirectHostOps($tmpRoot), $user))->domain($domainModel);

        try {
            $domain->deleteNginxConfig();
            $this->assertFileDoesNotExist($conf);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_create_from_template_writes_apache_sites_vhost(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/pa-domain-from-tpl-' . bin2hex(random_bytes(4));
        mkdir($tmpRoot . '/users/alice/apache-sites', 0777, true);
        mkdir($tmpRoot . '/home/alice', 0777, true);

        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $user->method('getUid')->willReturn(1000);
        $user->method('getGid')->willReturn(1000);
        $user->method('getTemplate')->willReturn('default');

        $domainModel = new DomainModel();
        $domainModel->forceFill(['domain' => 'tpl.example.test']);

        $driver = $this->createStub(WebserverInterface::class);
        $system = new class ($tmpRoot, $driver) extends System {
            public int $templateWrites = 0;

            public function __construct(
                private string $engineRoot,
                private WebserverInterface $driver,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->engineRoot . '/home';
            }

            public function webserver(): Webserver
            {
                $driver = $this->driver;

                return new class ($driver) extends Webserver {
                    public function __construct(private WebserverInterface $driver)
                    {
                    }

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this->driver;
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function filesystem(): Filesystem
            {
                $outer = $this;

                return new class ($outer) extends Filesystem {
                    public function __construct(private object $outer)
                    {
                    }

                    public function isDir(string $target): bool
                    {
                        return is_dir($target);
                    }

                    public function makeFileFromTemplate(
                        string $file,
                        string $templateFile,
                        array $templateVars,
                        ?string $chown = null,
                        ?string $chmod = null,
                    ): void {
                        $this->outer->templateWrites++;
                        file_put_contents($file, 'vhost');
                    }

                    public function makeDirFromTemplate(
                        string $dir,
                        string $templateDir,
                        array $templateVars,
                        ?string $chown = null,
                        ?string $chmod = null,
                        array $exclude = [],
                    ): void {
                        mkdir($dir, 0777, true);
                    }
                };
            }
        };

        try {
            (new Project($system, $user))->domain($domainModel)->createFromTemplate();

            $this->assertSame(1, $system->templateWrites);
            $this->assertFileExists($tmpRoot . '/users/alice/apache-sites/tpl.example.test.conf');
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    private function phpHostingAlice(): ModelsUser
    {
        $user = new ModelsUser();
        $user->username = 'alice';
        $user->setDetails([
            'template' => 'default',
            'UID' => 1001,
            'GID' => 1001,
        ]);

        return $user;
    }

    private function phpHostingAliceDomain(): DomainModel
    {
        $domainModel = new DomainModel();
        $domainModel->domain = 'alice.example.test';

        return $domainModel;
    }

    /**
     * @return System&object{journal: list<string>}
     */
    private function phpHostingDomainRootSystem(string $engineRoot): System
    {
        return new class ($engineRoot) extends System {
            /** @var list<string> */
            public array $journal = [];

            public function __construct(private string $engineRoot)
            {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->engineRoot . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->journal[] = $line;
                if (preg_match('/mkdir -p (.+)$/', $line, $matches) === 1) {
                    $path = trim($matches[1]);
                    if (!is_dir($path)) {
                        mkdir($path, 0777, true);
                    }
                }

                return '';
            }

            public function filesystem(): Filesystem
            {
                $outer = $this;

                return new class ($outer) extends Filesystem {
                    public function __construct(private object $outer)
                    {
                    }

                    public function isDir(string $target): bool
                    {
                        $this->outer->journal[] = 'isDir:' . $target;
                        return is_dir($target);
                    }

                    public function makeDirFromTemplate(
                        string $dir,
                        string $templateDir,
                        array $templateVars,
                        ?string $chown = null,
                        ?string $chmod = null,
                        array $exclude = [],
                    ): void {
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                        $this->outer->journal[] = 'template:' . $dir . ':' . (string) $chown;
                    }
                };
            }
        };
    }

    /**
     * Host exec/runProcess that apply mkdir/cp/chmod/test against a temp tree (no sudo, no Lib User).
     */
    private function systemWithDirectHostOps(string $engineRoot): System
    {
        return new class ($engineRoot) extends System {
            public function __construct(private string $engineRoot)
            {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function webserver(): Webserver
            {
                return new class ($this) extends Webserver {
                    public function getCurrentWebserver(): string
                    {
                        return 'nginx-proxy';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->applyHostCommand($cmd);

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                $argv = is_array($cmd) ? $cmd : (preg_split('/\s+/', $cmd) ?: []);
                $exit = 1;
                $output = '';

                if (($argv[0] ?? '') === 'test' && ($argv[1] ?? '') === '-e') {
                    $exit = file_exists((string) ($argv[2] ?? '')) ? 0 : 1;
                } elseif (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'find') {
                    $dir = (string) ($argv[2] ?? '');
                    $name = (string) ($argv[6] ?? '');
                    $found = [];
                    if (is_dir($dir)) {
                        foreach (scandir($dir) ?: [] as $file) {
                            if (strcasecmp($file, $name) === 0) {
                                $found[] = $dir . '/' . $file;
                            }
                        }
                    }
                    $output = implode("\n", $found);
                    $exit = 0;
                } else {
                    try {
                        $this->applyHostCommand($cmd);
                        $exit = 0;
                    } catch (\Throwable) {
                        $exit = 1;
                    }
                }

                $process = new class ($output, $exit) extends \Symfony\Component\Process\Process {
                    public function __construct(
                        private string $stubOutput,
                        private int $stubExit,
                    ) {
                        parent::__construct(['true']);
                    }

                    public function getOutput(): string
                    {
                        return $this->stubOutput;
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->stubExit;
                    }
                };

                return $process;
            }

            private function applyHostCommand(string|array $cmd): void
            {
                $argv = is_array($cmd) ? $cmd : (preg_split('/\s+/', trim($cmd)) ?: []);
                if (($argv[0] ?? '') === 'sudo') {
                    array_shift($argv);
                }
                $op = $argv[0] ?? '';
                if ($op === 'mkdir' && ($argv[1] ?? '') === '-p') {
                    if (!is_dir((string) $argv[2]) && !mkdir((string) $argv[2], 0777, true) && !is_dir((string) $argv[2])) {
                        throw new \RuntimeException('mkdir failed: ' . $argv[2]);
                    }

                    return;
                }
                if ($op === 'cp') {
                    $dest = (string) $argv[count($argv) - 1];
                    $dir = dirname($dest);
                    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                        throw new \RuntimeException('mkdir failed: ' . $dir);
                    }
                    if (!copy((string) $argv[1], $dest)) {
                        throw new \RuntimeException('copy failed: ' . $argv[1] . ' -> ' . $dest);
                    }

                    return;
                }
                if ($op === 'chmod' || $op === 'chown') {
                    return;
                }
                if ($op === 'mv') {
                    rename((string) $argv[1], (string) $argv[2]);

                    return;
                }
                if ($op === 'rm') {
                    $path = (string) $argv[count($argv) - 1];
                    if (is_file($path)) {
                        unlink($path);
                    }

                    return;
                }
                if ($op === 'openssl') {
                    $keyOut = $argv[array_search('-keyout', $argv, true) + 1];
                    $certOut = $argv[array_search('-out', $argv, true) + 1];
                    file_put_contents($keyOut, "-----BEGIN KEY-----\n");
                    file_put_contents($certOut, "-----BEGIN CERT-----\n");
                }
            }
        };
    }

    private function selfSignedPem(string $commonName): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $csr = openssl_csr_new(['countryName' => 'US', 'commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($x509);
        $pem = '';
        openssl_x509_export($x509, $pem);

        return $pem;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
