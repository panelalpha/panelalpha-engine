<?php

namespace Tests\Unit\Console;

use App\Console\Wizard\Section;
use App\Console\Wizard\Sections\McpSection;
use App\Console\Wizard\Wizard;
use Tests\TestCase;

/**
 * The menu behind `pae configure`. A section is reachable by the name it is
 * run with, so the two things that matter are that the names are unique and
 * that the one the operator types finds the section they meant.
 */
class WizardTest extends TestCase
{
    public function test_every_section_is_a_section(): void
    {
        foreach (Wizard::SECTIONS as $class) {
            $this->assertTrue(
                is_subclass_of($class, Section::class),
                "{$class} is on the menu but does not implement Section"
            );
        }
    }

    /** Two sections claiming one key would silently hide one of them. */
    public function test_the_keys_are_unique_and_speakable(): void
    {
        $this->assertCount(count(Wizard::SECTIONS), Wizard::sections());

        foreach (array_keys(Wizard::sections()) as $key) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $key, 'a key is typed at a shell');
        }
    }

    public function test_it_finds_a_section_by_name(): void
    {
        $this->assertSame(McpSection::class, Wizard::find('mcp'));
        $this->assertSame(McpSection::class, Wizard::find('  MCP '));
        $this->assertNull(Wizard::find('nothing-like-this'));
    }

    public function test_the_labels_are_the_menu(): void
    {
        $this->assertSame(array_keys(Wizard::sections()), array_keys(Wizard::labels()));

        foreach (Wizard::labels() as $label) {
            $this->assertNotSame('', trim($label));
        }
    }

    /**
     * Every screen is drawn over the last one and clearing erases rather than
     * scrolls, so the receipt is the whole of what an operator still has in
     * their terminal when the wizard exits. A section that has not run has
     * nothing to report, which is what `pae configure` reads as "nothing was
     * changed".
     */
    public function test_a_section_that_has_not_run_reports_nothing(): void
    {
        foreach (Wizard::SECTIONS as $class) {
            $this->assertSame([], (new $class())->receipt(), "{$class} reports something before it has run");
        }
    }

    /**
     * `Laravel\Prompts` answers with each prompt's default when there is no
     * terminal, so a piped run would answer the whole wizard by itself and
     * write the result. It has to refuse instead.
     */
    public function test_it_refuses_to_run_without_a_terminal(): void
    {
        $this->artisan('configure')
            ->expectsOutputToContain('needs a terminal')
            ->assertExitCode(1);
    }
}
