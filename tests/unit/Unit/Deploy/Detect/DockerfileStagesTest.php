<?php

namespace Tests\Unit\Deploy\Detect;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Detect\DockerfileStages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Docker builds a Dockerfile's last stage. karlomikus/vue-salt-rim ends in its `dev` stage, so the deploy ran
 * `bun run dev` on 5173 and the site answered 403; its `prod` nginx stage on 8080 was never built.
 */
class DockerfileStagesTest extends TestCase
{
    /** karlomikus/vue-salt-rim's Dockerfile, the lines that matter. */
    private const SALT_RIM = <<<'DOCKERFILE'
        FROM oven/bun:1-alpine AS build
        WORKDIR /app
        RUN bun run build
        FROM docker.io/nginxinc/nginx-unprivileged:stable AS prod
        COPY --from=build /app/dist /var/www/html
        EXPOSE 8080
        CMD [ "/usr/local/bin/entrypoint" ]
        FROM oven/bun:1 AS dev
        WORKDIR /app
        EXPOSE 5173
        CMD ["bun", "run", "dev"]
        DOCKERFILE;

    public function test_a_dockerfile_ending_in_its_dev_stage_builds_the_stage_that_starts_the_app(): void
    {
        $this->assertSame('prod', DockerfileStages::productionTarget(self::SALT_RIM));
    }

    /** lylo/pagecord: `app` starts Rails, `dev` builds on it; MozillaFoundation: `base` runs gunicorn. */
    public function test_the_stage_may_have_any_name_as_long_as_it_starts_the_app(): void
    {
        $pagecord = "FROM ruby:3 AS base\nFROM base AS build\nRUN bundle install\nFROM base AS app\n"
            ."ENTRYPOINT [\"/rails/bin/docker-entrypoint\"]\nCMD [\"./bin/rails\", \"server\"]\nFROM app AS dev\nRUN bundle install\n";
        $foundation = "FROM node:22 as frontend\nRUN npm run build\nFROM python:3.11 as base\nCMD gunicorn app.wsgi\n"
            ."FROM base as dev\nCMD tail -f /dev/null\n";

        $this->assertSame('app', DockerfileStages::productionTarget($pagecord));
        $this->assertSame('base', DockerfileStages::productionTarget($foundation));
    }

    public function test_a_dockerfile_ending_in_its_production_stage_builds_the_default(): void
    {
        $this->assertNull(DockerfileStages::productionTarget(
            "FROM node:22 AS dev\nCMD npm run dev\nFROM node:22 AS prod\nCMD node server.js\n"
        ));
        $this->assertNull(DockerfileStages::productionTarget("FROM node:22\nCMD node server.js\n"));
    }

    public function test_a_dev_stage_with_no_stage_before_it_that_starts_the_app_builds_the_default(): void
    {
        $this->assertNull(DockerfileStages::productionTarget(
            "FROM debian:13 AS base\nRUN apt-get update\nFROM base AS dev\nCMD fish\n"
        ));
    }

    public function test_the_port_is_read_from_the_stage_the_deploy_builds(): void
    {
        $this->assertSame(8080, DockerfileFinder::exposedPortIn(self::SALT_RIM));
        $this->assertSame(3000, DockerfileFinder::exposedPortIn(
            "FROM node:22 AS base\nEXPOSE 8000\nFROM base AS prod\nEXPOSE 3000\nCMD node a.js\nFROM prod AS dev\nCMD npm run dev\n"
        ));
    }

    public function test_the_generated_compose_builds_that_stage(): void
    {
        $build = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 8080, ['build_target' => 'prod']))['services']['app']['build'];

        $this->assertSame(['context' => '.', 'target' => 'prod'], $build);
    }
}
