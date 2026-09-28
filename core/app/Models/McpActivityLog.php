<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'token_id',
        'token_name',
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
     * and `smtp_password`. An exact-name list silently missed those two whole
     * families, so anything *ending* in one of these is redacted.
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

    /**
     * Matched case-insensitively against the key, at every depth.
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public static function redact(array $input): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            $name = is_string($key) ? strtolower($key) : null;

            if ($name !== null && self::isSecretKey($name)) {
                $out[$key] = self::REDACTED;
            } elseif ($name !== null && in_array($name, self::REDACT_VALUES_UNDER, true)) {
                $out[$key] = self::redactValues($value);
            } elseif ($name !== null && in_array($name, self::SUMMARISE_KEYS, true)) {
                $out[$key] = self::summarise($value);
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } else {
                $out[$key] = is_string($value) ? self::scrubString($value) : $value;
            }
        }

        return $out;
    }

    public static function isSecretKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::REDACT_SUFFIXES as $suffix) {
            if ($key === $suffix || str_ends_with($key, '_' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** Keys stay, every leaf value goes; a non-array is redacted whole. */
    private static function redactValues(mixed $value): mixed
    {
        if (!is_array($value)) {
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

        return (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^\s/@]+@#i', '$1' . self::REDACTED . '@', $value);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }
}
