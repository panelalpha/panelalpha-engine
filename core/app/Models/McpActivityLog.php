<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'token_id',
        'token_name',
        'project',
        'tool_name',
        'input',
        'status',
        'error_message',
    ];

    protected $casts = [
        'input' => 'array',
    ];

    /**
     * Argument names whose value never reaches the log.
     *
     * Matched as suffixes, not exact names: the credential arguments across the
     * tool surface are `password` and `git_token` but also `admin_password`,
     * `sendgrid_api_token`, `mailchannels_password`, `amazon_ses_smtp_password`
     * and `smtp_password`, so anything *ending* in one of these is redacted.
     *
     * These rows are readable through GET /api/mcp-activity-logs, so storing a
     * credential verbatim would put it in plaintext behind an ordinary read
     * endpoint. Tests\Unit\Mcp\ActivityLogRedactionTest checks this list against
     * the arguments the generated tools actually declare.
     */
    public const REDACT_SUFFIXES = [
        'password',
        'passwd',
        'passphrase',
        'token',
        'secret',
        'private_key',
        'api_key',
        'license_key',
    ];

    /**
     * Objects whose keys are worth keeping but whose values never are:
     * `env_vars` holds APP_KEY, DATABASE_URL, AWS_SECRET_ACCESS_KEY, and
     * backup `credentials` holds `secret_access_key` -- none of which a
     * suffix list can name.
     */
    public const REDACT_VALUES_UNDER = [
        'env_vars',
        'credentials',
    ];

    /**
     * Free-form payloads a secret is routinely typed into (`ssh_run.command`,
     * `wp_cli_run.args`, `file_write.contents`, `project_setting_set.value`).
     * Only their size is kept.
     */
    public const SUMMARISE_KEYS = [
        'command',
        'args',
        'contents',
        'file_contents',
        'value',
    ];

    public const REDACTED = '[redacted]';

    /** Query-string parameters whose value is a credential, in a URL a tool echoes back. */
    private const SECRET_QUERY_PARAMS = [
        'private_token',
        'token',
        'access_token',
        'api_token',
        'api_key',
        'apikey',
        'key',
        'password',
        'signature',
        'sig',
        'x-amz-signature',
        'x-amz-credential',
        'x-amz-security-token',
        'awsaccesskeyid',
    ];

    /** Masked only at 16+ characters: a real signature is long, a short `sig=` usually is not one. */
    private const SIGNATURE_QUERY_PARAMS = ['signature', 'sig', 'x-amz-signature'];

    /**
     * Strip credentials on the way in, so no writer can forget to. Applies to
     * the MCP middleware and to the panel's POST /api/mcp-activity-logs alike.
     */
    public function setInputAttribute(mixed $value): void
    {
        $this->attributes['input'] = $value === null
            ? null
            : json_encode(is_array($value) ? self::redact($value) : $value);
    }

    /** A failed call's error text can echo a caller's URL, credentials included. */
    public function setErrorMessageAttribute(mixed $value): void
    {
        $this->attributes['error_message'] = $value === null
            ? null
            : self::scrubString((string) $value);
    }

    /**
     * Matched case-insensitively against the key, at every depth.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function redact(array $input): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            $out[$key] = self::redactEntry(is_string($key) ? strtolower($key) : null, $value);
        }

        return $out;
    }

    /** One argument, by its lowercased name (null for a list index). */
    private static function redactEntry(?string $name, mixed $value): mixed
    {
        return match (true) {
            $name !== null && self::isSecretKey($name) => self::REDACTED,
            $name !== null && in_array($name, self::REDACT_VALUES_UNDER, true) => self::redactValues($value),
            $name !== null && in_array($name, self::SUMMARISE_KEYS, true) => self::summarise($value),
            is_array($value) => self::redact($value),
            is_string($value) => self::scrubString($value),
            default => $value,
        };
    }

    public static function isSecretKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::REDACT_SUFFIXES as $suffix) {
            if ($key === $suffix || str_ends_with($key, '_'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** Keys stay, every leaf value goes; a non-array is redacted whole. */
    private static function redactValues(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value === null ? null : self::REDACTED;
        }

        return array_map(static fn (mixed $v): mixed => self::redactValues($v), $value);
    }

    private static function summarise(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => sprintf('%s (%d bytes)', self::REDACTED, strlen($value)),
            is_array($value) => sprintf('%s (%d items)', self::REDACTED, count($value)),
            default => $value,
        };
    }

    /** A private key or URL credentials can turn up under any argument name. */
    private static function scrubString(string $value): string
    {
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $value) === 1) {
            return self::REDACTED;
        }

        $value = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^\s/@]+@#i', '$1'.self::REDACTED.'@', $value);

        return self::maskSecretQueryParams($value);
    }

    /** `?private_token=abc&x=1` -> `?private_token=[redacted]&x=1`. */
    private static function maskSecretQueryParams(string $value): string
    {
        foreach (self::SECRET_QUERY_PARAMS as $param) {
            $pattern = in_array($param, self::SIGNATURE_QUERY_PARAMS, true)
                ? '/([?&]'.preg_quote($param, '/').'=)[A-Za-z0-9%._~+\/-]{16,}/i'
                : '/([?&]'.preg_quote($param, '/').'=)[^&\s"\']+/i';

            $value = (string) preg_replace($pattern, '$1'.self::REDACTED, $value);
        }

        return $value;
    }

    /** Null once the token is deleted; the row keeps its `token_id`. */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    /** @param list<int> $tokenIds */
    public function scopeForTokenIds(Builder $query, array $tokenIds): Builder
    {
        return $query->whereIn('token_id', $tokenIds);
    }

    /** Case-insensitive, with `%` and `_` in `$text` taken literally. */
    public function scopeTokenNameContains(Builder $query, string $text): Builder
    {
        return $query->whereRaw("LOWER(token_name) LIKE LOWER(?) ESCAPE '!'", [self::containsPattern($text)]);
    }

    private static function containsPattern(string $text): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
    }
}
