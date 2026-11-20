<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\Platform\Runtime\Python\SystemPackages;
use PHPUnit\Framework\TestCase;
use Tests\Support\SystemPackagesFixtures;

/**
 * What a Python image needs installed before pip can build anything.
 *
 * The case that made this necessary: a Django project with `psycopg2-binary` in
 * requirements.txt died on `Error: pg_config executable not found` — pip had no
 * wheel for that interpreter, fell back to the C source, and python:3.12-slim
 * has neither a compiler nor libpq headers.
 */
class SystemPackagesTest extends TestCase
{
    use SystemPackagesFixtures;

    public function test_a_compiler_is_always_present(): void
    {
        $packages = SystemPackages::for(["flask\n"]);

        $this->assertContains('build-essential', $packages);
        // Many setup.py builds shell out to pkg-config, and its absence reads
        // as a missing library rather than a missing tool.
        $this->assertContains('pkg-config', $packages);
    }

    /** The exact failure this fixes. */
    public function test_psycopg2_binary_gets_the_postgres_headers(): void
    {
        $this->assertContains('libpq-dev', SystemPackages::for(["Django\npsycopg2-binary==2.9.9\n"]));
    }

    public function test_psycopg2_from_source_gets_them_too(): void
    {
        $this->assertContains('libpq-dev', SystemPackages::for(["psycopg2==2.9.9\n"]));
    }

    public function test_a_mysql_project_gets_the_mysql_headers(): void
    {
        $packages = SystemPackages::for(["Django\nmysqlclient\n"]);

        $this->assertContains('default-libmysqlclient-dev', $packages);
    }

    public function test_pillow_gets_its_image_libraries(): void
    {
        $packages = SystemPackages::for(["Pillow==11.0.0\n"]);

        $this->assertContains('libjpeg-dev', $packages);
        $this->assertContains('zlib1g-dev', $packages);
    }

    /**
     * Postgres is the guess when nothing is recognised — Django's default, and
     * an unused -dev package costs host build time once rather than a failed
     * deploy in an account.
     */
    public function test_an_unrecognised_project_still_gets_a_database_client(): void
    {
        $this->assertContains('libpq-dev', SystemPackages::for(["flask\ngunicorn\n"]));
    }

    /**
     * Distribution names are substrings of each other constantly, so matching
     * is bounded. `psycopg` must not fire inside `psycopg2-binary` and add a
     * package the more specific entry already covers, and a comment naming an
     * unrelated project must not add anything at all.
     */
    public function test_matching_is_bounded_to_whole_distribution_names(): void
    {
        $packages = SystemPackages::for(["# see https://example.com/not-pillow-really\nflask\n"]);

        $this->assertNotContains('libjpeg-dev', $packages);
    }

    public function test_the_set_is_deterministic_regardless_of_manifest_order(): void
    {
        $a = SystemPackages::for(["psycopg2-binary\nPillow\n"]);
        $b = SystemPackages::for(["Pillow\npsycopg2-binary\n"]);

        $this->assertSame(
            PythonBaseImage::normalizePackages($a),
            PythonBaseImage::normalizePackages($b)
        );
    }

    // -------------------------------------------------------------------------
    // The image built from them
    // -------------------------------------------------------------------------

    public function test_the_tag_is_derived_from_the_official_python_tag(): void
    {
        $tag = PythonBaseImage::tag('python:3.12-slim', ['build-essential', 'libpq-dev']);

        $this->assertNotNull($tag);
        $this->assertStringStartsWith(PythonBaseImage::repository() . ':3.12-slim-pa', $tag);
    }

    public function test_a_changed_package_set_changes_the_tag(): void
    {
        $this->assertNotSame(
            PythonBaseImage::tag('python:3.12-slim', ['build-essential']),
            PythonBaseImage::tag('python:3.12-slim', ['build-essential', 'libpq-dev'])
        );
    }

    public function test_no_tag_for_images_we_do_not_build(): void
    {
        $this->assertNull(PythonBaseImage::tag('ghcr.io/acme/python:3.12', ['build-essential']));
        $this->assertNull(PythonBaseImage::tag('python:3.12-slim', []));
        // A long tail of unusual libraries is not worth another image on the
        // host; that project installs its own.
        $this->assertNull(PythonBaseImage::tag('python:3.12-slim', array_map(
            static fn (int $i): string => "lib{$i}-dev",
            range(1, PythonBaseImage::MAX_PACKAGES + 1)
        )));
    }

    /**
     * Nothing publishes `panelalpha/python`, so a tag we fail to recognise as
     * ours goes to a registry that 404s and then to a pull inside the
     * account's nested NAT.
     */
    public function test_the_built_tag_is_recognised_as_ours(): void
    {
        $tag = (string) PythonBaseImage::tag('python:3.12-slim', ['build-essential', 'libpq-dev']);

        $this->assertTrue(BuiltImage::isOurs($tag));
        $this->assertSame('python', BuiltImage::runtimeFor($tag));
        $this->assertSame('python:3.12-slim', BuiltImage::sourceImage($tag));
        $this->assertSame('python:3.12-slim', PythonBaseImage::sourceImage($tag));
    }

    public function test_a_ruby_tag_is_not_a_python_one(): void
    {
        $this->assertNull(PythonBaseImage::sourceImage('panelalpha/ruby:3.3-slim-bookworm-pa0c53db49'));
    }

    public function test_the_dockerfile_installs_what_was_asked_for(): void
    {
        $dockerfile = PythonBaseImage::dockerfile('python:3.12-slim', ['libpq-dev', 'build-essential']);

        $this->assertStringContainsString('FROM python:3.12-slim', $dockerfile);
        $this->assertStringContainsString('build-essential libpq-dev', $dockerfile);
    }
}
