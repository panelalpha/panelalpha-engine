<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeExtends;
use App\Lib\Deploy\Compose\ComposeYaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Compose files Docker runs and Symfony refused, each failing the deploy with "The compose file in this
 * project could not be read as YAML": CryptPad's licence header above `---`, a blank line holding a tab,
 * and Compose's own `!reset` / `!override` merge tags under `extends:` (decocms, actvero).
 */
class ComposeYamlDockerConstructsTest extends TestCase
{
    private const LICENCE_HEADER = "# SPDX-FileCopyrightText: 2023 Someone\n#\n# SPDX-License-Identifier: AGPL-3.0-or-later\n\n---\nservices:\n  cryptpad:\n    image: cryptpad/cryptpad:latest\n";

    private const TAB_ON_BLANK_LINE = "services:\n  app:\n    image: nginx\n    volumes:\n      - ./a:/a\n\t  \n      - ./b:/b\n";

    private const RESET_UNDER_EXTENDS = <<<'YAML'
        services:
          minio:
            extends:
              file: deploy/base.yml
              service: minio
            ports: !reset []
            environment: !override
              ONLY: here
            healthcheck: !reset null
        YAML;

    private const BASE = <<<'YAML'
        services:
          minio:
            image: minio/minio
            ports:
              - "9000:9000"
            environment:
              FROM_BASE: "1"
            healthcheck:
              test: ["CMD", "true"]
            volumes:
              - data:/data
        YAML;

    /** @return array<string, array{string}> */
    public static function symfonyRefuses(): array
    {
        return [
            'comments above the document start' => [self::LICENCE_HEADER],
            'a tab on a blank line' => [self::TAB_ON_BLANK_LINE],
            'a merge tag' => [self::RESET_UNDER_EXTENDS],
        ];
    }

    #[DataProvider('symfonyRefuses')]
    public function test_symfony_alone_cannot_read_it(string $raw): void
    {
        $this->expectException(ParseException::class);
        Yaml::parse($raw);
    }

    public function test_the_document_behind_a_licence_header_is_read(): void
    {
        $parsed = ComposeYaml::parse(self::LICENCE_HEADER);

        $this->assertSame('cryptpad/cryptpad:latest', $parsed['services']['cryptpad']['image'] ?? null);
    }

    public function test_a_second_document_is_still_refused(): void
    {
        $this->assertNull(ComposeYaml::parse("# header\n---\nservices: {}\n---\nservices: {}\n"));
    }

    public function test_a_blank_line_holding_a_tab_is_blank(): void
    {
        $parsed = ComposeYaml::parse(self::TAB_ON_BLANK_LINE);

        $this->assertSame(['./a:/a', './b:/b'], $parsed['services']['app']['volumes'] ?? null);
    }

    public function test_merge_tags_give_their_values_to_a_plain_parse(): void
    {
        $parsed = ComposeYaml::parse(self::RESET_UNDER_EXTENDS);

        $this->assertSame([], $parsed['services']['minio']['ports']);
        $this->assertSame(['ONLY' => 'here'], $parsed['services']['minio']['environment']);
        $this->assertNull($parsed['services']['minio']['healthcheck']);
    }

    public function test_extends_applies_reset_and_override_as_compose_does(): void
    {
        $compose = ComposeYaml::parseKeepingMergeTags(self::RESET_UNDER_EXTENDS);
        $resolved = ComposeYaml::withoutMergeTags(
            ComposeExtends::resolve($compose, static fn (string $path): ?string => $path === 'deploy/base.yml' ? self::BASE : null)
        );
        $minio = $resolved['services']['minio'];

        $this->assertSame([], $minio['ports'], '!reset [] drops the base ports');
        $this->assertSame(['ONLY' => 'here'], $minio['environment'], '!override replaces the base map');
        $this->assertArrayNotHasKey('healthcheck', $minio, '!reset null removes the key');
        $this->assertSame(['data:/data'], $minio['volumes'], 'untagged keys still merge');
        $this->assertSame('minio/minio', $minio['image']);
    }

    public function test_a_reset_sequence_is_written_back_as_a_sequence(): void
    {
        $dumped = ComposeYaml::dump(ComposeYaml::parse(self::RESET_UNDER_EXTENDS), self::RESET_UNDER_EXTENDS, 6, 2);

        $this->assertStringContainsString('ports: []', $dumped);
    }

    public function test_a_file_symfony_reads_is_not_rewritten(): void
    {
        $raw = "---\nservices:\n  app:\n    image: nginx\n";

        $this->assertSame(Yaml::parse($raw), ComposeYaml::parse($raw));
    }
}
