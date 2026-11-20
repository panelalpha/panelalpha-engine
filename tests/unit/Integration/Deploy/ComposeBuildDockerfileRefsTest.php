<?php

namespace Tests\Integration\Deploy;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins where a compose `build:` points on disk, and which of those Dockerfiles a checkout lacks.
 */
class ComposeBuildDockerfileRefsTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/compose-build-refs-'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    /** @return array<string, array{0: array<string, mixed>|string, 1: ?string}> */
    public static function builds(): array
    {
        return [
            'dot' => ['.', '/proj/Dockerfile'],
            'dot-slash dir' => ['./app', '/proj/app/Dockerfile'],
            'trailing slash' => ['app/', '/proj/app/Dockerfile'],
            'empty string' => ['', null],
            'https context' => ['https://github.com/x/y.git', null],
            'git@ context' => ['git@github.com:x/y', null],
            'ssh context' => ['ssh://host/repo', null],
            'parent context' => ['../up', null],
            'backslashes' => ['a\\b', '/proj/a/b/Dockerfile'],
            'named dockerfile' => [['context' => '.', 'dockerfile' => 'Dockerfile.prod'], '/proj/Dockerfile.prod'],
            'context only' => [['context' => './console'], '/proj/console/Dockerfile'],
            'absolute dockerfile' => [['dockerfile' => '/abs/Dockerfile'], '/abs/Dockerfile'],
            'inline dockerfile' => [['dockerfile_inline' => 'FROM x'], null],
            'non-string context' => [['context' => 5], '/proj/Dockerfile'],
            'empty fields' => [['context' => '', 'dockerfile' => ''], '/proj/Dockerfile'],
            'backslash fields' => [['context' => 'a\\b', 'dockerfile' => 'docker\\Dockerfile'], '/proj/a/b/docker/Dockerfile'],
            'dot-slash dockerfile' => [['context' => './', 'dockerfile' => './docker/Dockerfile'], '/proj/docker/Dockerfile'],
            'upper-case scheme' => [['context' => 'HTTP://example.com/r.git'], null],
            'parent dockerfile' => [['dockerfile' => '../Dockerfile'], null],
            'padded fields' => [['context' => ' . ', 'dockerfile' => ' Dockerfile '], '/proj/Dockerfile'],
            'dots inside a name' => [['context' => 'app', 'dockerfile' => 'a..b'], null],
            'absolute under a context' => [['context' => './app/', 'dockerfile' => '/Dockerfile'], '/Dockerfile'],
            'parent only' => [['context' => '..', 'dockerfile' => 'Dockerfile'], null],
            'dot-slash only' => [['context' => './', 'dockerfile' => './'], null],
            'non-string dockerfile' => [['context' => '.', 'dockerfile' => 7], '/proj/Dockerfile'],
            'empty map' => [[], '/proj/Dockerfile'],
        ];
    }

    /** @param array<string, mixed>|string $build */
    #[DataProvider('builds')]
    public function test_the_dockerfile_a_build_resolves_to(array|string $build, ?string $expected): void
    {
        $this->assertSame($expected, ComposeFileInspector::composeBuildDockerfileAbsolute('/proj/', $build));
        $this->assertSame($expected, ComposeFileInspector::composeBuildDockerfileAbsolute('/proj', $build));
    }

    public function test_missing_dockerfiles_of_a_root_compose(): void
    {
        mkdir($this->tmpDir.'/api', 0777, true);
        file_put_contents($this->tmpDir.'/api/Dockerfile', "FROM x\n");
        file_put_contents($this->tmpDir.'/docker-compose.yml', <<<'YAML'
            services:
              api:
                build: ./api
              web:
                build:
                  context: ./web
                  dockerfile: Dockerfile.web
              again:
                build:
                  context: web
                  dockerfile: ./Dockerfile.web
              worker:
                build:
                  context: .
                  dockerfile: /opt/none/Dockerfile
              remote:
                build: https://github.com/x/y.git
              inline:
                build:
                  dockerfile_inline: FROM x
              image:
                image: nginx
              odd: notamap
              number:
                build: 5
            YAML);

        $this->assertSame(
            ['web/Dockerfile.web', '/opt/none/Dockerfile'],
            ComposeFileInspector::missingComposeDockerfileRefs($this->tmpDir.'/docker-compose.yml', $this->tmpDir)
        );
    }

    public function test_missing_dockerfiles_of_a_nested_compose(): void
    {
        mkdir($this->tmpDir.'/docker', 0777, true);
        file_put_contents($this->tmpDir.'/Dockerfile', "FROM x\n");
        file_put_contents($this->tmpDir.'/docker/compose.yml', <<<'YAML'
            services:
              app:
                build:
                  context: ..
              tool:
                build: ./tool
              side:
                build:
                  context: .
                  dockerfile: Dockerfile.side
            YAML);

        $this->assertSame(
            ['docker/tool/Dockerfile', 'docker/Dockerfile.side'],
            ComposeFileInspector::missingComposeDockerfileRefs($this->tmpDir.'/docker/compose.yml', $this->tmpDir.'/')
        );
    }

    /** @return array<string, array{0: ?string}> */
    public static function unreadableFiles(): array
    {
        return [
            'missing file' => [null],
            'empty file' => [''],
            'invalid yaml' => ["services: [\n"],
            'no services' => ["volumes:\n  data: {}\n"],
            'services not a map' => ["services: nope\n"],
        ];
    }

    #[DataProvider('unreadableFiles')]
    public function test_a_file_with_no_services_has_no_refs(?string $contents): void
    {
        $path = $this->tmpDir.'/compose.yml';
        if ($contents !== null) {
            file_put_contents($path, $contents);
        }

        $this->assertSame([], ComposeFileInspector::missingComposeDockerfileRefs($path, $this->tmpDir));
    }
}
