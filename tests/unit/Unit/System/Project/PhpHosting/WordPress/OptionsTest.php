<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\Options;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named WordPress option list/get/set operations.
 */
class OptionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_returns_decoded_options_with_default_path(): void
    {
        $sequence = [];
        $options = $this->optionsWithFakeRunner(
            $sequence,
            results: [[
                'exit_code' => 0,
                'stdout' => '[{"option_name":"siteurl","option_value":"https://example.test"},{"option_name":"blog_public","option_value":"1"}]',
                'stderr' => '',
            ]],
        );

        $body = $options->list();

        $this->assertSame(
            ['option', 'list', '--format=json', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertCount(2, $body);
        $this->assertSame('siteurl', $body[0]['option_name']);
    }

    public function test_get_and_set_pass_argv(): void
    {
        $sequence = [];
        $options = $this->optionsWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => "https://example.test\n", 'stderr' => ''],
            ],
        );

        $got = $options->get('siteurl');

        $optionsOther = $this->optionsWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => "Success: Updated 'home' option.\n", 'stderr' => ''],
            ],
            path: '/home/alice/other.test/public_html',
        );
        $set = $optionsOther->set('home', 'https://example.test');

        $this->assertSame(
            ['option', 'get', 'siteurl', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(['option' => 'siteurl', 'value' => 'https://example.test'], $got);
        $this->assertSame(
            ['option', 'update', 'home', 'https://example.test', '--path=/home/alice/other.test/public_html'],
            $sequence[1]
        );
        $this->assertSame('home', $set['option']);
        $this->assertSame('https://example.test', $set['value']);
    }

    public function test_get_nonzero_exit_throws(): void
    {
        $sequence = [];
        $options = $this->optionsWithFakeRunner(
            $sequence,
            results: [['exit_code' => 1, 'stdout' => 'Error: Could not get option.', 'stderr' => '']],
        );

        try {
            $options->get('missing');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Could not get option.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    /**
     * @param list<list<string>> $sequence
     * @param list<array{exit_code: int, stdout: string, stderr: string}> $results
     */
    private function optionsWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?string $path = null,
    ): Options {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $call = 0;
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$sequence, &$call, $results): array {
                $sequence[] = $args;
                $result = $results[$call] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                $call++;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        return new Options(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
