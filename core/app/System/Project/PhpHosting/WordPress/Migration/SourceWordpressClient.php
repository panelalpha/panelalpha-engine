<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use RuntimeException;
use Throwable;

/**
 * Source-site wp-admin + migrator-plugin client. Cookies stay in the HTTP
 * transport (process memory). Never logs the wp-admin password or migrator token.
 *
 * The plugin's transfer routes need no wp-admin session, only the site
 * ({@see useSite}); installing, activating and deleting the plugin need one
 * ({@see logIn}).
 */
final class SourceWordpressClient
{
    public const PLUGIN_SLUG = 'wordpress-migrator';

    public const PLUGIN_NAMESPACE = 'wp-migrator/v1';

    private const TOKEN_CHECK_MIGRATION_ID = 'panelalpha-token-check';

    private string $homeUrl = '';

    private string $restMode = SourceSite::REST_WP_JSON;

    private bool $adminSession = false;

    private int $batchRetrySleepSeconds = 5;

    public function __construct(
        private SourceHttpClient $http = new CurlSourceHttpClient,
    ) {}

    public function http(): SourceHttpClient
    {
        return $this->http;
    }

    public function homeUrl(): string
    {
        return $this->homeUrl;
    }

    /**
     * Talk to this site's REST API from now on. Keeps an existing wp-admin
     * session only when it belongs to the same site.
     */
    public function useSite(SourceSite $site): void
    {
        if ($site->url !== $this->homeUrl) {
            $this->adminSession = false;
        }

        $this->homeUrl = $site->url;
        $this->restMode = $site->restMode;
    }

    public function hasAdminSession(): bool
    {
        return $this->adminSession;
    }

    /**
     * Tests may set this to 0 so fixture retries do not sleep.
     */
    public function setBatchRetrySleepSeconds(int $seconds): void
    {
        $this->batchRetrySleepSeconds = max(0, $seconds);
    }

    /**
     * Build the JSON body for POST …/remote-sync.
     * destination_url is the destination site URL (incl. subdirectory) plus ?token=.
     * ip, when given, is where the plugin sends the destination's name
     * (CURLOPT_RESOLVE, TLS unverified), so the transfer reaches this engine
     * while the domain's DNS still points at the old server.
     *
     * @param  list<string>  $extraExclusions
     * @return array{
     *     destination_url: string,
     *     backup_database: true,
     *     backup_files: true,
     *     exclusions: string,
     *     force: true,
     *     ip?: string,
     * }
     */
    public static function remoteSyncPayload(
        string $destinationSiteUrl,
        string $token,
        array $extraExclusions = ['wp-content/cache'],
        ?string $destinationIp = null,
    ): array {
        $payload = [
            'destination_url' => rtrim($destinationSiteUrl, '/').'?token='.$token,
            'backup_database' => true,
            'backup_files' => true,
            'exclusions' => implode(',', [
                '*.log',
                'node_modules',
                '*.sql',
                ...$extraExclusions,
            ]),
            'force' => true,
        ];

        return $destinationIp === null ? $payload : $payload + ['ip' => $destinationIp];
    }

    /**
     * Log into wp-admin at the site's login URL. On success the ajax nonce is
     * returned; cookies stay on $this->http.
     *
     * @throws SourceCheckFailed on bad credentials, a missing nonce or a Cloudflare block
     */
    public function logIn(SourceSite $site, string $username, string $password): string
    {
        $this->useSite($site);
        $home = $site->url;
        $loginUrl = $site->loginUrl ?? $home.'/wp-login.php';

        $query = http_build_query([
            'log' => $username,
            'pwd' => html_entity_decode($password),
            'wp-submit' => 'Log In',
        ]);

        $postResponse = $this->http->request('POST', $loginUrl, $query);
        if (WpAdminHtml::isCloudflareBlocked($postResponse->body)) {
            throw SourceCheckFailed::cloudflare();
        }

        if (WpAdminHtml::isEmailVerificationAlert($postResponse->body)) {
            $this->submitEmailVerification($postResponse->body, $loginUrl);
            $this->http->request('POST', $loginUrl, $query);
        }

        $admin = $this->http->request('GET', $home.'/wp-admin');
        $nonce = WpAdminHtml::ajaxNonce($admin->body);
        if ($nonce === '') {
            throw SourceCheckFailed::loginFailed();
        }

        $this->adminSession = true;

        return $nonce;
    }

    /**
     * The REST index at a base URL in the given mode, or null when it is not a REST index.
     *
     * @return array<array-key, mixed>|null
     */
    public function restIndex(string $baseUrl, string $mode): ?array
    {
        $response = $this->http->request('GET', SourceSite::restUrlFor($baseUrl, $mode, '/'));
        if ($response->status < 200 || $response->status >= 300) {
            return null;
        }

        try {
            return $response->json();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ask the plugin's own token check (`/info` with X-Migrator-Token) whether
     * it accepts the token. Changes nothing on the source.
     *
     * @throws SourceCheckFailed unauthorized for a refused token, plugin_not_found without the route
     */
    public function assertPluginAcceptsToken(string $token): void
    {
        $this->assertSite();

        $response = $this->http->request(
            'GET',
            $this->endpoint('/wp-migrator/v1/info', ['migration_id' => self::TOKEN_CHECK_MIGRATION_ID]),
            null,
            ['X-Migrator-Token' => $token],
        );

        if ($response->status === 401 || $response->status === 403) {
            throw SourceCheckFailed::unauthorized();
        }
        if (! self::pluginAnswered($response)) {
            throw SourceCheckFailed::pluginNotFound();
        }
    }

    /**
     * Past the permission check, `/info` answers the made-up migration id with
     * its own not-found error; a missing route is WordPress's `rest_no_route`.
     */
    private static function pluginAnswered(SourceHttpResponse $response): bool
    {
        if ($response->status >= 200 && $response->status < 300) {
            return true;
        }

        $code = self::restErrorCode($response);

        return $response->status >= 400 && $response->status < 500
            && $code !== null && $code !== 'rest_no_route';
    }

    /**
     * Upload and leave the migrator plugin installed (active or inactive is fine;
     * call {@see activatePlugin} next).
     */
    public function installPluginFromZip(string $zipPath): void
    {
        $this->assertAdminSession();

        $list = $this->http->request('POST', $this->homeUrl.'/wp-admin/plugins.php');
        $plugins = WpAdminHtml::plugins($list->body);
        if (isset($plugins['active'][self::PLUGIN_SLUG]) || isset($plugins['inactive'][self::PLUGIN_SLUG])) {
            return;
        }

        $uploadPage = $this->http->request(
            'GET',
            $this->homeUrl.'/wp-admin/plugin-install.php?tab=upload',
        );
        $inputs = WpAdminHtml::inputs($uploadPage->body);
        $nonce = $inputs['_wpnonce'] ?? null;
        if (! is_string($nonce) || $nonce === '') {
            throw new RuntimeException(
                'Could not retrieve nonce for plugin upload. Make sure you are logged in.'
            );
        }

        if (! is_file($zipPath)) {
            throw new RuntimeException('Migrator install zip is missing: '.$zipPath);
        }

        $this->http->postMultipart(
            $this->homeUrl.'/wp-admin/update.php?action=upload-plugin',
            [
                '_wpnonce' => $nonce,
                '_wp_http_referer' => '/wp-admin/plugin-install.php',
                'pluginzip' => new \CURLFile($zipPath, 'application/x-zip-compressed', self::PLUGIN_SLUG.'.zip'),
                'install-plugin-submit' => 'Install Now',
            ],
        );

        $list = $this->http->request('POST', $this->homeUrl.'/wp-admin/plugins.php');
        $plugins = WpAdminHtml::plugins($list->body);
        if (! isset($plugins['inactive'][self::PLUGIN_SLUG]) && ! isset($plugins['active'][self::PLUGIN_SLUG])) {
            throw new RuntimeException('Source plugin install did not leave wordpress-migrator installed');
        }
    }

    public function activatePlugin(string $slug = self::PLUGIN_SLUG): void
    {
        $this->assertAdminSession();

        $list = $this->http->request('POST', $this->homeUrl.'/wp-admin/plugins.php');
        $plugins = WpAdminHtml::plugins($list->body);

        if (isset($plugins['active'][$slug])) {
            return;
        }
        if (! isset($plugins['inactive'][$slug])) {
            throw new RuntimeException('Plugin "'.$slug.'" is not installed on the source site');
        }

        $this->http->request('POST', $this->homeUrl.'/wp-admin/'.$plugins['inactive'][$slug]);

        $list = $this->http->request('POST', $this->homeUrl.'/wp-admin/plugins.php');
        $plugins = WpAdminHtml::plugins($list->body);
        if (! isset($plugins['active'][$slug])) {
            throw new RuntimeException('Plugin "'.$slug.'" could not be activated on the source site');
        }
    }

    public function deletePlugin(string $slug = self::PLUGIN_SLUG): void
    {
        $this->assertAdminSession();

        $list = $this->http->request('POST', $this->homeUrl.'/wp-admin/plugins.php');
        $plugins = WpAdminHtml::plugins($list->body);

        if (isset($plugins['active'][$slug])) {
            $this->http->request('POST', $this->homeUrl.'/wp-admin/'.$plugins['active'][$slug]);
            $list = $this->http->request('POST', $this->homeUrl.'/wp-admin/plugins.php');
            $ajaxNonce = WpAdminHtml::ajaxNonce($list->body);
        } elseif (isset($plugins['inactive'][$slug])) {
            $ajaxNonce = WpAdminHtml::ajaxNonce($list->body);
        } else {
            return;
        }

        $pluginFile = $slug.'/'.$slug.'.php';
        $query = http_build_query([
            'action' => 'delete-plugin',
            'plugin' => $pluginFile,
            'slug' => $slug,
            '_ajax_nonce' => $ajaxNonce,
        ]);
        $this->http->request('POST', $this->homeUrl.'/wp-admin/admin-ajax.php', $query);
    }

    /**
     * POST remote-sync. Returns the plugin response including migration_id.
     *
     * @param  list<string>  $extraExclusions
     * @return array<array-key, mixed>
     */
    public function remoteSync(
        string $destinationSiteUrl,
        string $token,
        array $extraExclusions = ['wp-content/cache'],
        ?string $destinationIp = null,
    ): array {
        $this->assertSite();

        $params = self::remoteSyncPayload($destinationSiteUrl, $token, $extraExclusions, $destinationIp);

        try {
            return $this->postJson($this->endpoint('/wp-migrator/v1/remote-sync'), $params);
        } catch (Throwable $e) {
            $this->changeRestMode();

            return $this->postJson($this->endpoint('/wp-migrator/v1/remote-sync'), $params);
        }
    }

    /**
     * One process-batch call with the panel's 3-attempt retry; an aborted request is not retried.
     *
     * @return array{
     *     finalized: bool,
     *     current_phase: string|null,
     *     current_item: string|null,
     *     current_phase_percent: float,
     *     next_item: string|null,
     *     next_phase: string|null,
     *     sent_chunks: int,
     *     total_chunks: int,
     * }
     */
    public function processBatch(string $migrationId): array
    {
        $this->assertSite();

        $result = [
            'finalized' => false,
            'current_phase' => null,
            'current_item' => null,
            'current_phase_percent' => 0.00,
            'next_item' => null,
            'next_phase' => null,
            'sent_chunks' => 0,
            'total_chunks' => 0,
        ];

        $attempts = 3;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $response = $this->runProcessBatch($migrationId);
                $progress = $response['progress'] ?? [];
                $result['finalized'] = (bool) ($response['finalized'] ?? false);
                $result['current_phase'] = isset($response['phase']) && is_string($response['phase'])
                    ? $response['phase']
                    : null;
                $result['current_item'] = is_array($progress)
                    ? ($progress['current_item'] ?? null)
                    : null;
                $result['next_item'] = is_array($progress)
                    ? ($progress['next_item'] ?? null)
                    : null;
                $result['current_phase_percent'] = is_array($progress)
                    ? (float) ($progress['percentage'] ?? 0.00)
                    : 0.00;
                $result['next_phase'] = isset($response['next_phase']) && is_string($response['next_phase'])
                    ? $response['next_phase']
                    : null;
                $result['sent_chunks'] = self::chunkCount($progress, 'sent_chunks');
                $result['total_chunks'] = self::chunkCount($progress, 'total_chunks');

                return $result;
            } catch (Throwable $e) {
                if ($i === $attempts - 1 || $e instanceof SourceRequestAborted) {
                    throw $e;
                }
                if ($this->batchRetrySleepSeconds > 0) {
                    sleep($this->batchRetrySleepSeconds);
                }
            }
        }

        return $result;
    }

    /**
     * Ask the plugin to stop the migration (`backup/stop`), with the token its
     * config.php holds; it sends nothing more until asked again.
     */
    public function stopMigration(string $migrationId, string $token): void
    {
        $this->assertSite();

        $response = $this->http->request(
            'POST',
            $this->endpoint('/wp-migrator/v1/backup/stop'),
            json_encode(['migration_id' => $migrationId], JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json', 'X-Migrator-Token' => $token],
        );
        $this->parsePluginResponse($response);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function migrationStatus(string $migrationId): array
    {
        $this->assertSite();

        try {
            $response = $this->getJson(
                $this->endpoint('/wp-migrator/v1/remote-sync/status/'.rawurlencode($migrationId))
            );
        } catch (Throwable $e) {
            $this->changeRestMode();
            $response = $this->getJson(
                $this->endpoint('/wp-migrator/v1/remote-sync/status/'.rawurlencode($migrationId))
            );
        }

        $status = $response['status'] ?? [];

        return is_array($status) ? $status : [];
    }

    /**
     * @return bool true when a file was transferred; false when success but not transferred
     */
    public function transferHtaccess(string $migrationId): bool
    {
        $this->assertSite();

        $params = ['migration_id' => $migrationId];
        try {
            $response = $this->postJson(
                $this->endpoint('/wp-migrator/v1/transfer-htaccess'),
                $params,
            );
        } catch (Throwable $e) {
            $this->changeRestMode();
            $response = $this->postJson(
                $this->endpoint('/wp-migrator/v1/transfer-htaccess'),
                $params,
            );
        }

        if (($response['success'] ?? false) === false) {
            throw new RuntimeException(
                is_string($response['message'] ?? null)
                    ? $response['message']
                    : 'Failed to transfer .htaccess file.'
            );
        }

        if (($response['success'] ?? false) === true && ($response['transferred'] ?? false) === false) {
            return false;
        }

        return true;
    }

    private static function chunkCount(mixed $progress, string $key): int
    {
        return is_array($progress) && is_numeric($progress[$key] ?? null) ? (int) $progress[$key] : 0;
    }

    private function changeRestMode(): void
    {
        $this->restMode = $this->restMode === SourceSite::REST_WP_JSON
            ? SourceSite::REST_QUERY
            : SourceSite::REST_WP_JSON;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function endpoint(string $route, array $query = []): string
    {
        return SourceSite::restUrlFor($this->homeUrl, $this->restMode, $route, $query);
    }

    private static function restErrorCode(SourceHttpResponse $response): ?string
    {
        try {
            $code = $response->json()['code'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($code) ? $code : null;
    }

    /**
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     */
    private function postJson(string $url, array $params): array
    {
        $body = json_encode($params, JSON_THROW_ON_ERROR);
        $response = $this->http->request('POST', $url, $body, [
            'Content-Type' => 'application/json',
        ]);

        return $this->parsePluginResponse($response);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function getJson(string $url): array
    {
        $response = $this->http->request('GET', $url);

        return $this->parsePluginResponse($response);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function runProcessBatch(string $migrationId): array
    {
        $params = ['migration_id' => $migrationId];
        try {
            return $this->postJson(
                $this->endpoint('/wp-migrator/v1/remote-sync/process-batch'),
                $params,
            );
        } catch (Throwable $e) {
            $this->changeRestMode();

            return $this->postJson(
                $this->endpoint('/wp-migrator/v1/remote-sync/process-batch'),
                $params,
            );
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parsePluginResponse(SourceHttpResponse $response): array
    {
        $decoded = $response->json();
        $httpCode = $response->status;

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = isset($decoded['message']) && is_string($decoded['message'])
                ? strip_tags($decoded['message'])
                : 'Unknown error from migrator plugin';
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    private function assertSite(): void
    {
        if ($this->homeUrl === '') {
            throw new RuntimeException('Source WordPress site is not resolved');
        }
    }

    private function assertAdminSession(): void
    {
        if (! $this->adminSession) {
            throw new RuntimeException('Source WordPress client is not logged in');
        }
    }

    private function submitEmailVerification(string $response, string $loginUrl): void
    {
        $inputs = WpAdminHtml::inputs($response);
        if (
            ! isset(
                $inputs['confirm_admin_email_nonce'],
                $inputs['_wp_http_referer'],
                $inputs['redirect_to'],
            )
        ) {
            return;
        }

        $query = http_build_query([
            'confirm_admin_email_nonce' => $inputs['confirm_admin_email_nonce'],
            'redirect_to' => $inputs['redirect_to'],
            '_wp_http_referer' => $inputs['_wp_http_referer'],
            'correct-admin-email' => 'The+email+is+correct',
        ], '', '&', PHP_QUERY_RFC3986);

        $this->http->request(
            'POST',
            $loginUrl.'?action=confirm_admin_email',
            $query,
        );
    }
}
