<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named user operations for a PHP hosting WordPress install.
 *
 * HTTP, artisan, and MCP tools are thin adapters around this. Path resolution
 * and the WP-CLI runner come from WpCli; a non-zero exit becomes Exception.
 * Password set accepts a caller-provided value; there is no named generate-
 * and-print password operation.
 */
final class Users
{
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
        $login = $fields['login'];
        $args = ['user', 'create', $login, $fields['email'], '--user_pass='.$fields['password']];
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
     */
    public function update(string $user, array $fields): array
    {
        $args = ['user', 'update', $user];
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
        // In the same `user update`, so the profile and the password change together or not at all.
        if (array_key_exists('password', $fields) && is_string($fields['password']) && $fields['password'] !== '') {
            $args[] = '--user_pass='.$fields['password'];
        }
        $result = $this->cli->runOrFail($args);

        return $this->mutationBody($user, $result['stdout']);
    }

    /**
     * @return array{user: string, message: string}
     */
    public function delete(string $user, ?string $reassign = null): array
    {
        $args = ['user', 'delete', $user, '--yes'];
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
        $result = $this->cli->runOrFail(
            ['user', 'update', $user, '--user_pass='.$password],
        );

        return $this->mutationBody($user, $result['stdout']);
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
