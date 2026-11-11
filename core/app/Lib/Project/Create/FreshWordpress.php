<?php

namespace App\Lib\Project\Create;

use App\Models\Domain;
use App\Models\User;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressInstaller;
use App\System\Project\PhpHosting\WordPress\Migration\WpCliWordpressInstaller;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A new WordPress on a php_hosting project's main domain, with its own database and the admin the caller chose:
 * the steps a WordPress import runs before it copies a site in.
 */
class FreshWordpress
{
    public function __construct(private readonly WordpressInstaller $installer = new WpCliWordpressInstaller) {}

    /**
     * @param  array{title: string, admin_user: string, admin_email: string, admin_password: string, locale: ?string, version: ?string}  $site
     * @param  callable(string): void  $log
     */
    public function install(User $user, array $site, callable $log): void
    {
        $domain = Domain::findByName((string) $user->domain)
            ?? throw new RuntimeException("Project '{$user->username}' has no main domain to install WordPress on.");
        $path = rtrim($user->project()->homeDirPath(), '/').$domain->getDocumentRoot();

        $log("Downloading WordPress core into {$domain->getDocumentRoot()}");
        WordPress::for($user, $path)->core()->download($site['locale'], $site['version']);

        $database = $user->getMysqlPrefix().'wp'.substr(bin2hex(random_bytes(2)), 0, 4);
        $password = Str::random(24);
        $log("Creating the database {$database}");
        $this->installer->ensureDatabase($user, $database, $database, $password);
        $this->installer->writeConfig($user, $path, $database, $database, $password);

        $log("Installing WordPress for https://{$domain->domain}");
        WordPress::for($user, $path)->core()->install([
            'url' => 'https://'.$domain->domain,
            'title' => $site['title'],
            'admin_user' => $site['admin_user'],
            'admin_email' => $site['admin_email'],
            'admin_password' => $site['admin_password'],
            'skip_email' => true,
        ]);
        $log('WordPress installed');
    }
}
