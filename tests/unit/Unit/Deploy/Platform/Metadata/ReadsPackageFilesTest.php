<?php

namespace Tests\Unit\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Metadata\ReadsPackageFiles;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * The coercions every package-file reader repeats.
 *
 * These files are written by hand and are wrong constantly: a `license` that
 * is a string in one project and a list in the next, an `author` that is
 * sometimes an object, a `version` someone set to the integer 1. Six readers
 * each defending themselves would be six copies of this, and the fifth copy
 * is where the fatal lives - in a code path that runs against every
 * repository a customer points at us.
 */
class ReadsPackageFilesTest extends TestCase
{
    private object $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new class {
            use ReadsPackageFiles {
                text as public;
                stringList as public;
                keys as public;
                directoryName as public;
                matchFrameworks as public;
                tomlSection as public;
                tomlString as public;
                tomlList as public;
                map as public;
                constraints as public;
            }
        };
    }

    public function test_a_string_is_trimmed(): void
    {
        $this->assertSame('acme/shop', $this->reader::text('  acme/shop '));
    }

    public function test_a_number_becomes_its_text(): void
    {
        // `"version": 1` is legal JSON and common in hand-written files.
        $this->assertSame('1', $this->reader::text(1));
        $this->assertSame('1.5', $this->reader::text(1.5));
    }

    public function test_anything_with_no_text_in_it_is_nothing(): void
    {
        foreach ([null, '', '   ', [], ['a'], true, false] as $value) {
            $this->assertNull($this->reader::text($value), var_export($value, true));
        }
    }

    public function test_a_single_value_becomes_a_one_entry_list(): void
    {
        // `"license": "MIT"` and `"license": ["MIT"]` both occur.
        $this->assertSame(['MIT'], $this->reader::stringList('MIT'));
        $this->assertSame(['MIT'], $this->reader::stringList(['MIT']));
    }

    public function test_a_list_of_objects_is_read_by_its_named_key(): void
    {
        // npm's `contributors`, composer's `authors`.
        $this->assertSame(
            ['Ada', 'Grace'],
            $this->reader::stringList([['name' => 'Ada', 'email' => 'a@x'], ['name' => 'Grace']])
        );
    }

    public function test_a_mixed_list_takes_what_it_can(): void
    {
        $this->assertSame(
            ['MIT', 'Apache-2.0'],
            $this->reader::stringList(['MIT', null, ['name' => 'Apache-2.0'], ['no-name' => 'x'], []])
        );
    }

    public function test_duplicates_are_reported_once(): void
    {
        $this->assertSame(['MIT'], $this->reader::stringList(['MIT', 'MIT', ['name' => 'MIT']]));
    }

    public function test_a_value_that_is_no_kind_of_list_yields_nothing(): void
    {
        $this->assertSame([], $this->reader::stringList(null));
        $this->assertSame([], $this->reader::stringList(true));
    }

    public function test_script_names_are_read_without_their_bodies(): void
    {
        // The bodies routinely contain tokens and deploy keys.
        $this->assertSame(
            ['build', 'test'],
            $this->reader::keys(['build' => 'vite build', 'test' => 'vitest run'])
        );
    }

    public function test_keys_keep_their_declaration_order(): void
    {
        $this->assertSame(['z', 'a', 'm'], $this->reader::keys(['z' => 1, 'a' => 2, 'm' => 3]));
    }

    public function test_a_scripts_block_that_is_not_a_map_yields_nothing(): void
    {
        $this->assertSame([], $this->reader::keys('npm run build'));
        $this->assertSame([], $this->reader::keys(null));
    }

    public function test_a_project_that_never_named_itself_falls_back_to_its_directory(): void
    {
        $context = ProjectContext::make('/srv/checkouts/acme-shop', []);

        $this->assertSame('acme-shop', $this->reader::directoryName($context));
    }

    public function test_a_declared_map_becomes_constraints(): void
    {
        $this->assertSame(
            ['laravel/framework' => '^11.0', 'guzzlehttp/guzzle' => '^7.8'],
            $this->reader::constraints(['laravel/framework' => '^11.0', 'guzzlehttp/guzzle' => '^7.8'])
        );
    }

    public function test_the_first_section_wins_for_a_package_in_both(): void
    {
        // require before require-dev: a package in both is a real dependency
        // whose dev entry is an override.
        $this->assertSame(
            ['phpunit/phpunit' => '^11.0'],
            $this->reader::constraints(['phpunit/phpunit' => '^11.0'], ['phpunit/phpunit' => '^10.0'])
        );
    }

    public function test_a_dependency_with_no_readable_range_is_still_a_dependency(): void
    {
        // Yarn resolutions and workspace protocols put objects here. The
        // package is present either way, and presence is what detection uses.
        $this->assertSame(['acme/pkg' => '*'], $this->reader::constraints(['acme/pkg' => ['version' => '1.0']]));
    }

    public function test_a_section_that_is_not_a_map_contributes_nothing(): void
    {
        $this->assertSame([], $this->reader::constraints([]));
    }

    public function test_a_toml_table_body_stops_at_the_next_table(): void
    {
        $toml = <<<'TOML'
        [package]
        name = "shop"
        version = "0.1.0"

        [dependencies]
        serde = "1"
        TOML;

        $section = $this->reader::tomlSection($toml, 'package');

        $this->assertStringContainsString('name = "shop"', $section);
        $this->assertStringNotContainsString('serde', $section);
    }

    public function test_a_table_that_is_not_there_reads_as_nothing(): void
    {
        $this->assertNull($this->reader::tomlSection("[package]\nname = \"shop\"\n", 'project'));
    }

    public function test_a_toml_string_is_read_in_either_quoting(): void
    {
        $this->assertSame('shop', $this->reader::tomlString('name = "shop"', 'name'));
        $this->assertSame('shop', $this->reader::tomlString("name = 'shop'", 'name'));
        $this->assertSame('shop', $this->reader::tomlString("  name   =   \"shop\"", 'name'));
    }

    public function test_a_toml_key_that_is_not_there_reads_as_nothing(): void
    {
        $this->assertNull($this->reader::tomlString('name = "shop"', 'version'));
    }

    public function test_a_toml_list_is_read(): void
    {
        $this->assertSame(
            ['Ada', 'Grace'],
            $this->reader::tomlList('authors = ["Ada", "Grace"]', 'authors')
        );
    }

    public function test_a_toml_list_spanning_several_lines_is_read(): void
    {
        $section = "authors = [\n  \"Ada\",\n  \"Grace\",\n]\n";

        $this->assertSame(['Ada', 'Grace'], $this->reader::tomlList($section, 'authors'));
    }

    public function test_a_toml_list_that_is_not_there_is_empty(): void
    {
        $this->assertSame([], $this->reader::tomlList('name = "shop"', 'authors'));
    }

    public function test_the_most_specific_framework_is_reported_first(): void
    {
        // Every Next.js project also depends on React, and reporting React
        // first would describe the wrong thing entirely.
        $table = ['next' => 'Next.js', 'react' => 'React'];
        $frameworks = $this->reader::matchFrameworks(
            $table,
            ['react' => '^18.0.0', 'next' => '^14.0.0'],
            ['next' => '14.2.3'],
            'package.json',
            'package-lock.json'
        );

        $this->assertSame(['next', 'react'], array_map(static fn ($f) => $f->id, $frameworks));
    }

    public function test_a_framework_reports_its_resolved_version_and_where_it_came_from(): void
    {
        $frameworks = $this->reader::matchFrameworks(
            ['next' => 'Next.js'],
            ['next' => '^14.0.0'],
            ['next' => '14.2.3'],
            'package.json',
            'package-lock.json'
        );

        $this->assertSame('Next.js', $frameworks[0]->name);
        $this->assertSame('^14.0.0', $frameworks[0]->constraint);
        $this->assertSame('14.2.3', $frameworks[0]->version);
        $this->assertSame('package-lock.json', $frameworks[0]->source);
    }

    public function test_a_framework_with_no_lockfile_entry_reports_the_manifest(): void
    {
        // A declared range is not a version. Saying it came from the manifest
        // is how a reader knows nothing resolved it.
        $frameworks = $this->reader::matchFrameworks(
            ['next' => 'Next.js'],
            ['next' => '^14.0.0'],
            [],
            'package.json',
            'package-lock.json'
        );

        $this->assertNull($frameworks[0]->version);
        $this->assertSame('package.json', $frameworks[0]->source);
    }

    public function test_a_framework_the_project_does_not_use_is_not_reported(): void
    {
        $this->assertSame(
            [],
            $this->reader::matchFrameworks(['next' => 'Next.js'], ['react' => '^18.0.0'], [], 'package.json')
        );
    }

    public function test_a_decoded_file_that_is_not_an_object_reads_as_empty(): void
    {
        $this->assertSame([], $this->reader::map(null));
        $this->assertSame([], $this->reader::map('not json'));
        $this->assertSame(['a' => 1], $this->reader::map(['a' => 1]));
    }
}
