<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use Throwable;

/**
 * Finds the source WordPress behind what the caller gave: a login page for
 * `wp_admin`, the migrator plugin's REST namespace for `manual_plugin`. Every
 * candidate is tried in order; when none works, the failure that got furthest
 * is thrown, so a wrong password reads as `login_failed`, not `not_wordpress`.
 */
final class SourceSiteResolver
{
    public function __construct(private SourceWordpressClient $client) {}

    /**
     * Read the home from each candidate login page, confirm it through the REST
     * index, and log in. The client keeps the session.
     *
     * @throws SourceCheckFailed
     */
    public function logIn(SourceAccess $access, bool $confirmAdminEmail = true): SourceSite
    {
        $failure = null;
        $tried = [];
        foreach (self::loginCandidates($access) as $candidate) {
            try {
                $site = $this->siteBehindLoginPage($candidate);
                if (isset($tried[$site->url.' '.$site->loginUrl])) {
                    continue;
                }
                $tried[$site->url.' '.$site->loginUrl] = true;
                $this->client->logIn($site, (string) $access->username, (string) $access->password, $confirmAdminEmail);

                return $site;
            } catch (Throwable $e) {
                $failure = SourceCheckFailed::furthest($failure, SourceCheckFailed::from($e));
            }
        }

        throw $failure ?? SourceCheckFailed::notWordpress('No WordPress login page found for '.$access->url);
    }

    /**
     * Find `wp-migrator/v1` at the URL, its parent, then the home a login page
     * names, and check the token with the plugin. Needs no wp-admin session.
     *
     * @throws SourceCheckFailed
     */
    public function findPlugin(SourceAccess $access): SourceSite
    {
        $failure = null;
        foreach (self::pluginBases($access->url) as $base) {
            try {
                return $this->pluginSite($base, null, (string) $access->sourceToken);
            } catch (Throwable $e) {
                $failure = SourceCheckFailed::furthest($failure, SourceCheckFailed::from($e));
            }
        }

        foreach (self::loginCandidates($access) as $candidate) {
            try {
                $loginPage = $this->siteBehindLoginPage($candidate);

                return $this->pluginSite($loginPage->url, $loginPage->loginUrl, (string) $access->sourceToken);
            } catch (Throwable $e) {
                $failure = SourceCheckFailed::furthest($failure, SourceCheckFailed::from($e));
            }
        }

        throw $failure ?? SourceCheckFailed::pluginNotFound();
    }

    /**
     * The site a login page belongs to, confirmed as WordPress by its REST index.
     *
     * @throws SourceCheckFailed
     */
    public function siteBehindLoginPage(string $loginPageUrl): SourceSite
    {
        $page = $this->client->http()->request('GET', $loginPageUrl);
        if (WpAdminHtml::isCloudflareBlocked($page->body)) {
            throw SourceCheckFailed::cloudflare();
        }

        $pageUrl = $page->effectiveUrl ?? $loginPageUrl;
        $home = WpAdminHtml::wpHomeUrl($page->body);
        if ($page->status >= 400 || $home === null) {
            throw SourceCheckFailed::notWordpress('No WordPress login page at '.$loginPageUrl);
        }

        $home = rtrim(SourceFetchGuard::resolveRedirect($pageUrl, $home), '/');
        $action = WpAdminHtml::loginFormAction($page->body);
        $loginUrl = $action === null ? $pageUrl : SourceFetchGuard::resolveRedirect($pageUrl, $action);

        return new SourceSite($home, $loginUrl, $this->confirmedRestMode($home));
    }

    /**
     * The REST mode under which the site's index names this same home.
     *
     * @throws SourceCheckFailed
     */
    private function confirmedRestMode(string $home): string
    {
        foreach (SourceSite::REST_MODES as $mode) {
            $index = $this->client->restIndex($home, $mode);
            if ($index !== null && self::sameUrl($index['home'] ?? null, $home)) {
                return $mode;
            }
        }

        throw SourceCheckFailed::notWordpress('The WordPress REST API does not confirm '.$home);
    }

    /**
     * @throws SourceCheckFailed
     */
    private function pluginSite(string $base, ?string $loginUrl, string $token): SourceSite
    {
        foreach (SourceSite::REST_MODES as $mode) {
            $index = $this->client->restIndex($base, $mode);
            if ($index === null) {
                continue;
            }
            if (! in_array(SourceWordpressClient::PLUGIN_NAMESPACE, (array) ($index['namespaces'] ?? []), true)) {
                throw SourceCheckFailed::pluginNotFound();
            }

            $site = new SourceSite(self::indexHome($index, $base), $loginUrl, $mode);
            $this->client->useSite($site);
            $this->client->assertPluginAcceptsToken($token);

            return $site;
        }

        throw SourceCheckFailed::notWordpress('No WordPress REST API at '.$base);
    }

    /**
     * With `login_url`: it, its directory's wp-login.php, wp-login.php under it,
     * then under the site URL. Without: the site URL's wp-login.php, the URL
     * itself (a login path or wp-admin), then its directory's wp-login.php.
     *
     * @return list<string>
     */
    public static function loginCandidates(SourceAccess $access): array
    {
        $url = rtrim($access->url, '/');
        if ($access->loginUrl === null) {
            return self::validUrls([$url.'/wp-login.php', $url, dirname($url).'/wp-login.php']);
        }

        $login = rtrim($access->loginUrl, '/');

        return self::validUrls([
            $login,
            dirname($login).'/wp-login.php',
            $login.'/wp-login.php',
            $url.'/wp-login.php',
        ]);
    }

    /**
     * @return list<string>
     */
    public static function pluginBases(string $url): array
    {
        $url = rtrim($url, '/');

        return self::validUrls([$url, dirname($url)]);
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private static function validUrls(array $urls): array
    {
        $valid = [];
        foreach (array_unique($urls) as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (filter_var($url, FILTER_VALIDATE_URL) !== false && is_string($host) && $host !== '') {
                $valid[] = $url;
            }
        }

        return $valid;
    }

    /**
     * The index's own home when it is the same host as where it was found
     * (`?rest_route=/` answers on any front-end path), else that base.
     *
     * @param  array<array-key, mixed>  $index
     */
    private static function indexHome(array $index, string $base): string
    {
        $home = $index['home'] ?? null;
        if (! is_string($home) || filter_var($home, FILTER_VALIDATE_URL) === false) {
            return rtrim($base, '/');
        }

        $baseHost = parse_url($base, PHP_URL_HOST);
        $homeHost = parse_url($home, PHP_URL_HOST);
        if (! is_string($baseHost) || ! is_string($homeHost) || ! SourceIpPin::sameSite($baseHost, $homeHost)) {
            return rtrim($base, '/');
        }

        return rtrim($home, '/');
    }

    private static function sameUrl(mixed $a, string $b): bool
    {
        return is_string($a) && strcasecmp(rtrim($a, '/'), rtrim($b, '/')) === 0;
    }
}
