<?php

namespace Tests\Unit\Rules;

use App\Rules\SshPublicKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Keys generated with ssh-keygen; the security-key ones are built field by field, since generating them needs a token. */
class SshPublicKeyTest extends TestCase
{
    private const ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIN5KCiuy5+pUCc2xXv4dhmQWvU/VtCA/OmjHI8mwa5l user@laptop';

    private const ECDSA_256 = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBAbWa7XoEeTmaHrUZprk35BFaaZToAR+v1WS0Eof/5xAesSy+RlDhx154YwnmciKqft/TBT04jm54mWR9sLJXuM= user@laptop';

    private const ECDSA_384 = 'ecdsa-sha2-nistp384 AAAAE2VjZHNhLXNoYTItbmlzdHAzODQAAAAIbmlzdHAzODQAAABhBIhurnnGWLs/r+KfUv0kENdHucwHM1DMHHUGPNlni9rq8R1Hct/ER1Os5UMk4dD0kyHunFUZdChkIabXf1H+AC8jmBUU4D2Ku3uD4ugjWA6ssbNp7fvJqMb3yuRLfZwpmg== user@laptop';

    private const ECDSA_521 = 'ecdsa-sha2-nistp521 AAAAE2VjZHNhLXNoYTItbmlzdHA1MjEAAAAIbmlzdHA1MjEAAACFBAAK+E109amuPecSvfEDSsM8i1rTKN2m9F4S5/0D2IXrtqlq7o+ldGv19J/IyJnfKlU8EEbao9QgiTloAVJILd0XAAFnQMG85gW0zAuSciCx1jG59ay5qmY88a1c71BS46NwSE5ATOeXWwdueJrTtwsP1uqdZPtEJENgQkR9k+ORMKh8JQ== user@laptop';

    private const RSA_2048 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDNKBV14HV/Fv4IVi8mw/Yw/oEQuTKt9ef4qFBKiSKYdNO7ThBVyUi0mIw0UpyS4R8K6G+AG+fT5cZgsoFzVxCl0zvPdGqu4qGxCzhjnn1VqvicPhBhimmz4MaORMq5v1VNfQQco/uwzQ3llHM6xjSxdpOC+q1aL3BhiRBWxzYW2st474yEERBuQJsdMJOrCbO3tlquyMOWG4BOV8noSX8U/6GaYbZLwAvy31PdBJuPsXSzvD9SyNgU2ffOtDKPJBNrYA+9pRXAxJ8jguYBooXPgtoFfZf0j91AjbaZmoU6xz9aRDcqIEG57qbmfA988bJe5rr0lxayyyqBH1ffk6uv user@laptop';

    private const RSA_1024 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQDkI2XmjUrsnhH62flwcGdiTOueAB4iXG9kUm36sHtJGPAvHGoyiAf7e0SlYt1O4VKvS4JdUFN5Kj8TomNg7hLqMdb8Y1ipRPUZZBINdaWvdlzuQOhLeWZoeavq1naeOig4pTkOqDcxF6YJo0MWnWmqKnVmtkSmxNS8wmLNJrEFnQ== user@laptop';

    /** @return array<string, array{string}> */
    public static function acceptedKeys(): array
    {
        return [
            'ed25519' => [self::ED25519],
            'ed25519 without a comment' => [strstr(self::ED25519, ' user@laptop', true)],
            'ecdsa nistp256' => [self::ECDSA_256],
            'ecdsa nistp384' => [self::ECDSA_384],
            'ecdsa nistp521' => [self::ECDSA_521],
            'rsa 2048' => [self::RSA_2048],
            'security-key ed25519' => [self::line('sk-ssh-ed25519@openssh.com', str_repeat("\x11", 32), 'ssh:')],
            'security-key ecdsa' => [self::line('sk-ecdsa-sha2-nistp256@openssh.com', 'nistp256', "\x04".str_repeat("\x22", 64), 'ssh:')],
        ];
    }

    #[DataProvider('acceptedKeys')]
    public function test_a_key_the_sftp_server_takes_is_accepted(string $key): void
    {
        $this->assertNull($this->refusal($key));
    }

    /** @return array<string, array{string, string}> */
    public static function refusedKeys(): array
    {
        $ed25519 = base64_decode(explode(' ', self::ED25519)[1]);

        return [
            'rsa 1024' => [self::RSA_1024, 'ssh_key_too_weak'],
            'dsa' => ['ssh-dss AAAAB3NzaC1kc3MAAACBAP1/U4EddRIpUt9KnC7s5Of2EbdSPO9EAMMeP4C2USZpRV1AIlH7WT2NWPq user@laptop', 'ssh_key_type_not_accepted'],
            'base64 that is not a key' => ['ssh-ed25519 AAAAnotakeyatall', 'ssh_key_corrupt'],
            'rsa blob labelled ed25519' => [preg_replace('/^ssh-rsa/', 'ssh-ed25519', self::RSA_2048), 'ssh_key_corrupt'],
            'nistp384 blob labelled nistp256' => [preg_replace('/^ecdsa-sha2-nistp384/', 'ecdsa-sha2-nistp256', self::ECDSA_384), 'ssh_key_corrupt'],
            'truncated ed25519' => ['ssh-ed25519 '.base64_encode(substr($ed25519, 0, -4)), 'ssh_key_corrupt'],
            'ed25519 with trailing bytes' => ['ssh-ed25519 '.base64_encode($ed25519."\0\0\0\0"), 'ssh_key_corrupt'],
            'ed25519 with a short key' => [self::line('ssh-ed25519', str_repeat("\x11", 31)), 'ssh_key_corrupt'],
            'compressed ecdsa point' => [self::line('ecdsa-sha2-nistp256', 'nistp256', "\x02".str_repeat("\x22", 64)), 'ssh_key_corrupt'],
            'invalid base64' => ['ssh-ed25519 AAAA=AAAA', 'ssh_key_invalid'],
            'newline' => ["ssh-ed25519 AAAA\nrogue::0:0:/home/victim:", 'ssh_key_invalid'],
            'colon' => ['ssh-ed25519 AAAA:0:0', 'ssh_key_invalid'],
            'shell in the comment' => [self::ED25519.' "$(id)"', 'ssh_key_invalid'],
            'unknown type' => ['not-a-key AAAA', 'ssh_key_invalid'],
            'private key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjE=", 'ssh_key_invalid'],
        ];
    }

    #[DataProvider('refusedKeys')]
    public function test_a_key_the_sftp_server_would_not_take_is_refused_with_its_code(string $key, string $code): void
    {
        $this->assertSame($code, $this->refusal($key)['code'] ?? null);
    }

    public function test_the_weak_key_refusal_names_its_size(): void
    {
        $this->assertSame('RSA keys must be at least 2048 bits; this one is 1024.', $this->refusal(self::RSA_1024)['message'] ?? null);
    }

    public function test_a_value_that_is_not_a_string_is_refused(): void
    {
        $this->assertSame('ssh_key_invalid', $this->refusal(['ssh-ed25519'])['code'] ?? null);
    }

    /** @return ?array<string, mixed> */
    private function refusal(mixed $key): ?array
    {
        $rule = new SshPublicKey;
        $failed = null;
        $rule->validate('public_key', $key, function (string $message) use (&$failed): void {
            $failed = $message;
        });
        $this->assertSame($rule->problem()['message'] ?? null, $failed);

        return $rule->problem();
    }

    private static function line(string $type, string ...$fields): string
    {
        $blob = '';
        foreach ([$type, ...$fields] as $field) {
            $blob .= pack('N', strlen($field)).$field;
        }

        return $type.' '.base64_encode($blob);
    }
}
