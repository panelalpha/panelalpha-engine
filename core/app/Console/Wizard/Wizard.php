<?php

namespace App\Console\Wizard;

use App\Console\Wizard\Sections\ApiTokensSection;
use App\Console\Wizard\Sections\EngineAddressSection;
use App\Console\Wizard\Sections\McpTokensSection;
use App\Console\Wizard\Sections\TelemetrySection;

/**
 * The areas `pae configure` knows how to configure — the top menu.
 *
 * A hand-written list rather than a scan of the directory: the order is the
 * order the menu shows, and a section is on the menu because someone put it
 * there, not because a file happened to be in the right folder.
 *
 * Adding an area is one class implementing {@see Section} and one line here.
 * What that area asks, and in what order, is the section's own business —
 * `pae configure` only gets it started and takes the exit code back.
 */
class Wizard
{
    /** @var array<int, class-string<Section>> */
    public const SECTIONS = [
        EngineAddressSection::class,
        McpTokensSection::class,
        ApiTokensSection::class,
        TelemetrySection::class,
    ];

    /**
     * @return array<string, class-string<Section>>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (self::SECTIONS as $class) {
            $sections[$class::key()] = $class;
        }

        return $sections;
    }

    /**
     * @return array<string, string> key => label, for a select prompt
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::sections() as $key => $class) {
            $labels[$key] = $class::label();
        }

        return $labels;
    }

    /**
     * @return class-string<Section>|null
     */
    public static function find(string $key): ?string
    {
        return self::sections()[strtolower(trim($key))] ?? null;
    }
}
