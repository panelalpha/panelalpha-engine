<?php

namespace Tests\Unit\Deploy\Template;

use App\Lib\Deploy\Template\Template;
use App\Lib\Deploy\Template\TemplateException;
use PHPUnit\Framework\TestCase;

/**
 * The rules every generated Dockerfile, entrypoint and helper script relies
 * on: a tag alone on its line takes the line with it, an absent value leaves
 * no blank behind, and a placeholder nobody answered is an error here rather
 * than a build failure on a host an hour later.
 */
class TemplateTest extends TestCase
{
    public function test_it_substitutes_inline_values(): void
    {
        $rendered = Template::fromString('FROM {{ image }} AS {{ stage }}')
            ->render(['image' => 'node:22', 'stage' => 'builder']);

        $this->assertSame('FROM node:22 AS builder', $rendered);
    }

    public function test_it_drops_the_line_of_an_empty_value(): void
    {
        $rendered = Template::fromString("FROM node\n{{ extra }}\nCMD x\n")
            ->render(['extra' => '']);

        $this->assertSame("FROM node\nCMD x\n", $rendered);
    }

    public function test_it_joins_a_list_value_with_newlines(): void
    {
        $rendered = Template::fromString("{{ lines }}\n")
            ->render(['lines' => ['RUN a', 'RUN b']]);

        $this->assertSame("RUN a\nRUN b\n", $rendered);
    }

    public function test_it_indents_every_line_of_a_multi_line_value(): void
    {
        $rendered = Template::fromString("if x; then\n  {{ body }}\nfi\n")
            ->render(['body' => ['echo a', 'echo b']]);

        $this->assertSame("if x; then\n  echo a\n  echo b\nfi\n", $rendered);
    }

    public function test_it_keeps_a_block_whose_value_is_present(): void
    {
        $rendered = Template::fromString("A\n{{# on }}\nB\n{{/ on }}\nC\n")
            ->render(['on' => 'yes']);

        $this->assertSame("A\nB\nC\n", $rendered);
    }

    public function test_it_removes_a_block_whose_value_is_absent(): void
    {
        $template = Template::fromString("A\n{{# on }}\nB\n{{/ on }}\nC\n");

        $this->assertSame("A\nC\n", $template->render(['on' => '']));
        $this->assertSame("A\nC\n", $template->render(['on' => null]));
        $this->assertSame("A\nC\n", $template->render(['on' => []]));
        $this->assertSame("A\nC\n", $template->render(['on' => false]));
    }

    public function test_it_inverts_a_caret_block(): void
    {
        $template = Template::fromString("{{^ prebuilt }}\nRUN apt-get update\n{{/ prebuilt }}\nCMD x\n");

        $this->assertSame("RUN apt-get update\nCMD x\n", $template->render(['prebuilt' => false]));
        $this->assertSame("CMD x\n", $template->render(['prebuilt' => true]));
    }

    public function test_it_expands_a_block_inside_a_line(): void
    {
        $template = Template::fromString('ENV {{# deployment }}BUNDLE_DEPLOYMENT=1 {{/ deployment }}BUNDLE_PATH=/x');

        $this->assertSame('ENV BUNDLE_DEPLOYMENT=1 BUNDLE_PATH=/x', $template->render(['deployment' => true]));
        $this->assertSame('ENV BUNDLE_PATH=/x', $template->render(['deployment' => false]));
    }

    public function test_it_expands_nested_blocks(): void
    {
        $template = Template::fromString("{{# outer }}\nA\n{{# inner }}\nB\n{{/ inner }}\n{{/ outer }}\nC\n");

        $this->assertSame("A\nB\nC\n", $template->render(['outer' => true, 'inner' => true]));
        $this->assertSame("A\nC\n", $template->render(['outer' => true, 'inner' => false]));
        $this->assertSame("C\n", $template->render(['outer' => false, 'inner' => true]));
    }

    public function test_it_rejects_a_placeholder_nobody_answered(): void
    {
        $this->expectException(TemplateException::class);

        Template::fromString('EXPOSE {{ port }}')->render([]);
    }

    public function test_it_reads_a_shipped_stub_by_name(): void
    {
        $this->assertStringContainsString('FROM', Template::named('dockerfile/command')->render([
            'image' => 'node:22',
            'env' => ['ENV PORT=3000'],
            'install_command' => '',
            'build_command' => '',
            'port' => 3000,
            'entrypoint' => 'panelalpha-entrypoint.sh',
        ]));
    }
}
