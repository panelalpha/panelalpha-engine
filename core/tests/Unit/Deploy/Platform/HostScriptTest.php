<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\HostScript;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\TestCase;

/**
 * Host-side stages: what runs in the account's shell rather than a container.
 */
class HostScriptTest extends TestCase
{
    /** @param list<array<string, mixed>> $commands */
    private function manifest(array $commands): PlatformManifest
    {
        return PlatformManifest::fromArray([
            'id' => 'demo',
            'label' => 'Demo',
            'priority' => 1,
            'runtime' => 'command',
            'detect' => ['file' => 'demo.json'],
            'commands' => $commands,
        ]);
    }

    public function test_it_renders_a_stages_commands_in_manifest_order(): void
    {
        $script = HostScript::render(
            $this->manifest([
                ['id' => 'one', 'stage' => 'prepare', 'run' => 'echo one'],
                ['id' => 'two', 'stage' => 'prepare', 'run' => 'echo two'],
            ]),
            PlatformStage::PREPARE
        );

        $this->assertLessThan(strpos($script, 'echo two'), strpos($script, 'echo one'));
    }

    /**
     * The manifest describes the kind of application; the app config knows this
     * one, so it runs last and can rely on what came before.
     */
    public function test_app_config_commands_run_after_the_manifests(): void
    {
        $script = HostScript::render(
            $this->manifest([['id' => 'generate', 'stage' => 'prepare', 'run' => 'echo manifest']]),
            PlatformStage::PREPARE,
            null,
            [],
            ['app-config-setup' => 'echo app-config']
        );

        $this->assertLessThan(strpos($script, 'echo app-config'), strpos($script, 'echo manifest'));
    }

    /**
     * A precheck that pipes into awk must fail when any part of the pipeline
     * fails, or a disk-space guard passes because the last command in the
     * pipe happened to succeed.
     */
    public function test_the_script_fails_on_a_broken_pipeline(): void
    {
        $script = HostScript::render(null, PlatformStage::PRECHECK, null, [], ['x' => 'true']);

        $this->assertStringContainsString('set -euo pipefail', $script);
    }

    /**
     * App config bodies are third-party scripts written against `set -e`. The
     * generated commands keep the strict mode; borrowed ones keep theirs, or
     * an unset variable starts failing a deploy that used to work.
     */
    public function test_app_config_bodies_run_under_the_semantics_they_were_written_for(): void
    {
        $script = HostScript::render(
            null,
            PlatformStage::PREPARE,
            null,
            [],
            ['app-config-setup' => "PASS=\$(openssl rand -hex 8)\necho \"\$PASS\""]
        );

        $this->assertStringContainsString('set +u +o pipefail', $script);
        // …and inside a subshell, so it cannot relax the rest of the script.
        $lines = explode("\n", $script);
        $at = array_search('set +u +o pipefail', $lines, true);
        $this->assertIsInt($at);
        $this->assertSame('(', $lines[$at - 1]);
        $this->assertContains(')', array_slice($lines, $at));
    }

    /**
     * A heredoc terminator has to sit at column 0. Indenting a borrowed body
     * for readability breaks every app config that writes a config file with one
     * — two of the shipped pages do.
     */
    public function test_a_borrowed_body_with_a_heredoc_stays_valid_shell(): void
    {
        $body = "cat > .env <<'EOF'\nAPP_KEY=abc\nEOF\necho done";

        $script = HostScript::render(null, PlatformStage::PREPARE, null, [], ['app-config-setup' => $body]);

        $this->assertStringContainsString("\nEOF\n", $script);
        $this->assertStringNotContainsString("\n  EOF\n", $script);

        $tmp = tempnam(sys_get_temp_dir(), 'hostscript');
        file_put_contents($tmp, $script);
        exec('bash -n ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        unlink($tmp);

        $this->assertSame(0, $code, 'generated script is not valid bash: ' . implode("\n", $output));
    }

    /**
     * A folded multi-line description must be commented on every line, or its
     * later lines land in the host script as bare shell (engine#198).
     */
    public function test_a_multiline_description_is_fully_commented(): void
    {
        $script = HostScript::render(
            $this->manifest([[
                'id' => 'prep',
                'stage' => 'prepare',
                'run' => 'echo prep',
                'description' => "Prepare the account.\n\nRuns once per deploy.",
            ]]),
            PlatformStage::PREPARE
        );

        $this->assertStringContainsString("# Prepare the account.\n# \n# Runs once per deploy.", $script);

        $tmp = tempnam(sys_get_temp_dir(), 'hostscript');
        file_put_contents($tmp, $script);
        exec('bash -n ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        unlink($tmp);

        $this->assertSame(0, $code, 'generated script is not valid bash: ' . implode("\n", $output));
    }

    public function test_a_container_stage_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HostScript::render($this->manifest([]), PlatformStage::START);
    }

    public function test_is_empty_reports_when_a_stage_has_nothing_to_run(): void
    {
        $manifest = $this->manifest([['id' => 'x', 'stage' => 'prepare', 'run' => 'echo x']]);

        $this->assertTrue(HostScript::isEmpty(null, PlatformStage::PREPARE));
        $this->assertFalse(HostScript::isEmpty(null, PlatformStage::PREPARE, null, ['a' => 'echo a']));
        $this->assertFalse(HostScript::isEmpty($manifest, PlatformStage::PREPARE));
        $this->assertTrue(HostScript::isEmpty($manifest, PlatformStage::PRECHECK));
    }

    public function test_optional_and_timeout_behave_as_they_do_in_the_entrypoint(): void
    {
        $script = HostScript::render(
            $this->manifest([
                ['id' => 'soft', 'stage' => 'prepare', 'run' => 'echo soft', 'optional' => true],
                ['id' => 'slow', 'stage' => 'prepare', 'run' => 'echo slow', 'timeout' => 30],
            ]),
            PlatformStage::PREPARE
        );

        $this->assertStringContainsString("echo soft || pa_skip prepare 'soft'", $script);
        $this->assertStringContainsString("timeout --foreground 30 bash -c 'echo slow'", $script);
    }

    /**
     * The two scripts must not overlap: a host command in the entrypoint would
     * run on every container boot, and a container command on the host would
     * run before the image it needs exists.
     */
    public function test_host_stages_never_reach_the_container_entrypoint(): void
    {
        $manifest = $this->manifest([
            ['id' => 'prep', 'stage' => 'prepare', 'run' => 'echo on-host'],
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]);

        $this->assertStringNotContainsString('echo on-host', StageScript::render($manifest));
    }

    /**
     * Detection reads the source, and at precheck time the source has not been
     * cloned — so a shipped manifest declaring precheck commands has written
     * something that can never run.
     */
    public function test_no_shipped_manifest_declares_precheck_commands(): void
    {
        foreach (PlatformRegistry::all() as $manifest) {
            $this->assertSame(
                [],
                $manifest->stage(PlatformStage::PRECHECK),
                "Manifest {$manifest->id} declares precheck commands, which run before detection has happened"
            );
        }
    }
}
