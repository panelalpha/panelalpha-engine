<?php

namespace App\Lib\Overlay;

/**
 * An HTML fragment the host proxy writes into every HTML page of a site.
 */
final readonly class Overlay
{
    public const int MAX_HTML_BYTES = 16384;

    public function __construct(
        public string $html,
        public OverlayPosition $position = OverlayPosition::BodyEnd,
    ) {
        if (trim($html) === '') {
            throw new \InvalidArgumentException('The overlay HTML must not be empty.');
        }
        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new \InvalidArgumentException('The overlay HTML must not be longer than '.self::MAX_HTML_BYTES.' bytes.');
        }
        if (str_contains($html, "\0")) {
            throw new \InvalidArgumentException('The overlay HTML must not contain a NUL byte.');
        }
    }

    /**
     * A stored overlay, or null when what is stored is not a usable one.
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (! is_array($stored) || ! is_string($stored['html'] ?? null)) {
            return null;
        }

        $position = OverlayPosition::tryFrom((string) ($stored['position'] ?? '')) ?? OverlayPosition::BodyEnd;
        try {
            return new self($stored['html'], $position);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * @return array{html: string, position: string}
     */
    public function toArray(): array
    {
        return [
            'html' => $this->html,
            'position' => $this->position->value,
        ];
    }
}
