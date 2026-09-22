<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Which Node projects need git in their image.
 *
 * The slim image has no git, and the host-compile path cannot apt-get one, so
 * getting this wrong is a failed deploy. It failed in two ways at once on
 * tine (supported-apps#1220), one deploy at 149 s:
 *
 *   the detector required the subcommand to follow `git` immediately, so
 *   `git -C /app submodule update` did not match -- and `-C` and
 *   `-c safe.directory=` are the engine's *own* idiom, spelled twice in
 *   System/Project/Git.php, so a recipe author who copies how the engine
 *   invokes git writes exactly the form it could not see;
 *
 *   and ten of tine's dependencies resolve to `git+ssh://` in
 *   npm-shrinkwrap.json, so npm clones before any script of the project's own
 *   runs. No script mentions git at all.
 *
 * Either way the build died as `npm error syscall spawn git` /
 * `git dep preparation failed` -- a message naming npm, not the missing
 * binary and not the image.
 */
class NodeGitBinaryDetectionTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/node-git-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmpDir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->tmpDir . '/' . $name, $contents);
    }

    private function needsGit(string $script): bool
    {
        $this->write('package.json', (string) json_encode(['scripts' => ['build' => $script]]));

        return NodeRuntime::needsGitBinary(ProjectContext::at($this->tmpDir));
    }

    /** The forms the old anchor missed. Both are Git.php's own spelling. */
    public function test_a_global_option_before_the_subcommand_still_counts(): void
    {
        $this->assertTrue($this->needsGit('git -C /app submodule update --init'));
        $this->assertTrue($this->needsGit('git -c safe.directory=/app submodule update'));
        $this->assertTrue($this->needsGit('git -c safe.directory=/app -C /app rev-parse HEAD'));
        $this->assertTrue($this->needsGit('git --no-pager log -1 --format=%h'));
        $this->assertTrue($this->needsGit('git --git-dir=/app/.git describe --tags'));
    }

    /** What already worked, and has to keep working. */
    public function test_the_plain_form_is_unchanged(): void
    {
        $this->assertTrue($this->needsGit('git rev-parse --short HEAD'));
        $this->assertTrue($this->needsGit("node -e \"require('child_process').execSync('git log -1')\""));
        $this->assertTrue($this->needsGit('vite build && git describe --tags'));
    }

    /**
     * The subcommand anchor is the whole reason the rule is not `/git/i`, and
     * widening the prefix must not cost it.
     */
    public function test_the_word_git_alone_is_still_not_an_invocation(): void
    {
        $this->assertFalse($this->needsGit('node digit-check.js'));
        $this->assertFalse($this->needsGit('husky install'));
        $this->assertFalse($this->needsGit('cp .gitignore dist/'));
        $this->assertFalse($this->needsGit('vite build'));
        // An option with no subcommand after it is not one either.
        $this->assertFalse($this->needsGit('git --version'));
    }

    /** tine's actual signal: npm clones, no script says so. */
    public function test_a_dependency_that_resolves_over_git_counts(): void
    {
        $this->write('package.json', (string) json_encode([
            'dependencies' => ['some-lib' => 'git+ssh://git@github.com/acme/some-lib.git#v1'],
            'scripts' => ['build' => 'vite build'],
        ]));

        $this->assertTrue(NodeRuntime::needsGitBinary(ProjectContext::at($this->tmpDir)));
    }

    /** A transitive one appears only in the lockfile. */
    public function test_a_git_url_in_the_lockfile_counts(): void
    {
        $this->write('package.json', (string) json_encode([
            'dependencies' => ['some-lib' => '^1.0.0'],
            'scripts' => ['build' => 'vite build'],
        ]));
        $this->write('npm-shrinkwrap.json', (string) json_encode([
            'packages' => [
                'node_modules/nested' => ['resolved' => 'git+ssh://git@github.com/acme/nested.git#abc'],
            ],
        ]));

        $this->assertTrue(NodeRuntime::needsGitBinary(ProjectContext::at($this->tmpDir)));
    }

    /** And an ordinary registry install still gets the slim image. */
    public function test_a_registry_only_project_does_not_pay_for_it(): void
    {
        $this->write('package.json', (string) json_encode([
            'dependencies' => ['express' => '4.21.0'],
            'scripts' => ['build' => 'vite build'],
        ]));
        $this->write('package-lock.json', (string) json_encode([
            'packages' => [
                'node_modules/express' => ['resolved' => 'https://registry.npmjs.org/express/-/express-4.21.0.tgz'],
            ],
        ]));

        $this->assertFalse(NodeRuntime::needsGitBinary(ProjectContext::at($this->tmpDir)));
    }
}
