<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WordPress;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Services\Webserver;
use Mockery;

/** A WordPress install in memory: answers the WP-CLI config, option, search-replace, db query and cache calls it receives. */
final class FakeWordPressSite
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var list<string> SQL received by `wp db query` */
    public array $queries = [];

    /** What a SELECT through `wp db query` answers: the network's siteurl row. */
    public string $networkSiteUrl = '';

    /** What `core is-installed` answers: an installed site, one that is not, or no WordPress at all. */
    public bool|\RuntimeException $installed = true;

    /**
     * @param array<string, mixed>  $constants wp-config.php
     * @param array<string, string> $options   the database
     * @param array<string, string> $variables wp-config.php, such as table_prefix
     */
    public function __construct(public array $constants, public array $options, public array $variables = [])
    {
    }

    public function wordPress(): WordPress
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'site';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/site');
        $project->shouldReceive('runWpCli')->andReturnUsing(fn (array $args): array => $this->answer($args));
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn('/site.test/public_html');
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($domain);

        return new WordPress($user, WpCli::for($user, system: $this->nginxHost(), skipPlugins: true));
    }

    /** @return list<list<string>> the search-replace calls, in order */
    public function searchReplaces(): array
    {
        return array_values(array_filter($this->calls, static fn (array $args): bool => $args[0] === 'search-replace'));
    }

    private function nginxHost(): System
    {
        $webserver = Mockery::mock(Webserver::class);
        $webserver->shouldReceive('getCurrentWebserver')->andReturn('nginx');
        $system = Mockery::mock(System::class);
        $system->shouldReceive('webserver')->andReturn($webserver);

        return $system;
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function answer(array $args): array
    {
        $this->calls[] = $args;
        $words = array_values(array_filter($args, static fn (string $arg): bool => !str_starts_with($arg, '--')));

        return match ([$words[0] ?? '', $words[1] ?? '']) {
            ['config', 'get'] => $this->read($this->constants, $words[2]),
            ['config', 'set'] => $this->write($this->constants, $words[2], $words[3]),
            ['config', 'list'] => $this->ok((string) json_encode($this->constantRows())),
            ['option', 'get'] => $this->read($this->options, $words[2]),
            ['option', 'update'] => $this->write($this->options, $words[2], $words[3]),
            ['search-replace', $words[1] ?? ''] => $this->searchReplace($words[1], $words[2], $args),
            ['db', 'query'] => $this->query($words[2]),
            ['cache', 'flush'] => $this->ok('Success: The cache was flushed.'),
            ['core', 'is-installed'] => $this->isInstalled(),
            default => ['exit_code' => 1, 'stdout' => '', 'stderr' => 'unexpected: ' . implode(' ', $args)],
        };
    }

    /** @return list<array{name: string, value: mixed, type: string}> */
    private function constantRows(): array
    {
        $rows = [];
        foreach ($this->constants as $name => $value) {
            $rows[] = ['name' => $name, 'value' => $value, 'type' => 'constant'];
        }
        foreach ($this->variables as $name => $value) {
            $rows[] = ['name' => $name, 'value' => $value, 'type' => 'variable'];
        }

        return $rows;
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function query(string $sql): array
    {
        $this->queries[] = $sql;

        return $this->ok(str_starts_with($sql, 'SELECT') && $this->networkSiteUrl !== '' ? $this->networkSiteUrl . "\n" : '');
    }

    /**
     * @param array<string, string> $store
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function read(array $store, string $name): array
    {
        return isset($store[$name])
            ? $this->ok($store[$name] . "\n")
            : ['exit_code' => 1, 'stdout' => '', 'stderr' => "Error: '{$name}' not found."];
    }

    /**
     * @param array<string, string> $store
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function write(array &$store, string $name, string $value): array
    {
        $store[$name] = $value;

        return $this->ok('Success');
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function searchReplace(string $search, string $replacement, array $args): array
    {
        $delimiter = $this->flagValue($args, '--regex-delimiter=') ?? '/';
        foreach ($this->options as $name => $value) {
            $this->options[$name] = in_array('--regex', $args, true)
                ? (string) preg_replace($delimiter . $search . $delimiter, $replacement, $value)
                : str_replace($search, $replacement, $value);
        }

        return $this->ok('Success');
    }

    /** @param list<string> $args */
    private function flagValue(array $args, string $flag): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $flag)) {
                return substr($arg, strlen($flag));
            }
        }

        return null;
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function isInstalled(): array
    {
        if ($this->installed instanceof \RuntimeException) {
            throw $this->installed;
        }

        return $this->installed ? $this->ok('') : ['exit_code' => 1, 'stdout' => '', 'stderr' => ''];
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function ok(string $stdout): array
    {
        return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => ''];
    }
}
