<?php

namespace Tests\Unit\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Metadata\CargoMetadata;
use App\Lib\Deploy\Platform\Metadata\ComposerMetadata;
use App\Lib\Deploy\Platform\Metadata\GoMetadata;
use App\Lib\Deploy\Platform\Metadata\MavenMetadata;
use App\Lib\Deploy\Platform\Metadata\NpmMetadata;
use App\Lib\Deploy\Platform\Metadata\PackageMetadata;
use App\Lib\Deploy\Platform\Metadata\PythonMetadata;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Every field each reader reports for a project that states nearly everything, pinned as a whole record.
 */
class ReaderSnapshotTest extends MetadataTestCase
{
    protected function composerProject(): PackageMetadata
    {
        $this->writeJson('composer.json', [
            'name' => 'acme/shop',
            'description' => 'Shop',
            'type' => 'project',
            'license' => ['MIT', 'GPL-2.0'],
            'homepage' => 'https://acme.test',
            'support' => ['source' => 'https://github.com/acme/shop'],
            'authors' => [['name' => 'Jane', 'email' => 'jane@acme.test'], ['name' => 'Bob']],
            'keywords' => ['shop', 'store'],
            'scripts' => ['test' => 'phpunit', 'post-install-cmd' => ['@php artisan optimize']],
            'bin' => ['bin/shop'],
            'require' => [
                'php' => '^8.2',
                'ext-intl' => '*',
                'PHP-64bit' => '*',
                'composer-runtime-api' => '^2.2',
                'php-di/php-di' => '^7.0',
                'laravel/framework' => '^11.0',
                'guzzlehttp/guzzle' => '^7.0',
            ],
            'require-dev' => ['ext-xdebug' => '*', 'phpunit/phpunit' => '^10', 'symfony/symfony' => '^7.0'],
        ]);
        $this->writeJson('composer.lock', [
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v11.31.0'],
                ['name' => 'guzzlehttp/guzzle', 'version' => '7.9.2'],
                'not-a-package',
                ['name' => 'no/version'],
            ],
            'packages-dev' => [['name' => 'symfony/symfony', 'version' => 'V7.1.0']],
        ]);

        return new ComposerMetadata();
    }

    protected function npmProject(): PackageMetadata
    {
        $this->writeJson('package.json', [
            'name' => 'shop',
            'version' => '1.2.3',
            'description' => 'Shop front',
            'license' => 'MIT',
            'homepage' => 'https://acme.test',
            'repository' => ['type' => 'git', 'url' => 'git+https://github.com/acme/shop.git'],
            'author' => ['name' => 'Jane', 'email' => 'jane@acme.test'],
            'contributors' => ['Bob', 'Jane'],
            'keywords' => ['shop'],
            'private' => true,
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
            'main' => 'server.js',
            'workspaces' => ['packages' => ['apps/*']],
            'dependencies' => ['next' => '^14.0.0', 'react' => '^18.0.0', 'lodash' => '^4'],
            'devDependencies' => ['vite' => '^6.0.0'],
            'engines' => ['Node' => '>=20', 'pnpm' => '9', 'bad' => ['x']],
        ]);
        $this->writeJson('package-lock.json', [
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['version' => '1.2.3'],
                'node_modules/next' => ['version' => '14.2.1'],
                'node_modules/vitest/node_modules/vite' => ['version' => '5.0.0'],
                'node_modules/vite' => ['version' => '6.0.1'],
                'node_modules/' => ['version' => '0.0.0'],
                'node_modules/react' => 'not-an-entry',
            ],
            'dependencies' => [
                'react' => ['version' => '18.3.1'],
                'next' => ['version' => '13.0.0'],
                'lodash' => ['version' => '4.17.21'],
            ],
        ]);

        return new NpmMetadata();
    }

    protected function npmBinProject(): PackageMetadata
    {
        $this->writeJson('package.json', [
            'name' => 'cli-tool',
            'repository' => 'github:acme/cli-tool',
            'bin' => ['cli-tool' => 'bin/cli.js', 'ct' => 'bin/cli.js'],
            'workspaces' => ['packages/*'],
            'dependencies' => ['express' => '^4.19.0'],
        ]);

        return new NpmMetadata();
    }

    protected function cargoProject(): PackageMetadata
    {
        $this->write('Cargo.toml', <<<'TOML'
        [package]
        name = "shop"
        version = "0.1.0"
        description = "Shop server"
        license = "MIT"
        homepage = "https://acme.test"
        repository = "https://github.com/acme/shop"
        authors = ["Jane <jane@acme.test>"]
        keywords = ["web", "shop"]
        rust-version = "1.75"

        [dependencies]
        axum = "0.7"
        tokio = { version = "1", features = ["full"] }
        serde = { git = "https://github.com/serde-rs/serde" }
        TOML);

        return new CargoMetadata();
    }

    protected function cargoWorkspace(): PackageMetadata
    {
        $this->write('Cargo.toml', <<<'TOML'
        [workspace]
        members = ["server", "cli"]

        [dependencies]
        actix-web = "4"
        TOML);

        return new CargoMetadata();
    }

    protected function goProject(): PackageMetadata
    {
        $this->write('go.mod', <<<'GOMOD'
        module github.com/acme/shop

        go 1.22

        require (
        	github.com/gin-gonic/gin v1.9.1
        	github.com/stretchr/testify v1.8.4 // indirect
        	// a comment
        	not-a-version
        )

        require github.com/gorilla/mux v1.8.0
        GOMOD);

        return new GoMetadata();
    }

    protected function mavenProject(): PackageMetadata
    {
        $this->write('pom.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <project>
          <parent>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-parent</artifactId>
            <version>3.2.0</version>
          </parent>
          <artifactId>shop</artifactId>
          <name>Shop Title</name>
          <url>https://acme.test</url>
          <licenses><license><name>Apache-2.0</name></license></licenses>
          <scm><url>https://github.com/acme/shop</url></scm>
          <developers>
            <developer><name>Jane</name></developer>
            <developer><id>bob</id></developer>
            <developer><name>Jane</name></developer>
          </developers>
          <dependencies>
            <dependency><artifactId>spring-boot-starter-web</artifactId></dependency>
            <dependency><artifactId>javalin</artifactId><version>6.1.0</version></dependency>
            <dependency><artifactId>spring-boot-starter-parent</artifactId><version>9.9.9</version></dependency>
            <dependency><groupId>no.artifact</groupId></dependency>
          </dependencies>
        </project>
        XML);

        return new MavenMetadata();
    }

    protected function pep621Project(): PackageMetadata
    {
        $this->write('pyproject.toml', <<<'TOML'
        [project]
        name = "shop-api"
        version = "0.4.1"
        description = "Storefront API"
        license = { text = "MIT" }
        homepage = "https://acme.test"
        repository = "https://github.com/acme/shop"
        keywords = ["shop"]
        authors = [{ name = "Jane Doe", email = "jane@acme.test" }, { name = "Bob" }]
        dependencies = ["@weird", "Flask_Login >= 0.6", "django (>=5.0)", "fastapi"]

        [project.scripts]
        serve = "shop.cli:serve"
        migrate = "shop.cli:migrate"

        [tool.poetry.dependencies]
        python = "^3.9"
        TOML);

        return new PythonMetadata();
    }

    protected function poetryProject(): PackageMetadata
    {
        $this->write('pyproject.toml', <<<'TOML'
        [tool.poetry]
        name = "shop-api"
        authors = ["Jane Doe <jane@acme.test>"]
        license = "BSD-3-Clause"

        [tool.poetry.dependencies]
        python = "^3.11"
        Flask = "^3.0"
        litestar = { version = "2.0" }

        [tool.poetry.scripts]
        shop = "shop:main"
        TOML);

        return new PythonMetadata();
    }

    protected function poetryWithoutDependencies(): PackageMetadata
    {
        $this->write('pyproject.toml', "[tool.poetry]\nname = \"bare\"\n");

        return new PythonMetadata();
    }

    /** @return array<string, mixed>|null */
    private function snapshot(PackageMetadata $reader): ?array
    {
        $record = $reader->read($this->context())?->toArray();
        if ($record !== null && $record['name'] === basename($this->tmpDir)) {
            $record['name'] = '<directory>';
        }

        return $record;
    }

    public function test_composer(): void
    {
        $this->assertSame([
            'ecosystem' => 'php',
            'file' => 'composer.json',
            'name' => 'acme/shop',
            'name_source' => 'composer.json name',
            'description' => 'Shop',
            'version' => null,
            'license' => 'MIT, GPL-2.0',
            'homepage' => 'https://acme.test',
            'repository' => 'https://github.com/acme/shop',
            'authors' => ['Jane', 'Bob'],
            'keywords' => ['shop', 'store'],
            'private' => true,
            'scripts' => ['test', 'post-install-cmd'],
            'entrypoints' => ['bin/shop'],
            'workspaces' => [],
            'frameworks' => [
                [
                    'id' => 'laravel/framework',
                    'name' => 'Laravel',
                    'constraint' => '^11.0',
                    'version' => '11.31.0',
                    'source' => 'composer.lock',
                ],
                [
                    'id' => 'symfony/symfony',
                    'name' => 'Symfony',
                    'constraint' => '^7.0',
                    'version' => '7.1.0',
                    'source' => 'composer.lock',
                ],
            ],
            'dependency_counts' => [
                'require' => 3,
                'require-dev' => 2,
            ],
            'platform' => [
                'composer-runtime-api' => '^2.2',
                'ext-intl' => '*',
                'php' => '^8.2',
                'php-64bit' => '*',
            ],
        ], $this->snapshot($this->composerProject()));
    }

    public function test_npm(): void
    {
        $this->assertSame([
            'ecosystem' => 'node',
            'file' => 'package.json',
            'name' => 'shop',
            'name_source' => 'package.json name',
            'description' => 'Shop front',
            'version' => '1.2.3',
            'license' => 'MIT',
            'homepage' => 'https://acme.test',
            'repository' => 'git+https://github.com/acme/shop.git',
            'authors' => ['Jane', 'Bob'],
            'keywords' => ['shop'],
            'private' => true,
            'scripts' => ['build', 'start'],
            'entrypoints' => ['server.js'],
            'workspaces' => ['apps/*'],
            'frameworks' => [
                [
                    'id' => 'next',
                    'name' => 'Next.js',
                    'constraint' => '^14.0.0',
                    'version' => '14.2.1',
                    'source' => 'package-lock.json',
                ],
                [
                    'id' => 'react',
                    'name' => 'React',
                    'constraint' => '^18.0.0',
                    'version' => '18.3.1',
                    'source' => 'package-lock.json',
                ],
                [
                    'id' => 'vite',
                    'name' => 'Vite',
                    'constraint' => '^6.0.0',
                    'version' => '6.0.1',
                    'source' => 'package-lock.json',
                ],
            ],
            'dependency_counts' => [
                'dependencies' => 3,
                'devDependencies' => 1,
            ],
            'platform' => [
                'node' => '>=20',
                'pnpm' => '9',
            ],
        ], $this->snapshot($this->npmProject()));
    }

    public function test_npm_with_a_bin_map(): void
    {
        $this->assertSame([
            'ecosystem' => 'node',
            'file' => 'package.json',
            'name' => 'cli-tool',
            'name_source' => 'package.json name',
            'description' => null,
            'version' => null,
            'license' => null,
            'homepage' => null,
            'repository' => 'github:acme/cli-tool',
            'authors' => [],
            'keywords' => [],
            'private' => false,
            'scripts' => [],
            'entrypoints' => ['cli-tool', 'ct'],
            'workspaces' => ['packages/*'],
            'frameworks' => [
                [
                    'id' => 'express',
                    'name' => 'Express',
                    'constraint' => '^4.19.0',
                    'version' => null,
                    'source' => 'package.json',
                ],
            ],
            'dependency_counts' => [
                'dependencies' => 1,
                'devDependencies' => 0,
            ],
            'platform' => [],
        ], $this->snapshot($this->npmBinProject()));
    }

    public function test_cargo(): void
    {
        $this->assertSame([
            'ecosystem' => 'rust',
            'file' => 'Cargo.toml',
            'name' => 'shop',
            'name_source' => 'Cargo.toml package.name',
            'description' => 'Shop server',
            'version' => '0.1.0',
            'license' => 'MIT',
            'homepage' => 'https://acme.test',
            'repository' => 'https://github.com/acme/shop',
            'authors' => ['Jane <jane@acme.test>'],
            'keywords' => ['web', 'shop'],
            'private' => false,
            'scripts' => [],
            'entrypoints' => [],
            'workspaces' => [],
            'frameworks' => [
                [
                    'id' => 'axum',
                    'name' => 'Axum',
                    'constraint' => '0.7',
                    'version' => null,
                    'source' => 'Cargo.toml',
                ],
            ],
            'dependency_counts' => [],
            'platform' => [
                'rust' => '1.75',
            ],
        ], $this->snapshot($this->cargoProject()));
    }

    public function test_cargo_workspace(): void
    {
        $this->assertSame([
            'ecosystem' => 'rust',
            'file' => 'Cargo.toml',
            'name' => '<directory>',
            'name_source' => 'directory',
            'description' => null,
            'version' => null,
            'license' => null,
            'homepage' => null,
            'repository' => null,
            'authors' => [],
            'keywords' => [],
            'private' => false,
            'scripts' => [],
            'entrypoints' => [],
            'workspaces' => ['server', 'cli'],
            'frameworks' => [
                [
                    'id' => 'actix-web',
                    'name' => 'Actix Web',
                    'constraint' => '4',
                    'version' => null,
                    'source' => 'Cargo.toml',
                ],
            ],
            'dependency_counts' => [],
            'platform' => [],
        ], $this->snapshot($this->cargoWorkspace()));
    }

    public function test_go(): void
    {
        $this->assertSame([
            'ecosystem' => 'go',
            'file' => 'go.mod',
            'name' => 'shop',
            'name_source' => 'go.mod module',
            'description' => null,
            'version' => null,
            'license' => null,
            'homepage' => null,
            'repository' => 'github.com/acme/shop',
            'authors' => [],
            'keywords' => [],
            'private' => false,
            'scripts' => [],
            'entrypoints' => [],
            'workspaces' => [],
            'frameworks' => [
                [
                    'id' => 'github.com/gin-gonic/gin',
                    'name' => 'Gin',
                    'constraint' => '1.9.1',
                    'version' => '1.9.1',
                    'source' => 'go.mod',
                ],
                [
                    'id' => 'github.com/gorilla/mux',
                    'name' => 'Gorilla Mux',
                    'constraint' => '1.8.0',
                    'version' => '1.8.0',
                    'source' => 'go.mod',
                ],
            ],
            'dependency_counts' => [
                'require' => 2,
            ],
            'platform' => [
                'go' => '1.22',
            ],
        ], $this->snapshot($this->goProject()));
    }

    public function test_maven(): void
    {
        $this->assertSame([
            'ecosystem' => 'java',
            'file' => 'pom.xml',
            'name' => 'shop',
            'name_source' => 'pom.xml artifactId',
            'description' => 'Shop Title',
            'version' => '3.2.0',
            'license' => 'Apache-2.0',
            'homepage' => 'https://acme.test',
            'repository' => 'https://github.com/acme/shop',
            'authors' => ['Jane', 'bob'],
            'keywords' => [],
            'private' => false,
            'scripts' => [],
            'entrypoints' => [],
            'workspaces' => [],
            'frameworks' => [
                [
                    'id' => 'spring-boot-starter-parent',
                    'name' => 'Spring Boot',
                    'constraint' => '3.2.0',
                    'version' => '3.2.0',
                    'source' => 'pom.xml',
                ],
                [
                    'id' => 'spring-boot-starter-web',
                    'name' => 'Spring Boot',
                    'constraint' => '*',
                    'version' => '*',
                    'source' => 'pom.xml',
                ],
                [
                    'id' => 'javalin',
                    'name' => 'Javalin',
                    'constraint' => '6.1.0',
                    'version' => '6.1.0',
                    'source' => 'pom.xml',
                ],
            ],
            'dependency_counts' => [
                'dependencies' => 4,
            ],
            'platform' => [],
        ], $this->snapshot($this->mavenProject()));
    }

    public function test_pep621(): void
    {
        $this->assertSame([
            'ecosystem' => 'python',
            'file' => 'pyproject.toml',
            'name' => 'shop-api',
            'name_source' => 'pyproject.toml name',
            'description' => 'Storefront API',
            'version' => '0.4.1',
            'license' => 'MIT',
            'homepage' => 'https://acme.test',
            'repository' => 'https://github.com/acme/shop',
            'authors' => ['Jane Doe', 'Bob'],
            'keywords' => ['shop'],
            'private' => false,
            'scripts' => [],
            'entrypoints' => ['serve', 'migrate'],
            'workspaces' => [],
            'frameworks' => [
                [
                    'id' => 'django',
                    'name' => 'Django',
                    'constraint' => '>=5.0',
                    'version' => null,
                    'source' => 'pyproject.toml',
                ],
                [
                    'id' => 'fastapi',
                    'name' => 'FastAPI',
                    'constraint' => '*',
                    'version' => null,
                    'source' => 'pyproject.toml',
                ],
            ],
            'dependency_counts' => [],
            'platform' => [
                'python' => '^3.9',
            ],
        ], $this->snapshot($this->pep621Project()));
    }

    public function test_poetry(): void
    {
        $this->assertSame([
            'ecosystem' => 'python',
            'file' => 'pyproject.toml',
            'name' => 'shop-api',
            'name_source' => 'pyproject.toml name',
            'description' => null,
            'version' => null,
            'license' => 'BSD-3-Clause',
            'homepage' => null,
            'repository' => null,
            'authors' => ['Jane Doe <jane@acme.test>'],
            'keywords' => [],
            'private' => false,
            'scripts' => [],
            'entrypoints' => ['shop'],
            'workspaces' => [],
            'frameworks' => [
                [
                    'id' => 'flask',
                    'name' => 'Flask',
                    'constraint' => '^3.0',
                    'version' => null,
                    'source' => 'pyproject.toml',
                ],
                [
                    'id' => 'litestar',
                    'name' => 'Litestar',
                    'constraint' => '2.0',
                    'version' => null,
                    'source' => 'pyproject.toml',
                ],
            ],
            'dependency_counts' => [],
            'platform' => [
                'python' => '^3.11',
            ],
        ], $this->snapshot($this->poetryProject()));
    }

    public function test_poetry_without_dependencies(): void
    {
        $this->assertSame([
            'ecosystem' => 'python',
            'file' => 'pyproject.toml',
            'name' => 'bare',
            'name_source' => 'pyproject.toml name',
            'description' => null,
            'version' => null,
            'license' => null,
            'homepage' => null,
            'repository' => null,
            'authors' => [],
            'keywords' => [],
            'private' => false,
            'scripts' => [],
            'entrypoints' => [],
            'workspaces' => [],
            'frameworks' => [],
            'dependency_counts' => [],
            'platform' => [],
        ], $this->snapshot($this->poetryWithoutDependencies()));
    }

    public function test_a_cargo_file_with_neither_table_is_no_package(): void
    {
        $this->write('Cargo.toml', "[lib]\nname = \"x\"\n");

        $this->assertNull((new CargoMetadata())->read($this->context()));
    }

    public function test_readers_answer_null_without_their_file(): void
    {
        $context = ProjectContext::make($this->tmpDir, []);
        foreach ([new ComposerMetadata(), new NpmMetadata(), new CargoMetadata(), new GoMetadata(), new MavenMetadata(), new PythonMetadata()] as $reader) {
            $this->assertNull($reader->read($context), $reader->id());
        }
    }
}
