<?php

namespace Tests\Integration\Deploy\Health\Explainers;

use App\Lib\Deploy\Health\Explainers\PhpDocrootExplainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

class PhpDocrootExplainerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/php-docroot-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function touch(string $relative): void
    {
        @mkdir(dirname($this->dir.'/'.$relative), 0777, true);
        file_put_contents($this->dir.'/'.$relative, '<?php');
    }

    /** @return array{detail: string, fix: string} */
    private function explain(): array
    {
        return (new PhpDocrootExplainer)->explain(
            ProjectContext::at($this->dir),
            new ProbedResponse(200, '<?php echo 1;', 'http://'.TestNetwork::LOOPBACK.':8000/')
        );
    }

    public function test_it_names_itself(): void
    {
        $this->assertSame('php-docroot', (new PhpDocrootExplainer)->id());
    }

    public function test_a_root_index_served_as_text_is_the_interpreter_not_the_docroot(): void
    {
        $this->touch('index.php');

        $this->assertSame([
            'detail' => 'index.php is at the project root and is being served rather than run.',
            'fix' => 'The container is not handing .php files to the interpreter; redeploy the project.',
        ], $this->explain());
    }

    public function test_an_entry_point_below_the_root_names_its_folder(): void
    {
        $this->touch('web/index.php');
        $this->touch('index.php');

        $this->assertSame(
            'The entry point is web/index.php, so the document root should be web rather than the project root.',
            $this->explain()['detail']
        );
    }

    public function test_no_index_anywhere_usual_says_so(): void
    {
        $this->touch('src/App.php');

        $this->assertSame([
            'detail' => 'No index.php was found in the usual places, so what is being served is some other PHP file.',
            'fix' => 'Check that the application\'s entry point is where the platform expects it.',
        ], $this->explain());
    }
}
