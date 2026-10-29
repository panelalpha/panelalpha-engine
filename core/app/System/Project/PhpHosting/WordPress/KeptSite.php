<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * What a push target's WordPress keeps through the push: its own wp-config.php,
 * the addresses it answers on, and whether search engines may index it.
 */
final class KeptSite
{
    /**
     * @param  list<string>  $sourceHosts  the hosts the pushed database answers on
     */
    public function __construct(
        public readonly string $configPath,
        public readonly string $home,
        public readonly string $siteUrl,
        public readonly string $blogPublic,
        public readonly array $sourceHosts,
    ) {}

    public function host(): string
    {
        return (string) parse_url($this->home, PHP_URL_HOST);
    }
}
