<?php

namespace Tests\Unit\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Metadata\CargoMetadata;
use App\Lib\Deploy\Platform\Metadata\GoMetadata;
use App\Lib\Deploy\Platform\Metadata\MavenMetadata;
use App\Lib\Deploy\Platform\Metadata\PythonMetadata;

/**
 * The four ecosystems whose metadata is declarative enough to read safely.
 *
 * Each is thinner than Composer or npm and each is thin in its own way, so
 * what these assert is mostly the boundary: how much the file actually says,
 * and that nothing is invented past it.
 */
class DeclarativeReadersTest extends MetadataTestCase
{
    public function test_pyproject_pep621(): void
    {
        $this->write('pyproject.toml', <<<'TOML'
        [build-system]
        requires = ["hatchling"]

        [project]
        name = "shop-api"
        version = "0.4.1"
        description = "Storefront API"
        requires-python = ">=3.11"
        authors = [{ name = "Jane Doe", email = "jane@acme.test" }]
        dependencies = [
            "django>=5.0",
            "psycopg[binary]>=3.1",
        ]

        [project.scripts]
        serve = "shop.cli:serve"
        TOML);

        $package = (new PythonMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame('shop-api', $package->name);
        $this->assertSame('0.4.1', $package->version);
        $this->assertSame('Storefront API', $package->description);
        // The email in the inline table is not a second author.
        $this->assertSame(['Jane Doe'], $package->authors);
        $this->assertSame(['serve'], $package->entrypoints);
        $this->assertSame('Django', $package->framework()?->name);
        $this->assertSame('>=5.0', $package->framework()?->constraint);
        // No lockfile this can read, so the range is all it claims.
        $this->assertNull($package->framework()?->version);
        $this->assertSame(['python' => '>=3.11'], $package->platform);
    }

    public function test_pyproject_poetry(): void
    {
        $this->write('pyproject.toml', <<<'TOML'
        [tool.poetry]
        name = "shop-api"
        description = "Storefront API"
        authors = ["Jane Doe <jane@acme.test>"]

        [tool.poetry.dependencies]
        python = "^3.11"
        Flask = "^3.0"
        TOML);

        $package = (new PythonMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame('shop-api', $package->name);
        $this->assertSame(['Jane Doe <jane@acme.test>'], $package->authors);
        // Normalised the way the packaging spec does: Flask is flask.
        $this->assertSame('Flask', $package->framework()?->name);
        // Poetry keeps the interpreter among the dependencies; it is still
        // the platform, not a package.
        $this->assertSame(['python' => '^3.11'], $package->platform);
    }

    public function test_a_pyproject_with_only_tooling_names_no_application(): void
    {
        $this->write('pyproject.toml', "[tool.ruff]\nline-length = 120\n");

        $this->assertNull((new PythonMetadata())->read($this->context()));
    }

    public function test_cargo_package(): void
    {
        $this->write('Cargo.toml', <<<'TOML'
        [package]
        name = "shop"
        version = "0.2.0"
        description = "Storefront"
        license = "MIT"
        edition = "2021"

        [dependencies]
        axum = "0.7"
        tokio = { version = "1", features = ["full"] }
        TOML);

        $package = (new CargoMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame('rust', $package->ecosystem);
        $this->assertSame('shop', $package->name);
        $this->assertSame('0.2.0', $package->version);
        $this->assertSame('MIT', $package->license);
        $this->assertSame('Axum', $package->framework()?->name);
        $this->assertSame('0.7', $package->framework()?->constraint);
        // The edition is a language dialect; only rust-version is a platform
        // requirement, and this manifest states none.
        $this->assertSame([], $package->platform);
    }

    public function test_a_cargo_workspace_root_reports_its_members(): void
    {
        $this->write('Cargo.toml', "[workspace]\nmembers = [\"crates/api\", \"crates/worker\"]\n");

        $package = (new CargoMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame(['crates/api', 'crates/worker'], $package->workspaces);
        $this->assertFalse($package->isNamed());
    }

    public function test_go_mod(): void
    {
        $this->write('go.mod', <<<'MOD'
        module github.com/acme/shop

        go 1.22

        require (
            github.com/gin-gonic/gin v1.9.1
            github.com/bytedance/sonic v1.9.1 // indirect
        )
        MOD);

        $package = (new GoMetadata())->read($this->context());

        $this->assertNotNull($package);
        // The last segment names the application; the whole path names the repo.
        $this->assertSame('shop', $package->name);
        $this->assertSame('github.com/acme/shop', $package->repository);
        $this->assertSame('Gin', $package->framework()?->name);
        // go.mod records the selected version, so there is no range to report.
        $this->assertSame('1.9.1', $package->framework()?->version);
        // The indirect dependency is not this project's framework.
        $this->assertSame(['require' => 1], $package->dependencyCounts);
        $this->assertSame(['go' => '1.22'], $package->platform);
    }

    public function test_pom_xml(): void
    {
        $this->write('pom.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <project xmlns="http://maven.apache.org/POM/4.0.0">
          <parent>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-parent</artifactId>
            <version>3.2.0</version>
          </parent>
          <artifactId>shop</artifactId>
          <name>Acme Shop</name>
          <dependencies>
            <dependency>
              <groupId>org.springframework.boot</groupId>
              <artifactId>spring-boot-starter-web</artifactId>
            </dependency>
          </dependencies>
        </project>
        XML);

        $package = (new MavenMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame('java', $package->ecosystem);
        $this->assertSame('shop', $package->name);
        // No <description>, so the human title stands in for one.
        $this->assertSame('Acme Shop', $package->description);
        // Spring Boot is declared as the parent, never as a dependency.
        $this->assertSame('Spring Boot', $package->framework()?->name);
        $this->assertSame('3.2.0', $package->framework()?->version);
        $this->assertSame('3.2.0', $package->version);
    }

    public function test_unreadable_xml_names_the_ecosystem_and_nothing_else(): void
    {
        $this->write('pom.xml', '<project><artifactId>shop</artifactId>');

        $package = (new MavenMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame('java', $package->ecosystem);
        $this->assertFalse($package->isNamed());
        $this->assertSame([], $package->frameworks);
    }

    public function test_absent_ecosystems_report_nothing(): void
    {
        $context = $this->context();

        $this->assertNull((new PythonMetadata())->read($context));
        $this->assertNull((new CargoMetadata())->read($context));
        $this->assertNull((new GoMetadata())->read($context));
        $this->assertNull((new MavenMetadata())->read($context));
    }
}
