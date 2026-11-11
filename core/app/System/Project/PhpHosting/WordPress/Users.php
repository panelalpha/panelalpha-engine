<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\Rules\WpCliArgument;

/**
 * Password set takes the caller's value; nothing generates and prints one. An operation naming a user WordPress
 * does not have answers 404, and a password is as long as the API's password rule allows.
 */
final class Users
{
    /** The shortest password this sets, the API's rule for the same operations. */
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @param  string|null  $role  only users with this role; null = all
     * @param  string|null  $fields  comma-separated WP-CLI fields; null = the WP-CLI default set
     * @return list<array<string, mixed>>
     */
    public function list(?string $role = null, ?string $fields = null): array
    {
        $args = ['user', 'list', '--format=json'];
        if ($role !== null) {
            $args[] = '--role='.$role;
        }
        if ($fields !== null) {
            $args[] = '--fields='.$fields;
        }
        $result = $this->cli->runOrFail($args);

        return JsonOutput::decodeList($result['stdout']);
    }

    /**
     * @param array{
     *     login: string,
     *     email: string,
     *     password: string,
     *     role?: string|null,
     *     display_name?: string|null,
     *     first_name?: string|null,
     *     last_name?: string|null,
     *     send_email?: bool,
     * } $fields
     * @return array{user: string, message: string}
     */
    public function create(array $fields): array
    {
        $login = self::storedLogin($fields['login']);
        self::assertPasswordLength($fields['password']);
        $args = ['user', 'create', WpCli::positional($login), WpCli::positional($fields['email']), '--user_pass='.$fields['password']];
        if (! empty($fields['role'])) {
            $args[] = '--role='.$fields['role'];
        }
        if (! empty($fields['display_name'])) {
            $args[] = '--display_name='.$fields['display_name'];
        }
        if (! empty($fields['first_name'])) {
            $args[] = '--first_name='.$fields['first_name'];
        }
        if (! empty($fields['last_name'])) {
            $args[] = '--last_name='.$fields['last_name'];
        }
        if (! empty($fields['send_email'])) {
            $args[] = '--send-email';
        }
        $result = $this->cli->runOrFail($args);

        return $this->mutationBody($login, $result['stdout']);
    }

    /**
     * @param array{
     *     email?: string|null,
     *     role?: string|null,
     *     display_name?: string|null,
     *     first_name?: string|null,
     *     last_name?: string|null,
     *     password?: string|null,
     * } $fields
     * @return array{user: string, message: string}
     *
     * The password goes in the same `user update`, so the profile and the
     * password change together or not at all.
     */
    public function update(string $user, array $fields): array
    {
        $password = is_string($fields['password'] ?? null) && $fields['password'] !== '' ? $fields['password'] : null;
        if ($password !== null) {
            self::assertPasswordLength($password);
        }
        $this->assertExists($user);
        $args = ['user', 'update', WpCli::positional($user)];
        if (array_key_exists('email', $fields) && is_string($fields['email']) && $fields['email'] !== '') {
            $args[] = '--user_email='.$fields['email'];
        }
        if (array_key_exists('role', $fields) && is_string($fields['role']) && $fields['role'] !== '') {
            $args[] = '--role='.$fields['role'];
        }
        if (array_key_exists('display_name', $fields) && is_string($fields['display_name']) && $fields['display_name'] !== '') {
            $args[] = '--display_name='.$fields['display_name'];
        }
        if (array_key_exists('first_name', $fields) && is_string($fields['first_name']) && $fields['first_name'] !== '') {
            $args[] = '--first_name='.$fields['first_name'];
        }
        if (array_key_exists('last_name', $fields) && is_string($fields['last_name']) && $fields['last_name'] !== '') {
            $args[] = '--last_name='.$fields['last_name'];
        }
        if ($password !== null) {
            $args[] = '--user_pass='.$password;
        }
        $result = $this->cli->runOrFail($args);

        return $this->mutationBody($user, $result['stdout']);
    }

    /**
     * @return array{user: string, message: string}
     */
    public function delete(string $user, ?string $reassign = null): array
    {
        $this->assertExists($user);
        $args = ['user', 'delete', WpCli::positional($user), '--yes'];
        if (is_string($reassign) && $reassign !== '') {
            $args[] = '--reassign='.$reassign;
        }
        $result = $this->cli->runOrFail($args);

        return $this->mutationBody($user, $result['stdout']);
    }

    /**
     * Set the WordPress user password to a caller-provided value.
     *
     * @return array{user: string, message: string}
     */
    public function setPassword(string $user, string $password): array
    {
        self::assertPasswordLength($password);
        $this->assertExists($user);
        $result = $this->cli->runOrFail(
            ['user', 'update', WpCli::positional($user), '--user_pass='.$password],
        );

        return $this->mutationBody($user, $result['stdout']);
    }

    /**
     * WP-CLI's `user update` and `user delete` only warn about a login or ID no user has, and answer 0.
     */
    private function assertExists(string $user): void
    {
        $result = $this->cli->run(['user', 'get', WpCli::positional($user), '--field=ID']);
        if ($result['exit_code'] !== 0) {
            throw new Exception($this->cli->failureMessage($result), 404);
        }
    }

    /**
     * A login WordPress keeps as it is given: it trims it and drops what {@see WpCliArgument::LOGIN} refuses.
     */
    private static function storedLogin(string $login): string
    {
        $login = trim(WpCli::positional($login));
        if (preg_match('~^'.WpCliArgument::LOGIN.'$~D', $login) !== 1) {
            throw new Exception("The login must be letters, digits, spaces and _ . @ -: '{$login}' is not.", 422);
        }

        return $login;
    }

    private static function assertPasswordLength(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new Exception('The password must be at least '.self::MIN_PASSWORD_LENGTH.' characters.', 422);
        }
    }

    /**
     * @return array{user: string, message: string}
     */
    private function mutationBody(string $user, string $stdout): array
    {
        return [
            'user' => $user,
            'message' => trim($stdout),
        ];
    }
}
