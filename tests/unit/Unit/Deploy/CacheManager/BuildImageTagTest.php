<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\NodeBuildImage;
use App\Lib\Deploy\CacheManager\RustBuildToolsImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exact tag each host build image gets: the slug of the toolchain image and a fingerprint
 * of the Dockerfile, so a change to either shows up here.
 */
class BuildImageTagTest extends TestCase
{
    private static function expected(string $repository, string $slug, string $dockerfile): string
    {
        return $repository.':'.$slug.'-pa'.substr(sha1($dockerfile), 0, 8);
    }

    /** @return array<string, array{string, string}> */
    public static function images(): array
    {
        return [
            'plain' => ['gradle:9-jdk25', 'gradle-9-jdk25'],
            'padded' => ["  gradle:9-jdk25\t", 'gradle-9-jdk25'],
            'registry with port' => ['registry.example.com:5000/library/maven:3-eclipse-temurin-21', 'registry.example.com-5000-library-maven-3-eclipse-temurin-21'],
            'uppercase and digest' => ['Example/Rust@sha256:'.str_repeat('a', 64), 'example-rust-sha256-'.substr(str_repeat('a', 64), 0, 60)],
            'leading and trailing separators' => ['_rust:1.80.', '_rust-1.80'],
            'long' => [str_repeat('abcdefghij', 9).':1', str_repeat('abcdefghij', 8)],
        ];
    }

    #[DataProvider('images')]
    public function test_the_node_build_tag_is_the_slug_and_the_fingerprint(string $image, string $slug): void
    {
        $dockerfile = (string) NodeBuildImage::dockerfile($image, 'node:22-bookworm-slim');

        $this->assertNotSame('', $dockerfile);
        $this->assertSame(
            self::expected('panelalpha/build-node', $slug, $dockerfile),
            NodeBuildImage::tag($image, 'node:22-bookworm-slim')
        );
    }

    #[DataProvider('images')]
    public function test_the_rust_build_tag_is_the_slug_and_the_fingerprint(string $image, string $slug): void
    {
        $dockerfile = (string) RustBuildToolsImage::dockerfile($image, ['protobuf-compiler', 'cmake']);

        $this->assertNotSame('', $dockerfile);
        $this->assertSame(
            self::expected('panelalpha/build-rust', $slug, $dockerfile),
            RustBuildToolsImage::tag($image, ['protobuf-compiler', 'cmake'])
        );
    }

    public function test_known_tags_are_unchanged(): void
    {
        $this->assertSame('panelalpha/build-node:gradle-9-jdk25-pa'.substr(sha1(
            "FROM node:20-bookworm-slim AS node\nFROM gradle:9-jdk25\n"
            ."COPY --from=node /usr/local/bin/ /usr/local/bin/\n"
            ."COPY --from=node /usr/local/lib/node_modules/ /usr/local/lib/node_modules/\n"
            ."COPY --from=node /opt/ /opt/\n"
            ."RUN node --version && npm --version && { ! command -v corepack >/dev/null || corepack enable pnpm; }\n"
        ), 0, 8), NodeBuildImage::tag('gradle:9-jdk25', 'node:20-bookworm-slim'));
        $this->assertSame('panelalpha/build-rust:rust-1-bookworm-pa'.substr(sha1(
            "FROM rust:1-bookworm\nRUN apt-get update && apt-get install -y --no-install-recommends cmake mold protobuf-compiler"
            ." && rm -rf /var/lib/apt/lists/*\n"
        ), 0, 8), RustBuildToolsImage::tag(' rust:1-bookworm ', ['protobuf-compiler', 'mold', 'cmake', 'mold']));
    }

    public function test_an_unbuildable_pair_has_no_tag(): void
    {
        $this->assertNull(NodeBuildImage::tag('gradle:9', "node:22\nRUN id"));
        $this->assertNull(NodeBuildImage::tag('', 'node:22'));
        $this->assertNull(RustBuildToolsImage::tag('rust:1', []));
        $this->assertNull(RustBuildToolsImage::tag('rust:1', ['Bad_Name']));
        $this->assertNull(RustBuildToolsImage::tag("rust:1\nRUN id", ['cmake']));
    }
}
