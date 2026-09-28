<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\CacheManager\NodeBuildImage;
use PHPUnit\Framework\TestCase;

class NodeBuildImageTest extends TestCase
{
    public function test_dockerfile_copies_node_into_the_toolchain_image(): void
    {
        $dockerfile = NodeBuildImage::dockerfile('gradle:9-jdk25', 'node:20-bookworm-slim');

        $this->assertSame(
            "FROM node:20-bookworm-slim AS node\n"
            . "FROM gradle:9-jdk25\n"
            . "COPY --from=node /usr/local/bin/ /usr/local/bin/\n"
            . "COPY --from=node /usr/local/lib/node_modules/ /usr/local/lib/node_modules/\n"
            . "COPY --from=node /opt/ /opt/\n"
            . "RUN node --version && npm --version\n",
            $dockerfile
        );
    }

    public function test_tag_names_the_toolchain_and_fingerprints_the_pair(): void
    {
        $tag = NodeBuildImage::tag('gradle:9-jdk25', 'node:20-bookworm-slim');

        $this->assertMatchesRegularExpression('#^panelalpha/build-node:gradle-9-jdk25-pa[0-9a-f]{8}$#', (string) $tag);
        $this->assertTrue(ImageTransfer::isSafeImageRef((string) $tag));
        $this->assertSame($tag, NodeBuildImage::tag('gradle:9-jdk25', 'node:20-bookworm-slim'));
        $this->assertNotSame($tag, NodeBuildImage::tag('gradle:9-jdk25', 'node:22-bookworm-slim'));
        $this->assertNotSame($tag, NodeBuildImage::tag('gradle:8-jdk21', 'node:20-bookworm-slim'));
    }

    public function test_registry_prefixed_image_gives_a_valid_tag(): void
    {
        $tag = NodeBuildImage::tag('registry.example.com:5000/library/maven:3-eclipse-temurin-21', 'node:20-bookworm-slim');

        $this->assertTrue(ImageTransfer::isSafeImageRef((string) $tag));
    }

    public function test_unsafe_references_are_refused(): void
    {
        $this->assertNull(NodeBuildImage::tag("gradle:9-jdk25\nRUN id", 'node:20-bookworm-slim'));
        $this->assertNull(NodeBuildImage::dockerfile('gradle:9-jdk25', '--from=x'));
        $this->assertNull(NodeBuildImage::tag('', 'node:20-bookworm-slim'));
    }
}
