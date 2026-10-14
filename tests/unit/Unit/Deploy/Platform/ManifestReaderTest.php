<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\ManifestReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading a platform manifest, and refusing one that is wrong.
 *
 * Manifests are hand-written YAML that decides how every project of a given
 * shape is built, so a typo is not one broken deploy - it is every deploy of
 * that platform, and the symptom appears three stages later as a build that
 * fails on an empty command. Everything here fails loudly at load time, and
 * says which file and which key.
 */
class ManifestReaderTest extends TestCase
{
    /**
     * @param array<string, mixed> $raw
     */
    private function reader(array $raw, string $subject = 'laravel.yaml'): ManifestReader
    {
        return new ManifestReader($raw, $subject);
    }

    public function test_an_error_names_the_file_it_came_from(): void
    {
        // With 29 manifests, an error that does not say which one is a
        // needle-in-a-haystack at deploy time.
        $this->expectExceptionMessage("laravel.yaml: 'label' must be a non-empty string");

        $this->reader([])->text('label');
    }

    public function test_errors_can_be_renamed_after_the_manifests_own_id(): void
    {
        // Once the id is known it is more useful than the filename.
        $reader = $this->reader([]);
        $reader->nameErrorsAfter('laravel');

        $this->expectExceptionMessage("laravel: 'label' must be a non-empty string");
        $reader->text('label');
    }

    public function test_a_key_nobody_recognises_is_refused(): void
    {
        // A misspelled key would otherwise be silently ignored, and the
        // manifest would quietly not do the thing it says it does.
        try {
            $this->reader(['id' => 'x', 'lable' => 'Laravel'])->assertNoUnknownKeys(['id', 'label']);
            $this->fail('expected a rejection');
        } catch (ManifestException $e) {
            $this->assertStringContainsString('unknown key(s) lable', $e->getMessage());
            $this->assertStringContainsString('expected one of id, label', $e->getMessage());
        }
    }

    public function test_a_manifest_using_only_known_keys_passes(): void
    {
        $this->reader(['id' => 'x'])->assertNoUnknownKeys(['id', 'label']);

        $this->addToAssertionCount(1);
    }

    public function test_an_identifier_must_be_kebab_case(): void
    {
        $this->assertSame('next-js', $this->reader(['id' => 'next-js'])->identifier('id'));
        $this->assertSame('php', $this->reader(['id' => 'php'])->identifier('id'));
    }

    public function test_an_identifier_that_would_not_survive_a_filename_is_refused(): void
    {
        foreach (['Next.js', 'next js', '-next', '', 'NEXT'] as $value) {
            try {
                $this->reader(['id' => $value])->identifier('id');
                $this->fail('accepted ' . var_export($value, true));
            } catch (ManifestException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_identifier_can_fall_back_to_a_default(): void
    {
        // How `strategy` defaults to the manifest's own id.
        $this->assertSame('laravel', $this->reader([])->identifier('strategy', 'laravel'));
    }

    public function test_text_is_trimmed(): void
    {
        $this->assertSame('Laravel', $this->reader(['label' => '  Laravel  '])->text('label'));
    }

    public function test_text_that_is_only_whitespace_is_not_text(): void
    {
        $this->expectException(ManifestException::class);

        $this->reader(['label' => '   '])->text('label');
    }

    public function test_an_optional_value_may_be_absent(): void
    {
        $this->assertNull($this->reader([])->optionalText('image', 'must be an image'));
    }

    public function test_an_optional_value_that_is_present_must_still_be_valid(): void
    {
        // `image:` with nothing after it is a YAML null, and a manifest that
        // meant to name an image would otherwise silently name none.
        $this->expectExceptionMessage("laravel.yaml: 'image' must be an image reference");

        $this->reader(['image' => ''])->optionalText('image', 'must be an image reference');
    }

    public function test_an_integer_is_read(): void
    {
        $this->assertSame(940, $this->reader(['priority' => 940])->integer('priority', 'must be a number'));
    }

    public function test_a_number_written_as_a_string_is_refused(): void
    {
        // `priority: "940"` sorts as a string and would order the manifest
        // wrongly against every other one.
        $this->expectException(ManifestException::class);

        $this->reader(['priority' => '940'])->integer('priority', 'must be a number');
    }

    public function test_an_enum_takes_one_of_its_allowed_values(): void
    {
        $this->assertSame('nginx', $this->reader(['runtime' => 'nginx'])->enum('runtime', ['node', 'nginx'], 'node'));
    }

    public function test_an_enum_falls_back_when_absent(): void
    {
        $this->assertSame('node', $this->reader([])->enum('runtime', ['node', 'nginx'], 'node'));
    }

    public function test_an_enum_lists_what_it_would_have_accepted(): void
    {
        $this->expectExceptionMessage("'runtime' must be one of node, nginx");

        $this->reader(['runtime' => 'deno'])->enum('runtime', ['node', 'nginx'], 'node');
    }

    public function test_a_port_is_read(): void
    {
        $this->assertSame(8080, $this->reader(['port' => 8080])->port('port'));
        $this->assertNull($this->reader([])->port('port'));
    }

    public function test_a_number_that_is_not_a_port_is_refused(): void
    {
        foreach ([0, -1, 65536, '8080', 8080.5] as $value) {
            try {
                $this->reader(['port' => $value])->port('port');
                $this->fail('accepted ' . var_export($value, true));
            } catch (ManifestException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_object_defaults_to_empty_when_optional(): void
    {
        $this->assertSame([], $this->reader([])->object('env', 'must be a map'));
    }

    public function test_a_required_object_must_actually_be_there(): void
    {
        // `detect:` with nothing under it would match every project or none,
        // depending on the matcher - neither is what the author meant.
        $this->expectExceptionMessage("'detect' must be a non-empty map");

        $this->reader(['detect' => []])->object('detect', 'must be a non-empty map', true);
    }

    public function test_something_that_is_not_an_object_is_refused(): void
    {
        $this->expectException(ManifestException::class);

        $this->reader(['env' => 'NODE_ENV=production'])->object('env', 'must be a map');
    }

    public function test_a_passthrough_value_is_taken_as_is_or_dropped(): void
    {
        // For blocks validated further down rather than here.
        $this->assertSame(['file' => 'composer.json'], $this->reader(['detect' => ['file' => 'composer.json']])->passthrough('detect'));
        $this->assertSame([], $this->reader(['detect' => 'composer.json'])->passthrough('detect'));
    }

    public function test_presence_is_distinguishable_from_absence(): void
    {
        // `image: null` is a manifest saying "no image", which is not the same
        // as a manifest that never mentioned one.
        $reader = $this->reader(['image' => null]);

        $this->assertTrue($reader->has('image'));
        $this->assertFalse($reader->has('label'));
        $this->assertNull($reader->raw('image'));
    }
}
