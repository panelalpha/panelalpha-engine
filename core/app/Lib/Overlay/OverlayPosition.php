<?php

namespace App\Lib\Overlay;

/**
 * Where in an HTML page an overlay goes: just before the tag that closes the
 * body (a banner, a widget) or the head (a stylesheet, a script).
 */
enum OverlayPosition: string
{
    case BodyEnd = 'body_end';
    case HeadEnd = 'head_end';

    public function closingTag(): string
    {
        return match ($this) {
            self::BodyEnd => '</body>',
            self::HeadEnd => '</head>',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $position): string => $position->value, self::cases());
    }
}
