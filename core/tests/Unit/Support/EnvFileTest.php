<?php

namespace Tests\Unit\Support;

use App\Support\EnvFile;
use RuntimeException;
use Tests\TestCase;

/**
 * The `.env` this edits is a single-file bind mount into the core container,
 * bound by inode, and its comments are the documentation of every setting. So
 * the two things worth asserting are that a write keeps the same file and that
 * it keeps everything it was not asked to change.
 */
class EnvFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'pae-env-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . EnvFile::BACKUP_SUFFIX);

        parent::tearDown();
    }

    /**
     * The one that would break the container: a rename gives the file a new
     * inode, the bind mount keeps pointing at the old one, and the edit
     * reaches neither the host's copy nor the container's.
     */
    public function test_a_write_keeps_the_same_file(): void
    {
        $this->write("MCP_TOOLSETS=all\n");
        $before = fileinode($this->path);

        $this->env()->set(['MCP_TOOLSETS' => 'projects,domains']);

        clearstatcache();
        $this->assertSame($before, fileinode($this->path), 'the file must be rewritten in place');
    }

    public function test_it_replaces_the_value_and_leaves_the_rest_alone(): void
    {
        $this->write(<<<'ENV'
            APP_ENV=production
            # Ceiling on what any tool may do.
            MCP_PERMISSION_MODE=full
            APP_DEBUG=false

            ENV);

        $changed = $this->env()->set(['MCP_PERMISSION_MODE' => 'readonly']);

        $this->assertSame(['MCP_PERMISSION_MODE'], $changed);
        $this->assertSame(<<<'ENV'
            APP_ENV=production
            # Ceiling on what any tool may do.
            MCP_PERMISSION_MODE=readonly
            APP_DEBUG=false

            ENV, $this->read());
    }

    /**
     * The shipped `.env-core.example` documents each MCP setting above a
     * commented-out key. Setting it there keeps the value under its own
     * explanation rather than orphaning both.
     */
    public function test_it_fills_in_a_commented_placeholder(): void
    {
        $this->write("# Groups to enable.\n# MCP_TOOLSETS=\nAPP_ENV=production\n");

        $this->env()->set(['MCP_TOOLSETS' => 'projects']);

        $this->assertSame("# Groups to enable.\nMCP_TOOLSETS=projects\nAPP_ENV=production\n", $this->read());
    }

    /** A live line wins over a commented one, wherever each of them is. */
    public function test_a_live_line_is_preferred_to_a_placeholder(): void
    {
        $this->write("# MCP_TOOLSETS=\nMCP_TOOLSETS=domains\n");

        $this->env()->set(['MCP_TOOLSETS' => 'projects']);

        $this->assertSame("# MCP_TOOLSETS=\nMCP_TOOLSETS=projects\n", $this->read());
    }

    public function test_an_unknown_key_is_appended(): void
    {
        $this->write("APP_ENV=production\n\n\n");

        $this->env()->set(['MCP_TOOLS' => 'project_list']);

        $this->assertSame("APP_ENV=production\n\nMCP_TOOLS=project_list\n", $this->read());
    }

    /** A value dotenv would read as something else is quoted; a plain one is not. */
    public function test_it_quotes_only_what_needs_quoting(): void
    {
        $this->write("APP_ENV=production\n");

        $this->env()->set([
            'MCP_DENIED_TOOLS' => 'project_*,*_delete',
            'MCP_DENIED_TOOLS_REGEX' => '^(project|domain) # nope',
        ]);

        $this->assertStringContainsString('MCP_DENIED_TOOLS=project_*,*_delete', $this->read());
        $this->assertStringContainsString('MCP_DENIED_TOOLS_REGEX="^(project|domain) # nope"', $this->read());
    }

    public function test_a_quoted_value_reads_back_as_it_was_written(): void
    {
        $this->write("APP_ENV=production\n");
        $env = $this->env();

        $env->set(['MCP_DENIED_TOOLS_REGEX' => '^(project|domain) # nope']);

        $this->assertSame('^(project|domain) # nope', $env->get('MCP_DENIED_TOOLS_REGEX'));
    }

    public function test_it_reports_only_the_keys_that_moved(): void
    {
        $this->write("MCP_TOOLSETS=all\nMCP_PERMISSION_MODE=full\n");

        $changed = $this->env()->set([
            'MCP_TOOLSETS' => 'all',
            'MCP_PERMISSION_MODE' => 'readonly',
        ]);

        $this->assertSame(['MCP_PERMISSION_MODE'], $changed);
    }

    /** Nothing to change means nothing is written, so no backup is made either. */
    public function test_an_unchanged_file_is_not_rewritten(): void
    {
        $this->write("MCP_TOOLSETS=all\n");

        $this->assertSame([], $this->env()->set(['MCP_TOOLSETS' => 'all']));
        $this->assertFileDoesNotExist($this->path . EnvFile::BACKUP_SUFFIX);
    }

    /** In place means the previous contents have nowhere else to be kept. */
    public function test_the_previous_contents_are_kept_beside_the_file(): void
    {
        $this->write("MCP_PERMISSION_MODE=full\n");

        $this->env()->set(['MCP_PERMISSION_MODE' => 'readonly']);

        $this->assertSame("MCP_PERMISSION_MODE=full\n", file_get_contents($this->path . EnvFile::BACKUP_SUFFIX));
    }

    public function test_get_reports_what_is_written_down(): void
    {
        $this->write("MCP_TOOLSETS=projects\nexport MCP_TOOLS=project_list\n# MCP_DENIED_TOOLS=x\n");
        $env = $this->env();

        $this->assertSame('projects', $env->get('MCP_TOOLSETS'));
        $this->assertSame('project_list', $env->get('MCP_TOOLS'), 'an exported key is still a key');
        $this->assertNull($env->get('MCP_DENIED_TOOLS'), 'a commented key is not set');
        $this->assertNull($env->get('NOT_THERE'));
    }

    /**
     * The engine's `.env` is a single-file bind mount, and a single-file mount
     * is fragile: replace the file underneath it and the container falls back
     * to whatever is at that path instead, silently. Compose passes the
     * database credentials separately, so the engine keeps serving and every
     * setting that lived only in that file reverts to its default. A wizard
     * that reported success while writing somewhere nothing reads would be
     * worse than one that refused.
     */
    public function test_it_says_when_it_is_probably_not_the_file_the_engine_reads(): void
    {
        $this->write("TELEMETRY_TIER=1\n");

        $this->assertStringContainsString('APP_KEY', (string) $this->env()->suspicious());

        $this->write("APP_KEY=base64:abc\nTELEMETRY_TIER=1\n");

        $this->assertNull($this->env()->suspicious());
    }

    public function test_a_missing_file_is_suspicious_too(): void
    {
        unlink($this->path);

        $this->assertStringContainsString('no ', (string) $this->env()->suspicious());
    }

    public function test_it_refuses_to_invent_a_file(): void
    {
        unlink($this->path);

        $this->expectException(RuntimeException::class);

        $this->env()->set(['MCP_TOOLSETS' => 'all']);
    }

    private function env(): EnvFile
    {
        return new EnvFile($this->path);
    }

    private function write(string $contents): void
    {
        file_put_contents($this->path, $contents);
    }

    private function read(): string
    {
        return file_get_contents($this->path);
    }
}
