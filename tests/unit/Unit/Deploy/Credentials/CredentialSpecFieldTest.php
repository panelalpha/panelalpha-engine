<?php

namespace Tests\Unit\Deploy\Credentials;

use App\Lib\Deploy\Credentials\CredentialSpec;
use App\Lib\Deploy\Platform\ManifestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One `credentials.fields` entry: the exact refusal for each way it can be wrong, in the
 * order the checks run, and what an accepted one becomes.
 */
class CredentialSpecFieldTest extends TestCase
{
    private static function parse(string $name, mixed $field): ?CredentialSpec
    {
        return CredentialSpec::parse(['fields' => [$name => $field]], fn (string $m) => new ManifestException($m));
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function refused(): array
    {
        $value = 'credentials.fields.X.value must be a non-empty string of letters, digits and @._+-, optionally with {random} / {host} / {email}';

        return [
            'name starts with a digit' => ['1X', ['kind' => 'password'], "credentials.fields: '1X' is not a valid environment variable name"],
            'name with a dash' => ['A-B', 'not even a mapping', "credentials.fields: 'A-B' is not a valid environment variable name"],
            'name ending in a newline' => ["ADMIN_USER\n", ['kind' => 'username'], "credentials.fields: 'ADMIN_USER\n' is not a valid environment variable name"],
            'a string' => ['X', 'password', 'credentials.fields.X must be a mapping with a `kind`'],
            'a list' => ['X', ['password'], 'credentials.fields.X must be a mapping with a `kind`'],
            'an empty array' => ['X', [], 'credentials.fields.X must be a mapping with a `kind`'],
            'unknown keys' => ['X', ['length' => 32, 'kind' => 'token', 'extra' => 1], 'credentials.fields.X: unknown key(s) length, extra'],
            'no kind' => ['X', ['value' => 'admin'], 'credentials.fields.X.kind must be one of username, email, password'],
            'a kind that is not a string' => ['X', ['kind' => 5], 'credentials.fields.X.kind must be one of username, email, password'],
            'an unknown kind' => ['X', ['kind' => 'token'], 'credentials.fields.X.kind must be one of username, email, password'],
            'a password value before a bad symbol' => ['X', ['kind' => 'password', 'value' => 'x', 'symbol' => 'yes'], 'credentials.fields.X: a password is generated, it cannot declare a value'],
            'a value that is not a string' => ['X', ['kind' => 'username', 'value' => 5], $value],
            'an empty value' => ['X', ['kind' => 'username', 'value' => ''], $value],
            'a value with a space' => ['X', ['kind' => 'email', 'value' => 'a b@example.com'], $value],
            'a value ending in a newline' => ['X', ['kind' => 'email', 'value' => "admin@example.com\n"], $value],
            'an unknown placeholder' => ['X', ['kind' => 'email', 'value' => 'admin@{domain}'], $value],
            'a bad value before a bad symbol' => ['X', ['kind' => 'username', 'value' => "ad'min", 'symbol' => 1], $value],
            'a symbol that is not a bool' => ['X', ['kind' => 'password', 'symbol' => 'yes'], 'credentials.fields.X.symbol must be true or false'],
            'a symbol on an email' => ['X', ['kind' => 'email', 'symbol' => true], 'credentials.fields.X.symbol applies to a password only'],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_wrong_field_is_refused_with_its_exact_reason(string $name, mixed $field, string $message): void
    {
        try {
            self::parse($name, $field);
            $this->fail('expected a ManifestException');
        } catch (ManifestException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** @return array<string, array{mixed, array{kind: string, value: ?string, symbol: bool}}> */
    public static function accepted(): array
    {
        return [
            'a bare password' => [['kind' => 'password'], ['kind' => 'password', 'value' => null, 'symbol' => false]],
            'a password with symbols' => [['kind' => 'password', 'symbol' => true], ['kind' => 'password', 'value' => null, 'symbol' => true]],
            'a password with a null value' => [['kind' => 'password', 'value' => null, 'symbol' => false], ['kind' => 'password', 'value' => null, 'symbol' => false]],
            'a bare username' => [['kind' => 'username'], ['kind' => 'username', 'value' => null, 'symbol' => false]],
            'a username with placeholders' => [['kind' => 'username', 'value' => 'admin-{random}'], ['kind' => 'username', 'value' => 'admin-{random}', 'symbol' => false]],
            'an email with placeholders' => [['kind' => 'email', 'value' => 'admin+{random}@{host}'], ['kind' => 'email', 'value' => 'admin+{random}@{host}', 'symbol' => false]],
            'an email with symbol false' => [['kind' => 'email', 'value' => '{email}', 'symbol' => false], ['kind' => 'email', 'value' => '{email}', 'symbol' => false]],
        ];
    }

    #[DataProvider('accepted')]
    public function test_a_valid_field_is_kept_as_declared(mixed $field, array $expected): void
    {
        $this->assertSame(['_A1' => $expected], self::parse('_A1', $field)?->fields);
    }
}
