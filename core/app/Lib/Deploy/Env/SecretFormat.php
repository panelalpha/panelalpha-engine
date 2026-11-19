<?php

namespace App\Lib\Deploy\Env;

use App\Lib\Deploy\Compose\ComposePlaceholders;

/**
 * The shape a template's comment asks a secret to have: `openssl rand -hex 32` is 64 hex characters,
 * `openssl rand -base64 32` is base64 of 32 bytes, "at least 32 characters" is a minimum. An app that
 * validates its key refuses the engine's default 48 hex characters for the first two.
 *
 * No Laravel dependencies — unit-testable.
 */
final class SecretFormat
{
    private const HEX = 'hex';

    private const BASE64 = 'base64';

    private const EXACT = 'exact';

    private const MINIMUM = 'minimum';

    private const MAX_LENGTH = 256;

    private const MAX_BYTES = 128;

    /** A command that makes the value, so the value is the app's own to invent. */
    private const RECIPE_PATTERN = '/openssl\s+rand|randomBytes\s*\(|secrets\.token_|token_(?:hex|urlsafe|bytes)\s*\(|\/dev\/urandom|\bpwgen\b|\buuidgen\b|auth\s+secret|rails\s+secret|phx\.gen\.secret|Fernet\.generate_key/i';

    /** Words that make the format a check the app runs, not only advice. */
    private const REQUIREMENT_PATTERN = '/\b(must|exactly|required|requires|need|needs|at\s+least|minimum|invalid)\b|\d+\+\s*(?:characters|chars)/i';

    /** A blank the app reads as "off", which a generated value would switch on. */
    private const OPTIONAL_PATTERN = '/\boptional\b|leave\s+(?:it\s+)?(?:empty|blank|unset)|to\s+disable|if\s+(?:unset|empty|blank)/i';

    private function __construct(
        private string $kind,
        private int $size,
        private bool $required,
        private bool $recipe,
    ) {}

    /** The format the text asks for, or null when it names none. */
    public static function fromText(string $text): ?self
    {
        $required = preg_match(self::REQUIREMENT_PATTERN, $text) === 1;
        $recipe = preg_match(self::RECIPE_PATTERN, $text) === 1;
        foreach (self::readers() as $reader) {
            $format = $reader($text);
            if ($format !== null) {
                return new self($format[0], $format[1], $required, $recipe);
            }
        }

        return null;
    }

    public static function isOptional(string $text): bool
    {
        return preg_match(self::OPTIONAL_PATTERN, $text) === 1;
    }

    /** Text that tells a person how to make the value: the value is theirs, not a service's. */
    public static function hasRecipe(string $text): bool
    {
        return preg_match(self::RECIPE_PATTERN, $text) === 1;
    }

    /** The app checks the format, so a value outside it stops the app. */
    public function isRequired(): bool
    {
        return $this->required;
    }

    public function hasGenerationRecipe(): bool
    {
        return $this->recipe;
    }

    public function isSatisfiedBy(string $value): bool
    {
        return match ($this->kind) {
            self::HEX => strlen($value) === $this->size && ctype_xdigit($value),
            self::BASE64 => self::decodedLength($value) === $this->size,
            self::EXACT => strlen($value) === $this->size,
            default => strlen($value) >= $this->size,
        };
    }

    /** Seeded by name, so a redeploy gets the same value. */
    public function generate(string $name, string $seed): string
    {
        return match ($this->kind) {
            self::BASE64 => ComposePlaceholders::requiredSecretValue($name, $seed, 0, $this->size),
            self::HEX, self::EXACT => ComposePlaceholders::hexSecretOfLength($name, $seed, $this->size),
            default => ComposePlaceholders::requiredSecretValue($name, $seed, $this->size),
        };
    }

    /** For the deploy log: "64 hex characters", "base64 of 32 bytes". */
    public function describe(): string
    {
        return match ($this->kind) {
            self::HEX => "{$this->size} hex characters",
            self::BASE64 => "base64 of {$this->size} bytes",
            self::EXACT => "{$this->size} characters",
            default => "at least {$this->size} characters",
        };
    }

    /**
     * Most specific first: a command beats a description, and hex or base64 beats a plain length.
     *
     * @return list<callable(string): ?array{0: string, 1: int}>
     */
    private static function readers(): array
    {
        return [
            static fn (string $t): ?array => self::bytesAs(self::HEX, $t, '/rand\s+-hex\s+(\d+)|randomBytes\s*\(\s*(\d+)\s*\)\s*\.toString\(\s*[\'"]hex|token_hex\s*\(\s*(\d+)/i', 2),
            static fn (string $t): ?array => self::bytesAs(self::BASE64, $t, '/rand\s+-base64\s+(\d+)|randomBytes\s*\(\s*(\d+)\s*\)\s*\.toString\(\s*[\'"]base64/i', 1),
            static fn (string $t): ?array => self::bytesAs(self::MINIMUM, $t, '/token_urlsafe\s*\(\s*(\d+)/i', 1),
            static fn (string $t): ?array => self::lengthAs(self::HEX, $t, '/(\d+)[\s-]*(?:characters?|chars?)\s+(?:long\s+)?hex|(\d+)\s+hex(?:adecimal)?\s+(?:characters?|chars?|digits?)/i'),
            static fn (string $t): ?array => preg_match('/base[\s_-]?64/i', $t) === 1
                ? self::lengthAs(self::BASE64, $t, '/(\d+)[\s_-]*bytes?\b/i', self::MAX_BYTES)
                : null,
            static fn (string $t): ?array => preg_match('/\bhex\b/i', $t) === 1
                ? self::bytesAs(self::HEX, $t, '/(\d+)[\s_-]*bytes?\b/i', 2)
                : null,
            static fn (string $t): ?array => self::lengthAs(self::EXACT, $t, '/exactly\s+(\d+)\s*(?:characters|chars|bytes)|(\d+)\s*bytes?\s+long/i'),
            static fn (string $t): ?array => self::lengthAs(self::MINIMUM, $t, '/(?:at\s+least|minimum(?:\s+of)?)\s+(\d+)\s*(?:characters|chars)|(\d+)\+\s*(?:characters|chars)|(\d+)\s*(?:characters|chars)\s+or\s+(?:more|longer)/i'),
        ];
    }

    /** @return array{0: string, 1: int}|null */
    private static function bytesAs(string $kind, string $text, string $pattern, int $perByte): ?array
    {
        $bytes = self::firstNumber($text, $pattern);
        if ($bytes === null) {
            return null;
        }
        if ($kind === self::MINIMUM) {
            return [$kind, min((int) ceil($bytes * 4 / 3), self::MAX_LENGTH)];
        }

        return [$kind, $kind === self::BASE64 ? min($bytes, self::MAX_BYTES) : min($bytes * $perByte, self::MAX_LENGTH)];
    }

    /** @return array{0: string, 1: int}|null */
    private static function lengthAs(string $kind, string $text, string $pattern, int $max = self::MAX_LENGTH): ?array
    {
        $length = self::firstNumber($text, $pattern);

        return $length === null ? null : [$kind, min($length, $max)];
    }

    private static function firstNumber(string $text, string $pattern): ?int
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }
        foreach (array_slice($m, 1) as $group) {
            if ($group !== '' && (int) $group > 0) {
                return (int) $group;
            }
        }

        return null;
    }

    private static function decodedLength(string $value): int
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? -1 : strlen($decoded);
    }
}
