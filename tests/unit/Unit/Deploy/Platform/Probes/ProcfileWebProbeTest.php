<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\ProcfileWebProbe;

/**
 * A Procfile that names a web process — and only then.
 *
 * A Procfile declaring only `worker:` or `release:` gives nothing to serve.
 * Claiming the project on the strength of the filename alone produces a
 * container that builds and then exits.
 */
class ProcfileWebProbeTest extends ProbeTestCase
{
    private function probe(): ProcfileWebProbe
    {
        return new ProcfileWebProbe();
    }

    public function test_the_web_command_is_contributed(): void
    {
        $this->write('Procfile', "web: bundle exec puma -p \$PORT\n");

        $this->assertSame(
            ['procfile_web' => 'bundle exec puma -p $PORT'],
            $this->probe()->evaluate($this->context())
        );
    }

    public function test_the_web_line_is_found_among_others(): void
    {
        $this->write('Procfile', <<<'PROC'
        release: bundle exec rake db:migrate
        web: bundle exec puma -C config/puma.rb
        worker: bundle exec sidekiq
        PROC);

        $this->assertSame(
            'bundle exec puma -C config/puma.rb',
            $this->probe()->evaluate($this->context())['procfile_web']
        );
    }

    public function test_a_procfile_with_no_web_process_is_no_match(): void
    {
        $this->write('Procfile', "worker: bundle exec sidekiq\nrelease: rake db:migrate\n");

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_an_empty_web_command_is_no_match(): void
    {
        $this->write('Procfile', "web:\n");

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_no_procfile_is_no_match(): void
    {
        $this->write('package.json', '{}');

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }
}
