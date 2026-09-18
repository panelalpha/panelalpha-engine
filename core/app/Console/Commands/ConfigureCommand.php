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
 * `pae configure` — the engine's settings, asked rather than looked up.
 *
 * Everything here can be set by hand in `.env`, and the variables are
 * documented where they live. What the wizard adds is the answer: each choice
 * is shown with its consequence, and nothing is written until the whole result
 * has been reviewed.
 *
 * Two levels, both of them loops. This one picks an area — {@see Wizard::SECTIONS},
 * of which MCP tool exposure is the first — and comes back for the next one
 * when that area is done. Each area runs its own menu of the settings it owns,
 * so an operator who came to change one thing changes that one thing. Naming
 * an area on the command line skips the top menu, because naming it is the
 * choice the menu would have asked for.
 *
 * It refuses to run without a terminal on purpose — see {@see canAsk()}.
 */
class ConfigureCommand extends Command
{
    /**
     * What the wizard did, in the lines worth keeping.
     *
     * Every screen is drawn over the last one, and clearing erases rather than
     * scrolls, so this is the whole of what the operator still has in their
     * terminal afterwards. Collected from each section as it finishes.
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

        // Naming an area means you came for that area: run it, and when it is
        // done the wizard is done. No menu asked for, none shown.
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

    /**
     * The top level: pick an area, configure it, come back for the next one.
     *
     * A loop rather than a single choice, because configuring one thing is
     * usually not the whole errand, and the alternative is running the command
     * again for each of them.
     */
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

            // A section that failed has said why; carrying on to the menu
            // would draw over it.
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

    /**
     * The last screen, and the only one nothing is drawn over.
     *
     * The wizard runs as one screen redrawn in place, which means everything
     * it showed along the way is gone by the time it exits. What the operator
     * should still be able to read off their terminal — what was written, and
     * where the file it replaced went — is said once more here.
     */
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
     * Whether a prompt can be answered here at all.
     *
     * `Laravel\Prompts` does not fail without a terminal: it returns each
     * prompt's default. A piped or `--no-interaction` run would therefore
     * answer every question in this wizard by itself and write the result,
     * which is the one outcome worse than refusing.
     */
    private function canAsk(): bool
    {
        return $this->input->isInteractive()
            && defined('STDIN')
            && @stream_isatty(STDIN);
    }
}
