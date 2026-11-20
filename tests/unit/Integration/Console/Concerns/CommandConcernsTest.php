<?php

namespace Tests\Integration\Console\Concerns;

use App\Console\Commands\Concerns\PrintsOverlayState;
use App\Console\Commands\Concerns\ReadsOperatorImageBuild;
use App\Console\Commands\Concerns\ReadsOverlayOptions;
use App\Console\Commands\Concerns\StreamsFileToOutput;
use App\Exceptions\ProblemException;
use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayPosition;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** The traits commands share: streaming a file out, printing overlay state, and reading overlay and image-build options. */
class CommandConcernsTest extends TestCase
{
    private string $dir;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-cmd-concerns-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->output = new BufferedOutput;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $input */
    private function wire(Command $command, array $input): Command
    {
        $command->setLaravel($this->app);
        $arrayInput = new ArrayInput($input, $command->getDefinition());
        $command->setInput($arrayInput);
        $command->setOutput(new OutputStyle($arrayInput, $this->output));

        return $command;
    }

    private function streamer(): Command
    {
        return $this->wire(new class extends Command {
            use StreamsFileToOutput;

            protected $signature = 'concern:stream';

            public function stream(string $source, ?string $out): int
            {
                return $this->streamFile($source, $out);
            }
        }, []);
    }

    public function test_a_file_is_saved_where_asked_with_its_size(): void
    {
        file_put_contents($this->dir.'/access.log', str_repeat('x', 1500));

        $this->assertSame(0, $this->streamer()->stream($this->dir.'/access.log', $this->dir.'/copy.log'));

        $this->assertSame(str_repeat('x', 1500), file_get_contents($this->dir.'/copy.log'));
        $this->assertStringContainsString('Saved to '.$this->dir.'/copy.log (1,500 bytes)', $this->output->fetch());
    }

    public function test_without_a_target_the_bytes_go_to_stdout_unchanged(): void
    {
        file_put_contents($this->dir.'/access.log', "line one\nline two\n");

        ob_start();
        $code = $this->streamer()->stream($this->dir.'/access.log', null);
        $printed = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertSame("line one\nline two\n", $printed);
    }

    public function test_an_unwritable_target_is_reported(): void
    {
        file_put_contents($this->dir.'/access.log', 'x');

        $code = @$this->streamer()->stream($this->dir.'/access.log', $this->dir.'/missing/copy.log');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Could not open '.$this->dir.'/missing/copy.log for writing', $this->output->fetch());
    }

    /** @param array<string, mixed> $input */
    private function overlayPrinter(array $input): Command
    {
        return $this->wire(new class extends Command {
            use PrintsOverlayState;

            protected $signature = 'concern:overlay {--json}';

            /** @param array<string, mixed> $state */
            public function print(array $state): int
            {
                return $this->printOverlayState($state);
            }
        }, $input);
    }

    public function test_overlay_state_is_flattened_into_setting_rows(): void
    {
        $state = ['enabled' => true, 'overlay' => ['position' => 'body_end', 'html' => null, 'inherited' => false], 'bytes' => 42];

        $this->assertSame(0, $this->overlayPrinter([])->print($state));

        $table = $this->output->fetch();
        $this->assertMatchesRegularExpression('/enabled\s+\|\s+yes/', $table);
        $this->assertMatchesRegularExpression('/overlay\.position\s+\|\s+body_end/', $table);
        $this->assertMatchesRegularExpression('/overlay\.html\s+\|\s+none/', $table);
        $this->assertMatchesRegularExpression('/overlay\.inherited\s+\|\s+no/', $table);
        $this->assertMatchesRegularExpression('/bytes\s+\|\s+42/', $table);
    }

    public function test_overlay_state_as_json(): void
    {
        $this->overlayPrinter(['--json' => true])->print(['enabled' => false, 'overlay' => ['position' => 'head_end']]);

        $this->assertSame(['enabled' => false, 'overlay' => ['position' => 'head_end']], json_decode($this->output->fetch(), true));
    }

    /** @param array<string, mixed> $input */
    private function overlayReader(array $input): Command
    {
        return $this->wire(new class extends Command {
            use ReadsOverlayOptions;

            protected $signature = 'concern:read-overlay {--position=body_end} {--html=} {--file=}';

            public function read(): Overlay
            {
                return $this->overlayOptions();
            }
        }, $input);
    }

    public function test_overlay_html_is_read_from_a_file(): void
    {
        file_put_contents($this->dir.'/banner.html', '<div>Maintenance tonight</div>');

        $overlay = $this->overlayReader(['--file' => $this->dir.'/banner.html', '--position' => 'head_end'])->read();

        $this->assertSame('<div>Maintenance tonight</div>', $overlay->html);
        $this->assertSame(OverlayPosition::HeadEnd, $overlay->position);
    }

    public function test_overlay_options_refuse_an_unknown_position_or_unreadable_file(): void
    {
        try {
            $this->overlayReader(['--position' => 'footer', '--html' => '<b>x</b>'])->read();
            $this->fail('An unknown position was accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('--position must be one of: '.implode(', ', OverlayPosition::values()).'.', $e->getMessage());
        }

        $this->expectExceptionMessage("Cannot read '{$this->dir}/none.html'.");
        $this->overlayReader(['--file' => $this->dir.'/none.html'])->read();
    }

    /** @param array<string, mixed> $input */
    private function imageBuild(array $input): Command
    {
        return $this->wire(new class extends Command {
            use ReadsOperatorImageBuild;

            protected $signature = 'concern:image {--build-arg=*}';

            /** @return array<string, string> */
            public function args(): array
            {
                return $this->buildArgOptions();
            }

            public function write(string $chunk): void
            {
                $this->writeOutput($chunk);
            }
        }, $input);
    }

    public function test_build_args_are_split_at_the_first_equals_sign(): void
    {
        $command = $this->imageBuild(['--build-arg' => ['PHP=8.3', 'FLAGS=a=b', 'EMPTY=']]);

        $this->assertSame(['PHP' => '8.3', 'FLAGS' => 'a=b', 'EMPTY' => ''], $command->args());

        $command->write("<b>Step 1/3</b>\n");
        $this->assertSame("<b>Step 1/3</b>\n", $this->output->fetch());
    }

    public function test_a_build_arg_without_a_name_is_refused(): void
    {
        foreach (['NOVALUE', '=value'] as $pair) {
            try {
                $this->imageBuild(['--build-arg' => [$pair]])->args();
                $this->fail("'{$pair}' was accepted.");
            } catch (ProblemException $e) {
                $this->assertSame("--build-arg takes NAME=value, not '{$pair}'.", $e->getMessage());
            }
        }
    }
}
