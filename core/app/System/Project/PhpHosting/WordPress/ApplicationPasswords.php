<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Application passwords for a WordPress user on a PHP hosting install.
 *
 * Create returns the one-time secret in that response only (WP-CLI --porcelain).
 */
final class ApplicationPasswords
{
    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $wpUser): array
    {
        $result = $this->cli->runOrFail(
            ['user', 'application-password', 'list', WpCli::positional($wpUser), '--format=json'],
        );

        return JsonOutput::decodeList($result['stdout']);
    }

    /**
     * Create an application password. The plaintext secret appears only here.
     *
     * @return array{user: string, name: string, password: string}
     */
    public function create(string $wpUser, string $name, ?string $appId = null): array
    {
        $args = ['user', 'application-password', 'create', WpCli::positional($wpUser), WpCli::positional($name), '--porcelain'];
        if (is_string($appId) && $appId !== '') {
            $args[] = '--app-id='.$appId;
        }
        $result = $this->cli->runOrFail($args);

        return [
            'user' => $wpUser,
            'name' => $name,
            'password' => $this->parseCreateSecret($result['stdout']),
        ];
    }

    /**
     * @return array{user: string, uuid: string, message: string}
     */
    public function delete(string $wpUser, string $uuid): array
    {
        $result = $this->cli->runOrFail(
            ['user', 'application-password', 'delete', WpCli::positional($wpUser), WpCli::positional($uuid)],
        );

        return [
            'user' => $wpUser,
            'uuid' => $uuid,
            'message' => trim($result['stdout']),
        ];
    }

    /**
     * Extract the one-time plaintext from create stdout.
     *
     * Prefer --porcelain (a single trimmed line). Fall back to a
     * "Password: …" line when chrome is present. Porcelain is the first
     * non-empty line that is not a Success: banner.
     */
    private function parseCreateSecret(string $stdout): string
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            throw new Exception('WP-CLI create did not return an application password.', 500);
        }

        if (preg_match('/^Password:\s*(.+)$/mi', $trimmed, $matches) === 1) {
            $secret = trim($matches[1]);
            if ($secret !== '') {
                return $secret;
            }
        }

        foreach (preg_split("/\r\n|\n|\r/", $trimmed) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'Success:')) {
                continue;
            }

            return $line;
        }

        throw new Exception('WP-CLI create did not return an application password.', 500);
    }
}
