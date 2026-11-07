<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named site-language operations. Set runs `wp language core install {language} --activate`, which installs the pack
 * when needed and activates it in one call; the older `language core activate` is deprecated.
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
            ['language', 'core', 'install', WpCli::positional($language), '--activate'],
        );

        return [
            'language' => $language,
            'message' => trim($result['stdout']),
        ];
    }
}
