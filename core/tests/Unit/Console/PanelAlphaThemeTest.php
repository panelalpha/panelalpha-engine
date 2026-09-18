<?php

namespace Tests\Unit\Console;

use App\Console\Prompts\PanelAlphaTheme;
use PHPUnit\Framework\Attributes\DataProvider;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The engine's prompts are framed in orange (#61). The default renderer paints
 * the frame through a method named by the colour it is handed, so the theme is
 * only correct if `box()` asks for a colour that exists - a typo there is an
 * `Error: Call to undefined method`, not a colour that is quietly ignored.
 *
 * Rendered rather than read, because that is where the escape codes appear.
 */
class PanelAlphaThemeTest extends TestCase
{
    private const ORANGE = "\e[38;5;215m";
    private const RED = "\e[31m";
    private const YELLOW = "\e[33m";
    private const CYAN = "\e[36m";

    protected function setUp(): void
    {
        parent::setUp();

        PanelAlphaTheme::register();
    }

    /** The frame, the label and the highlighted row: the box reads as one piece. */
    public function test_the_default_state_is_orange_throughout(): void
    {
        $frame = $this->render($this->prompt());

        // The rules are written as one span, so the corner is not right after
        // the escape code - the code is, then the dashes, then the corner.
        $this->assertStringContainsString(self::ORANGE . ' ┌', $frame, 'the top-left corner must be orange');
        $this->assertStringContainsString('──┐' . "\e[39m", $frame, 'the top-right corner must be orange');
        $this->assertStringContainsString(self::ORANGE . '│', $frame, 'the side rails must be orange');
        // Same shape on the bottom rule: the code, then the corner and dashes.
        $this->assertStringContainsString(self::ORANGE . ' └', $frame, 'the bottom rule must be orange');

        // The accent: the default renderer draws the label and both markers of
        // the highlighted row in cyan, so all three follow the frame.
        $this->assertStringContainsString(self::ORANGE . 'Connect an AI agent to this engine', $frame);
        $this->assertStringContainsString(self::ORANGE . '›', $frame);
        $this->assertStringContainsString(self::ORANGE . '●', $frame);
        $this->assertStringNotContainsString(self::CYAN, $frame, 'nothing may stay cyan');
    }

    /**
     * The unselected marker stays dim, and that is the point: dim is what says
     * "not the row you are on". Colouring it would flatten the choice.
     */
    public function test_the_unselected_row_stays_dim(): void
    {
        $frame = $this->render($this->prompt());

        $this->assertStringContainsString("\e[2m○\e[22m", $frame);
        $this->assertStringContainsString("\e[2mCodex\e[22m", $frame);
    }

    /** The scrollbar handle is the renderer's accent too, so it follows. */
    public function test_the_scrollbar_handle_is_orange(): void
    {
        $prompt = new SelectPrompt(
            label: 'Connect an AI agent to this engine',
            options: ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'],
            default: 'a',
            scroll: 2, // fewer visible than there are: a scrollbar is drawn
        );

        $frame = $this->render($prompt);

        $this->assertStringContainsString(self::ORANGE . '┃', $frame);
        // The track is deliberately not the accent; it is a rule, not a marker.
        $this->assertStringContainsString("\e[90m│", $frame);
    }

    /** A prompt that failed says so in yellow; a cancelled one in red. */
    public function test_cancel_and_error_keep_their_own_frame(): void
    {
        $cancelled = $this->prompt();
        $cancelled->state = 'cancel';
        $frame = $this->render($cancelled);

        $this->assertStringContainsString(self::RED . ' ┌', $frame);
        // Cancel is the one state that carries no accent: every row is dimmed
        // and struck through, so nothing of the theme shows through.
        $this->assertStringNotContainsString(self::ORANGE, $frame);

        $failed = $this->prompt();
        $failed->state = 'error';
        $failed->error = 'something went wrong';
        $frame = $this->render($failed);

        $this->assertStringContainsString(self::YELLOW . ' ┌', $frame);
        $this->assertStringContainsString(self::YELLOW . '  ⚠ something went wrong', $frame);
        // The frame reports the failure; the markers are still the accent,
        // because the accent override is not scoped to a state. Deliberate:
        // the alternative is restating __invoke() to colour by state.
        $this->assertStringContainsString(self::ORANGE . '●', $frame);
    }

    /**
     * Nothing is left on screen after Enter.
     *
     * The package settles an answered prompt into a one-line frame and leaves
     * it there. `pae configure` is a menu an operator comes back to, so those
     * settle into a transcript of how they navigated — a twenty-five row group
     * list scrolling the menu off the top on every visit. `Prompt::render()`
     * erases the previous frame before writing the new one, so an empty submit
     * frame means the prompt vanishes and the next one draws in its place.
     */
    public function test_an_answered_prompt_leaves_nothing_behind(): void
    {
        $submitted = $this->prompt();
        $submitted->state = 'submit';

        $this->assertSame('', $this->render($submitted));
    }

    /**
     * ...for every prompt type, or a wizard that erased one question and kept
     * the next would look worse than one that kept them all.
     */
    #[DataProvider('everyPromptTypeTheWizardAsks')]
    public function test_no_prompt_type_leaves_anything_behind(callable $make): void
    {
        $prompt = $make();
        $prompt->state = 'submit';

        $render = new ReflectionMethod($prompt, 'renderTheme');

        $this->assertSame('', $render->invoke($prompt), $prompt::class . ' left a settled frame behind');
    }

    /** Cancelling still says so: only a successful answer is erased. */
    public function test_a_cancelled_prompt_still_shows(): void
    {
        $cancelled = $this->prompt();
        $cancelled->state = 'cancel';

        $this->assertStringContainsString(self::RED . ' ┌', $this->render($cancelled));
    }

    /** Registering twice is not an error: the command registers on every run. */
    public function test_registering_is_idempotent(): void
    {
        PanelAlphaTheme::register();
        PanelAlphaTheme::register();

        $this->assertSame(PanelAlphaTheme::NAME, Prompt::theme());
        $this->assertStringContainsString(self::ORANGE . ' ┌', $this->render($this->prompt()));
    }

    /**
     * The renderer is resolved in the prompt's constructor, from the theme
     * active at that moment - so setting a theme after a prompt exists does
     * nothing, which is why `register()` is called before `select()`.
     */
    public function test_a_theme_set_after_the_prompt_existed_changes_nothing(): void
    {
        $prompt = $this->prompt();

        PanelAlphaTheme::register();
        Prompt::theme('default'); // what the next prompt would see

        $this->assertStringContainsString(self::ORANGE . ' ┌', $this->render($prompt));
    }

    /**
     * The wizard asks more than one kind of question, and a run that framed
     * one prompt in orange and the next in cyan would look broken. Rendered
     * through the theme the way a prompt resolves its own renderer, so a type
     * dropped from `register()` fails here.
     */
    #[DataProvider('everyPromptTypeTheWizardAsks')]
    public function test_every_prompt_type_the_wizard_asks_is_framed_in_orange(callable $make): void
    {
        $prompt = $make();

        // Rendered the way the prompt itself renders: `renderTheme()` looks
        // the renderer up in the active theme, which is the registration this
        // is here to check.
        $render = new ReflectionMethod($prompt, 'renderTheme');
        $frame = $render->invoke($prompt);

        $this->assertStringContainsString(self::ORANGE . ' ┌', $frame, $prompt::class . ' must be framed in orange');
        $this->assertStringNotContainsString(self::CYAN, $frame, $prompt::class . ' has something left cyan');
    }

    /** @return array<string, array{0: callable}> */
    public static function everyPromptTypeTheWizardAsks(): array
    {
        return [
            'select' => [fn (): SelectPrompt => new SelectPrompt('Ceiling', ['a' => 'A', 'b' => 'B'])],
            'multiselect' => [fn (): MultiSelectPrompt => new MultiSelectPrompt('Groups', ['a' => 'A', 'b' => 'B'])],
            'confirm' => [fn (): ConfirmPrompt => new ConfirmPrompt('Write this?')],
            'text' => [fn (): TextPrompt => new TextPrompt('Tools to deny')],
        ];
    }

    private function prompt(): SelectPrompt
    {
        return new SelectPrompt(
            label: 'Connect an AI agent to this engine',
            options: ['claude' => 'Claude Code', 'codex' => 'Codex'],
            default: 'claude',
            scroll: 2,
            hint: 'Arrow keys to choose, Enter to connect',
        );
    }

    private function render(SelectPrompt $prompt): string
    {
        return (new PanelAlphaTheme($prompt))($prompt);
    }
}
