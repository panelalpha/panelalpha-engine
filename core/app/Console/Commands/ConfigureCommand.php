<?php

namespace App\Console\Commands;

use App\Console\Prompts\PanelAlphaTheme;
use App\Console\Prompts\Screen;
use App\Console\Wizard\Section;
use App\Console\Wizard\Wizard;
use Illuminate\Console\Command;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;

use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;

/**
 * `pae configure` — the engine's settings, asked rather than looked up. Each
 * choice is shown with its consequence and nothing is written unreviewed.
 *
 * Two levels, both loops: this picks an area ({@see Wizard::SECTIONS}) and
 * comes back when it is done; each area runs its own menu. Naming an area on
 * the command line skips the top menu. Refuses to run without a terminal —
 * see {@see canAsk()}.
 */
class ConfigureCommand extends Command
{
    /**
     * Collected from each section. Every screen is drawn over the last, so
     * this is all the operator still has in their terminal afterwards.
     *
     * @var array<int, string>
     */
    private array $receipt = [];

    /** `pae setup` and `pae wizard`: the words operators reach for. */
    protected $aliases = ['setup', 'wizard'];

    protected $signature = 'configure
                            {section? : The area to configure; omit to choose}
                            {--dry-run : Show what would be written, and write nothing}';

    protected $description = 'Configure this engine, one question at a time';

    public function handle(): int
    {
        if (!$this->canAsk()) {
            $this->error('This is a wizard and needs a terminal to ask on.');
            $this->line('');
            $this->line('  Over SSH:      <fg=yellow>pae configure</>');
            $this->line('  In a script:   set the variables in .env directly, or run');
            $this->line('                 <fg=yellow>pae mcp:tool:list</> to check what the current ones expose.');

            return self::FAILURE;
        }

        // Before any prompt exists: a prompt resolves its renderer from the
        // theme active when it is constructed.
        PanelAlphaTheme::register();

        // Where the wizard draws, so a section that runs another command can
        // get the screen back afterwards. See Screen::remember().
        Screen::remember($this->output);

        $named = $this->argument('section');

        // Naming an area means you came for that area: no menu shown.
        if (is_string($named) && $named !== '') {
            $section = Wizard::find($named);

            if ($section === null) {
                $this->error(sprintf(
                    'There is nothing called "%s" to configure. There is: %s.',
                    $named,
                    implode(', ', array_keys(Wizard::sections()))
                ));

                return self::FAILURE;
            }

            return $this->done($this->enter($section));
        }

        return $this->menu();
    }

    /** The top level: pick an area, configure it, come back for the next. */
    private function menu(): int
    {
        $sections = Wizard::sections();

        while (true) {
            Screen::draw('Configure this engine');

            $options = Wizard::labels() + ['quit' => 'Nothing more — leave the wizard'];

            $key = (string) select(
                label: 'What would you like to configure?',
                options: $options,
                scroll: count($options),
                hint: 'Arrow keys to choose, Enter to start',
                info: static fn (string $key): string => isset($sections[$key])
                    ? $sections[$key]::hint()
                    : 'Leave everything as it is.',
            );

            if (!isset($sections[$key])) {
                return $this->done(self::SUCCESS);
            }

            $code = $this->enter($sections[$key]);

            // A failed section has said why; the menu would draw over it.
            if ($code !== self::SUCCESS) {
                return $code;
            }
        }
    }

    /**
     * @param class-string<Section> $section
     */
    private function enter(string $section): int
    {
        try {
            $run = new $section();
            $code = $run->run((bool) $this->option('dry-run'));
            $this->receipt = array_merge($this->receipt, $run->receipt());

            return $code;
        } catch (NonInteractiveValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** The last screen, and the only one nothing is drawn over. */
    private function done(int $code): int
    {
        Screen::wipe();

        if ($this->receipt === []) {
            outro('Nothing was changed.');

            return $code;
        }

        outro('Done.');
        note(implode(PHP_EOL, $this->receipt), 'info');

        return $code;
    }


    /**
     * `Laravel\Prompts` does not fail without a terminal — it returns each
     * prompt's default, so a piped run would answer every question itself and
     * write the result. Refusing is the better outcome.
     */
    private function canAsk(): bool
    {
        return $this->input->isInteractive()
            && defined('STDIN')
            && @stream_isatty(STDIN);
    }
}
