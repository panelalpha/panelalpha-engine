<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * One-time SSO URL for a WordPress user, on PHP hosting or in a container.
 *
 * Ensures the engine must-use plugin (same panelalpha_sso token scheme DinD
 * WordPress uses) is on the install, then stores a one-time token and returns
 * `{siteurl}/?panelalpha_sso={token}`. Does not install the panel connector
 * and does not emit controlpanel_sso.
 */
final class Sso
{
    public function __construct(
        private WpCli $cli,
        private User $user,
        private ?EnsuresMustUsePlugin $mustUsePlugin = null,
    ) {}

    /**
     * The token has the same shape as panelalpha-app.php users:sso (DinD).
     *
     * @return array{url: string}
     */
    public function create(string $wpUser): array
    {
        $this->muPlugin()->ensure($this->cli->runsInContainer() ? '' : $this->cli->resolvePath());

        $expires = time() + 60;
        $token = (string) $expires.'_'.Str::random(64);

        $this->cli->runOrFail(
            ['user', 'meta', 'update', WpCli::positional($wpUser), 'panelalpha_sso', sha1($token)],
        );

        $siteurl = trim($this->cli->runOrFail(
            ['option', 'get', 'siteurl'],
        )['stdout']);

        if ($siteurl === '') {
            throw new Exception('WP-CLI did not return siteurl.', 500);
        }

        return [
            'url' => rtrim($siteurl, '/').'/?panelalpha_sso='.$token,
        ];
    }

    private function muPlugin(): EnsuresMustUsePlugin
    {
        $plugin = new MustUsePlugin($this->user);

        return $this->mustUsePlugin ??= $this->cli->runsInContainer()
            ? new ContainerMustUsePlugin($this->cli, $plugin)
            : $plugin;
    }
}
