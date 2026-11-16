<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\OperatorBaseImages;
use PHPUnit\Framework\TestCase;

/**
 * Which operator images a deploy fetches before compose builds: the ones its
 * build services' Dockerfiles start FROM, and no others.
 */
class OperatorBaseImagesTest extends TestCase
{
    private const COMPOSE_PATH = '/home/demo/project/docker-compose.panelalpha.yml';

    private const OPERATOR = ['whmcs-dev-base:php8.3', 'tools:1'];

    public function test_every_literal_base_is_named_but_stages_scratch_and_args_are_not(): void
    {
        $dockerfile = <<<'DOCKERFILE'
            ARG BASE=debian:12
            FROM --platform=linux/amd64 whmcs-dev-base:php8.3 AS base
            RUN echo ok
            FROM base AS app
            FROM ${BASE}
            FROM scratch
            COPY <<EOF /x
            FROM tools:1
            EOF
            FROM node:22 as assets
            DOCKERFILE;

        $this->assertSame(['whmcs-dev-base:php8.3', 'node:22'], OperatorBaseImages::fromRefs($dockerfile));
    }

    public function test_a_build_service_from_an_operator_image_needs_it(): void
    {
        $compose = <<<'YAML'
            services:
              app:
                build:
                  context: .
                  dockerfile: docker/Dockerfile
              db:
                image: mysql:8
            YAML;

        $needed = OperatorBaseImages::neededBy(self::COMPOSE_PATH, $compose, self::OPERATOR, $this->files([
            '/home/demo/project/docker/Dockerfile' => "FROM docker.io/library/whmcs-dev-base:php8.3\nRUN true\n",
        ]));

        $this->assertSame(['whmcs-dev-base:php8.3'], $needed);
    }

    public function test_short_and_inline_builds_are_read_too(): void
    {
        $compose = <<<'YAML'
            services:
              web:
                build: .
              worker:
                build:
                  dockerfile_inline: |
                    FROM tools:1
            YAML;

        $needed = OperatorBaseImages::neededBy(self::COMPOSE_PATH, $compose, self::OPERATOR, $this->files([
            '/home/demo/project/Dockerfile' => "FROM whmcs-dev-base:php8.3\n",
        ]));

        $this->assertSame(['whmcs-dev-base:php8.3', 'tools:1'], $needed);
    }

    public function test_a_service_compose_will_not_start_is_skipped(): void
    {
        $compose = <<<'YAML'
            services:
              debug:
                profiles: [debug]
                build: .
            YAML;

        $needed = OperatorBaseImages::neededBy(self::COMPOSE_PATH, $compose, self::OPERATOR, $this->files([
            '/home/demo/project/Dockerfile' => "FROM whmcs-dev-base:php8.3\n",
        ]), []);

        $this->assertSame([], $needed);
    }

    public function test_a_dockerfile_outside_the_project_is_never_read(): void
    {
        $compose = <<<'YAML'
            services:
              app:
                build:
                  context: .
                  dockerfile: /etc/Dockerfile
            YAML;
        $read = [];

        $needed = OperatorBaseImages::neededBy(self::COMPOSE_PATH, $compose, self::OPERATOR, function (string $path) use (&$read): ?string {
            $read[] = $path;

            return "FROM whmcs-dev-base:php8.3\n";
        });

        $this->assertSame([], $needed);
        $this->assertSame([], $read);
    }

    public function test_nothing_is_read_without_operator_images(): void
    {
        $needed = OperatorBaseImages::neededBy(self::COMPOSE_PATH, "services:\n  app:\n    build: .\n", [], function (): ?string {
            $this->fail('nothing to look for, so nothing to read');
        });

        $this->assertSame([], $needed);
    }

    /**
     * @param  array<string, string>  $files
     * @return callable(string): ?string
     */
    private function files(array $files): callable
    {
        return static fn (string $path): ?string => $files[$path] ?? null;
    }
}
