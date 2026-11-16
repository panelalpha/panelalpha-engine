<?php

namespace App\Rules;

use Closure;

/**
 * One OpenSSH public key on one line: `<type> <base64> [comment]`, whose
 * decoded blob is a key of that type the SFTP server accepts.
 *
 * The key is stored in a colon-and-newline delimited logins file and then
 * pasted into an `AuthorizedKeysCommand /bin/echo "<key>"` line in the SFTP
 * container's sshd_config. Both formats are defenceless against the value:
 * a newline is a new login (any uid, any home), a colon shifts the fields, a
 * quote or `$` is shell to sshd. So the grammar here is the whole defence.
 */
final class SshPublicKey implements ProblemRule
{
    use RaisesProblem;

    public const MIN_RSA_BITS = 2048;

    private const TYPES = [
        'ssh-rsa',
        'ssh-ed25519',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com',
        'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    private const ED25519_KEY_BYTES = 32;

    private const ECDSA_POINT_BYTES = ['nistp256' => 65, 'nistp384' => 97, 'nistp521' => 133];

    /** Comment: what ssh-keygen writes (user@host) and nothing a shell or a colon-separated file would read. */
    private const COMMENT = '[A-Za-z0-9._@+=\/-]{1,255}';

    private const EXPECTED = 'an OpenSSH public key line: ssh-ed25519, ecdsa-sha2-nistp256/384/521 or ssh-rsa of at least 2048 bits';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $refusal = is_string($value) ? self::refusal($value) : self::notAKey();
        if ($refusal === null) {
            return;
        }

        $this->reject(['field' => $attribute, ...$refusal, 'expected' => self::EXPECTED], $fail);
    }

    public static function isValid(string $key): bool
    {
        return self::refusal($key) === null;
    }

    /** @return ?array{code: string, message: string} */
    private static function refusal(string $key): ?array
    {
        if (str_starts_with($key, 'ssh-dss ')) {
            return self::refused('ssh_key_type_not_accepted', 'DSA (ssh-dss) keys are not accepted by the SFTP server. Use an Ed25519, ECDSA or RSA key.');
        }

        $line = self::parseLine($key);
        if ($line === null) {
            return self::notAKey();
        }

        $fields = self::wireFields($line['blob']);
        if ($fields === null || $fields[0] !== $line['type'] || ! self::isWellFormed($line['type'], $fields)) {
            return self::refused('ssh_key_corrupt', "The key data is not a valid {$line['type']} public key. Paste the whole line of the .pub file.");
        }

        $bits = $line['type'] === 'ssh-rsa' ? self::mpintBits($fields[2]) : null;
        if ($bits !== null && $bits < self::MIN_RSA_BITS) {
            return self::refused('ssh_key_too_weak', 'RSA keys must be at least '.self::MIN_RSA_BITS." bits; this one is {$bits}.");
        }

        return null;
    }

    /** @return ?array{type: string, blob: string} */
    private static function parseLine(string $key): ?array
    {
        $types = implode('|', array_map('preg_quote', self::TYPES));
        $pattern = '/\A(?<type>'.$types.') (?<blob>[A-Za-z0-9+\/]+={0,3})(?: '.self::COMMENT.')?\z/';
        if (preg_match($pattern, $key, $match) !== 1) {
            return null;
        }

        $blob = base64_decode($match['blob'], true);

        return $blob === false ? null : ['type' => $match['type'], 'blob' => $blob];
    }

    /**
     * The blob as its length-prefixed strings, or null when one runs past the end.
     *
     * @return ?non-empty-list<string>
     */
    private static function wireFields(string $blob): ?array
    {
        $fields = [];
        $offset = 0;
        while ($offset < strlen($blob)) {
            if ($offset + 4 > strlen($blob)) {
                return null;
            }
            $length = unpack('N', $blob, $offset)[1];
            $offset += 4;
            if ($length > strlen($blob) - $offset) {
                return null;
            }
            $fields[] = substr($blob, $offset, $length);
            $offset += $length;
        }

        return $fields === [] ? null : $fields;
    }

    /** @param  non-empty-list<string>  $fields */
    private static function isWellFormed(string $type, array $fields): bool
    {
        return match ($type) {
            'ssh-rsa' => count($fields) === 3 && self::mpintBits($fields[1]) > 0 && self::mpintBits($fields[2]) > 0,
            'ssh-ed25519' => count($fields) === 2 && strlen($fields[1]) === self::ED25519_KEY_BYTES,
            'sk-ssh-ed25519@openssh.com' => count($fields) === 3 && strlen($fields[1]) === self::ED25519_KEY_BYTES && $fields[2] !== '',
            'sk-ecdsa-sha2-nistp256@openssh.com' => count($fields) === 4 && self::isEcdsaPoint('nistp256', $fields) && $fields[3] !== '',
            default => count($fields) === 3 && self::isEcdsaPoint(substr($type, strlen('ecdsa-sha2-')), $fields),
        };
    }

    /** @param  non-empty-list<string>  $fields */
    private static function isEcdsaPoint(string $curve, array $fields): bool
    {
        $point = $fields[2] ?? '';

        return ($fields[1] ?? null) === $curve
            && strlen($point) === self::ECDSA_POINT_BYTES[$curve]
            && $point[0] === "\x04";
    }

    private static function mpintBits(string $mpint): int
    {
        $magnitude = ltrim($mpint, "\0");

        return $magnitude === '' ? 0 : (strlen($magnitude) - 1) * 8 + strlen(decbin(ord($magnitude[0])));
    }

    /** @return array{code: string, message: string} */
    private static function notAKey(): array
    {
        return self::refused('ssh_key_invalid', 'The public key must be one line of an OpenSSH .pub file: type, base64 key, optional comment.');
    }

    /** @return array{code: string, message: string} */
    private static function refused(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
