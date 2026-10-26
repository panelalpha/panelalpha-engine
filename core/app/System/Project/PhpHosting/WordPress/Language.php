<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named site-language operations for a PHP hosting WordPress install.
 *
 * Set maps to the modern WP-CLI form `wp language core install {language}
 * --activate` (installs the pack when needed and activates it). The older
 * `language core activate` is deprecated in favour of `site switch-language`;
 * install-with-activate covers both install and activate in one call.
 * HTTP, artisan, and MCP tools are adapters. Path resolution and the runner
 * come from WpCli; a non-zero exit becomes Exception.
 */
final class Language
{
    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @return array{language: string, message: string}
     */
    public function set(string $language): array
    {
        $result = $this->cli->runOrFail(
            ['language', 'core', 'install', $language, '--activate'],
        );

        return [
            'language' => $language,
            'message' => trim($result['stdout']),
        ];
    }
}
