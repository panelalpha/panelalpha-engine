<?php

namespace Tests\Integration\Deploy\Health\Explainers;

use App\Lib\Deploy\Health\Explainers\StaticEntryExplainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

class StaticEntryExplainerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/static-entry-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function write(string ...$names): void
    {
        foreach ($names as $name) {
            file_put_contents($this->dir.'/'.$name, str_ends_with($name, '.html') ? '<h1>'.$name.'</h1>' : 'print(1)');
        }
    }

    /** @return array{detail: string, fix: string} */
    private function explain(): array
    {
        return (new StaticEntryExplainer)->explain(
            ProjectContext::at($this->dir),
            new ProbedResponse(404, '', 'http://'.TestNetwork::LOOPBACK.':8000/')
        );
    }

    public function test_it_names_itself(): void
    {
        $this->assertSame('static-entry', (new StaticEntryExplainer)->id());
    }

    public function test_a_project_without_documents_is_told_to_upload_them(): void
    {
        $this->write('server.py');

        $this->assertSame('There is no HTML document in ~/project at all.', $this->explain()['detail']);
    }

    public function test_an_entry_on_disk_means_the_deployed_configuration_is_stale(): void
    {
        $this->write('index.html', 'about.html');

        $this->assertSame([
            'detail' => 'The site should be served from index.html, which is in ~/project, '
                .'so the deployed configuration is pointing somewhere else.',
            'fix' => 'Redeploy the project: the running nginx configuration predates the files on disk.',
        ], $this->explain());
    }

    public function test_pages_without_an_entry_are_listed(): void
    {
        $this->write('contact.html', 'about.html', 'server.py');

        $this->assertSame([
            'detail' => 'about.html, contact.html are still in ~/project, but the document the '
                .'deployed site is served from is not.',
            'fix' => 'Restore that file, or rename one of the pages above to index.html and redeploy.',
        ], $this->explain());
    }

    public function test_one_page_past_the_named_ones_is_counted_in_the_singular(): void
    {
        $this->write('a.html', 'b.html', 'c.html', 'd.html', 'server.py');

        $this->assertStringStartsWith('a.html, b.html, c.html and 1 more page are still in ~/project', $this->explain()['detail']);
    }

    public function test_more_pages_past_the_named_ones_are_counted_in_the_plural(): void
    {
        $this->write('a.html', 'b.html', 'c.html', 'd.html', 'e.htm', 'server.py');

        $this->assertStringStartsWith('a.html, b.html, c.html and 2 more pages are still in ~/project', $this->explain()['detail']);
    }
}
