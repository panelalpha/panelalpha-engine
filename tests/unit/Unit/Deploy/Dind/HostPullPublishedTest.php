<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\PublishedBaseImage;
use App\Lib\Deploy\Dind\DindImageStore;
use Tests\Support\HostImageLines;
use Tests\TestCase;

/**
 * A host pulls the published copy of one of our base images before compiling it, and keeps it only when it was
 * built from the Dockerfile this engine renders.
 */
class HostPullPublishedTest extends TestCase
{
    private string $dir;

    private string $tag;

    private string $dockerfile;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deploy.base_image_registry' => 'ghcr.io/panelalpha']);
        $item = $this->catalogueBuild();
        $this->tag = $item['ref'];
        $this->dockerfile = (string) BuiltImage::dockerfile($item['ref'], $item['extensions']);

        $this->dir = sys_get_temp_dir().'/host-pull-published-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0700, true);
        mkdir($this->dir.'/state/host', 0700, true);
        file_put_contents($this->dir.'/bin/docker', <<<'SH'
#!/bin/bash
echo "$*" >> "$STATE/calls"
key() { printf '%s' "$1" | tr '/:' '__'; }
words=(); for w; do [ "$w" = -- ] || words+=("$w"); done; set -- "${words[@]}"
case "$1 $2" in
  "image inspect")
    if [ "$3" = --format ]; then [ -e "$STATE/host/$(key "$5")" ] && cat "$STATE/label-$(key "$5")" 2>/dev/null; exit; fi
    [ -e "$STATE/host/$(key "$3")" ]; exit ;;
  "image rm") rm -f "$STATE/host/$(key "$3")"; exit 0 ;;
esac
case "$1" in
  pull) [ -e "$STATE/published-$(key "$3")" ] || { echo "manifest unknown" >&2; exit 1; }
        touch "$STATE/host/$(key "$3")"; exit 0 ;;
  tag) [ -e "$STATE/host/$(key "$2")" ] && touch "$STATE/host/$(key "$3")"; exit ;;
  build) while [ $# -gt 0 ]; do [ "$1" = -t ] && t="$2"; shift; done
         cat >/dev/null; touch "$STATE/host/$(key "$t")"; echo built >> "$STATE/built"; exit 0 ;;
esac
echo "unexpected docker call: $*" >&2; exit 99
SH);
        chmod($this->dir.'/bin/docker', 0755);
        HostImageLines::install($this->dir.'/bin');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_catalogue_base_is_published_under_the_registry(): void
    {
        $this->assertSame('ghcr.io/panelalpha/php:'.explode(':', $this->tag, 2)[1], PublishedBaseImage::ref($this->tag));
    }

    public function test_a_tag_outside_the_catalogue_is_not_looked_for(): void
    {
        $this->assertNull(PublishedBaseImage::ref($this->tag.'-x00000000'));
        $this->assertNull(PublishedBaseImage::ref('composer:2'));
    }

    public function test_none_or_empty_turns_pulling_off(): void
    {
        config(['deploy.base_image_registry' => 'none']);
        $this->assertNull(PublishedBaseImage::ref($this->tag));
        config(['deploy.base_image_registry' => '']);
        $this->assertNull(PublishedBaseImage::ref($this->tag));
        $this->assertStringNotContainsString('docker pull', (new DindImageStore())->hostBuildCommand($this->tag, $this->dockerfile));
    }

    public function test_the_published_copy_is_used_when_it_was_built_from_this_dockerfile(): void
    {
        $this->publish(PublishedBaseImage::dockerfileSha($this->dockerfile));

        [$code, $out] = $this->shell((new DindImageStore())->hostBuildCommand($this->tag, $this->dockerfile));

        $this->assertSame(0, $code);
        $this->assertTrue($this->hostHas($this->tag));
        $this->assertFalse($this->hostHas($this->published()), 'the registry name is dropped again');
        $this->assertFileDoesNotExist($this->dir.'/state/built');
        $this->assertStringContainsString('Pulled base image', $out);
    }

    public function test_a_copy_built_from_another_dockerfile_is_removed_and_the_host_builds(): void
    {
        $this->publish(str_repeat('0', 64));

        [$code] = $this->shell((new DindImageStore())->hostBuildCommand($this->tag, $this->dockerfile));

        $this->assertSame(0, $code);
        $this->assertFileExists($this->dir.'/state/built');
        $this->assertFalse($this->hostHas($this->published()));
        $this->assertTrue($this->hostHas($this->tag));
    }

    public function test_an_unpublished_tag_is_built(): void
    {
        [$code] = $this->shell((new DindImageStore())->hostBuildCommand($this->tag, $this->dockerfile));

        $this->assertSame(0, $code);
        $this->assertFileExists($this->dir.'/state/built');
    }

    public function test_a_host_that_has_the_tag_neither_pulls_nor_builds(): void
    {
        touch($this->dir.'/state/host/'.$this->key($this->tag));
        $this->publish(PublishedBaseImage::dockerfileSha($this->dockerfile));

        $this->shell((new DindImageStore())->hostBuildCommand($this->tag, $this->dockerfile));

        $this->assertStringNotContainsString('pull', (string) @file_get_contents($this->dir.'/state/calls'));
        $this->assertFileDoesNotExist($this->dir.'/state/built');
    }

    public function test_a_refresh_follows_the_republished_copy_instead_of_compiling(): void
    {
        touch($this->dir.'/state/host/'.$this->key($this->tag));
        $this->publish(PublishedBaseImage::dockerfileSha($this->dockerfile));

        [$code] = $this->shell((new DindImageStore())->hostBuildCommand($this->tag, $this->dockerfile, true));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('pull -q -- '.$this->published(), (string) file_get_contents($this->dir.'/state/calls'));
        $this->assertFileDoesNotExist($this->dir.'/state/built');
    }

    /** @return array<string, mixed> */
    private function catalogueBuild(): array
    {
        foreach (HostPrewarmPlan::available(['php']) as $item) {
            if ($item['kind'] === HostPrewarmPlan::KIND_BUILD && $item['extensions'] === []) {
                return $item;
            }
        }
        $this->fail('images.yaml declares no built PHP base');
    }

    private function published(): string
    {
        return (string) PublishedBaseImage::ref($this->tag);
    }

    private function publish(string $label): void
    {
        touch($this->dir.'/state/published-'.$this->key($this->published()));
        file_put_contents($this->dir.'/state/label-'.$this->key($this->published()), $label."\n");
    }

    private function hostHas(string $ref): bool
    {
        return file_exists($this->dir.'/state/host/'.$this->key($ref));
    }

    private function key(string $ref): string
    {
        return strtr($ref, '/:', '__');
    }

    /** @return array{int, string} */
    private function shell(string $command): array
    {
        $env = 'PATH='.escapeshellarg($this->dir.'/bin:'.getenv('PATH')).' STATE='.escapeshellarg($this->dir.'/state');
        exec($env.' sh -c '.escapeshellarg(HostImageLines::line($command)).' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }
}
