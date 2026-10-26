<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use RuntimeException;
use Throwable;

/**
 * Why the source WordPress could not be used, as the `error_type` the test
 * endpoint answers with. The rank orders how far a check got, so a lookup over
 * several candidate URLs reports the one that came closest.
 */
final class SourceCheckFailed extends RuntimeException
{
    public const UNREACHABLE = 'unreachable';

    public const TLS = 'tls';

    public const NOT_WORDPRESS = 'not_wordpress';

    public const CLOUDFLARE = 'cloudflare';

    public const PLUGIN_NOT_FOUND = 'plugin_not_found';

    public const LOGIN_FAILED = 'login_failed';

    public const UNAUTHORIZED = 'unauthorized';

    public const DOMAIN_TAKEN = 'domain_taken';

    public const DOMAIN_REQUIRED = 'domain_required';

    private const RANKS = [
        self::UNREACHABLE => 0,
        self::TLS => 1,
        self::NOT_WORDPRESS => 2,
        self::CLOUDFLARE => 3,
        self::PLUGIN_NOT_FOUND => 3,
        self::LOGIN_FAILED => 4,
        self::UNAUTHORIZED => 4,
        self::DOMAIN_TAKEN => 5,
        self::DOMAIN_REQUIRED => 5,
    ];

    public function __construct(
        private readonly string $errorType,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unreachable(string $message, ?Throwable $previous = null): self
    {
        return new self(self::UNREACHABLE, $message, $previous);
    }

    public static function tls(string $message): self
    {
        return new self(self::TLS, $message);
    }

    public static function notWordpress(string $message): self
    {
        return new self(self::NOT_WORDPRESS, $message);
    }

    public static function cloudflare(): self
    {
        return new self(self::CLOUDFLARE, 'Source WordPress login blocked by Cloudflare');
    }

    public static function pluginNotFound(): self
    {
        return new self(
            self::PLUGIN_NOT_FOUND,
            'The wordpress-migrator plugin (wp-migrator/v1) was not found on the source site',
        );
    }

    public static function loginFailed(): self
    {
        return new self(
            self::LOGIN_FAILED,
            'Cannot connect to source WordPress: login failed or ajax nonce missing',
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            self::UNAUTHORIZED,
            'The wordpress-migrator plugin on the source site refused the migration token',
        );
    }

    /**
     * Any failure as a typed one; an untyped failure means the source was not reached.
     */
    public static function from(Throwable $e): self
    {
        return $e instanceof self ? $e : self::unreachable($e->getMessage(), $e);
    }

    /**
     * The failure that got further, the earlier one on a tie.
     */
    public static function furthest(?self $current, self $candidate): self
    {
        if ($current === null || $candidate->rank() > $current->rank()) {
            return $candidate;
        }

        return $current;
    }

    public function errorType(): string
    {
        return $this->errorType;
    }

    public function rank(): int
    {
        return self::RANKS[$this->errorType] ?? 0;
    }
}
