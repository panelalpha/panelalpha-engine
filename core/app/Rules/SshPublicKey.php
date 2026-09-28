<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One OpenSSH public key on one line: `<type> <base64> [comment]`.
 *
 * The key is stored in a colon-and-newline delimited logins file and then
 * pasted into an `AuthorizedKeysCommand /bin/echo "<key>"` line in the SFTP
 * container's sshd_config. Both formats are defenceless against the value:
 * a newline is a new login (any uid, any home), a colon shifts the fields, a
 * quote or `$` is shell to sshd. So the grammar here is the whole defence.
 */
final class SshPublicKey implements ValidationRule
{
    private const TYPES = [
        'ssh-rsa',
        'ssh-ed25519',
        'ssh-dss',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com',
        'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    /** Comment: what ssh-keygen writes (user@host) and nothing a shell or a colon-separated file would read. */
    private const COMMENT = '[A-Za-z0-9._@+=\/-]{1,255}';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !self::isValid($value)) {
            $fail('The :attribute must be a single-line OpenSSH public key (type, base64 key, optional comment).');
        }
    }

    public static function isValid(string $key): bool
    {
        $types = implode('|', array_map('preg_quote', self::TYPES));
        $pattern = '/\A(?:' . $types . ') [A-Za-z0-9+\/]+={0,3}(?: ' . self::COMMENT . ')?\z/';

        return preg_match($pattern, $key) === 1;
    }
}
