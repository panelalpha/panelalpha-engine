<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\Language;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named site-language set: success body, failure as Exception,
 * and the modern WP-CLI form `language core install {locale} --activate`.
 */
class LanguageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_set_installs_and_activates_with_default_path(): void
    {
        $sequence = [];
        $op = $this->languageWithFakeRunner(
            $sequence,
            results: [[
                'exit_code' => 0,
                'stdout' => "Language 'pl_PL' installed.\nSuccess: Language activated.\n",
                'stderr' => '',
            ]],
        );

        $body = $op->set('pl_PL');

        $this->assertSame(
            [
                'language',
                'core',
                'install',
                'pl_PL',
                '--activate',
                '--path=/home/alice/example.test/public_html',
            ],
            $sequence[0]
        );
        $this->assertSame(
            [
                'language' => 'pl_PL',
                'message' => "Language 'pl_PL' installed.\nSuccess: Language activated.",
            ],
            $body
        );
    }

    public function test_set_with_explicit_path(): void
    {
        $sequence = [];
        $op = $this->languageWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: Language activated.\n", 'stderr' => '']],
            path: '/home/alice/other.test/public_html',
        );

        $body = $op->set('de_DE');

        $this->assertSame(
            [
                'language',
                'core',
                'install',
                'de_DE',
                '--activate',
                '--path=/home/alice/other.test/public_html',
            ],
            $sequence[0]
        );
        $this->assertSame('de_DE', $body['language']);
        $this->assertSame('Success: Language activated.', $body['message']);
    }

    public function test_set_nonzero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $op = $this->languageWithFakeRunner(
            $sequence,
            results: [['exit_code' => 1, 'stdout' => "Error: Language 'xx_XX' not found.", 'stderr' => '']],
        );

        try {
            $op->set('xx_XX');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame("Error: Language 'xx_XX' not found.", $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    /**
     * @param  list<list<string>>  $sequence
     * @param  list<array{exit_code: int, stdout: string, stderr: string}>  $results
     */
    private function languageWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?string $path = null,
    ): Language {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
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

        return new Language(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
