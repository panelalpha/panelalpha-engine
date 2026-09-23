<?php

namespace App\Console\Wizard;

use App\Console\Wizard\Sections\ApiTokensSection;
use App\Console\Wizard\Sections\EngineAddressSection;
use App\Console\Wizard\Sections\McpTokensSection;
use App\Console\Wizard\Sections\QueueSection;
use App\Console\Wizard\Sections\TelemetrySection;

/**
 * The areas `pae configure` offers — the top menu, in menu order.
 *
 * Hand-written rather than scanned: a section is listed because someone put it
 * there. Adding one is a class implementing {@see Section} and a line here.
 */
class Wizard
{
    /** @var array<int, class-string<Section>> */
    public const SECTIONS = [
        EngineAddressSection::class,
        McpTokensSection::class,
        ApiTokensSection::class,
        QueueSection::class,
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
