<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\WordpressMigration;
use Closure;
use RuntimeException;

/**
 * The source site for one job run, shared by every step of that run. The site
 * is found once and stored on the record; a wp-admin session is opened at most
 * once per run, and only where wp-admin is needed. Transfer calls carry the
 * plugin's token ({@see transfer}). While a run is bound ({@see keepAlive}),
 * every source call it makes keeps the run's claim alive.
 */
final class SourceConnection
{
    /** @var Closure(SourceAccess): SourceHttpClient */
    private Closure $httpFactory;

    private ?SourceWordpressClient $client = null;

    private ?int $migrationId = null;

    private ?RunHeartbeat $heartbeat = null;

    /**
     * @param  (Closure(SourceAccess): SourceHttpClient)|null  $httpFactory
     */
    public function __construct(?Closure $httpFactory = null)
    {
        $this->httpFactory = $httpFactory
            ?? static fn (SourceAccess $access): SourceHttpClient => new CurlSourceHttpClient($access->pin());
    }

    /**
     * Keep this run's claim alive from inside every source call; null once the run ends.
     */
    public function keepAlive(?RunHeartbeat $heartbeat): void
    {
        $this->heartbeat = $heartbeat;
        $this->client?->http()->whileWaiting($this->keepWaiting());
    }

    /**
     * A client on this record's source site: found and stored on the first
     * run, read back from the record after that.
     */
    public function open(WordpressMigration $migration): SourceWordpressClient
    {
        $client = $this->clientFor($migration);
        if ($client->homeUrl() !== '') {
            return $client;
        }

        $site = self::storedSite($migration) ?? $this->find($client, $migration);
        $client->useSite($site);

        return $client;
    }

    /**
     * A client for the plugin's transfer routes, sending the record's source
     * token on every call.
     */
    public function transfer(WordpressMigration $migration): SourceWordpressClient
    {
        $client = $this->open($migration);
        $access = SourceAccess::of($migration);
        self::withSecret($access, $access->sourceToken, 'source token');
        $client->useSourceToken((string) $access->sourceToken);

        return $client;
    }

    /**
     * A client logged into the source wp-admin (wp_admin migrations only).
     */
    public function admin(WordpressMigration $migration): SourceWordpressClient
    {
        if ($migration->usesManualPlugin()) {
            throw new RuntimeException('A manual_plugin migration has no wp-admin access to the source site');
        }

        $client = $this->open($migration);
        if (! $client->hasAdminSession()) {
            $access = SourceAccess::of($migration);
            self::withSecret($access, $access->password, 'wp-admin password');
            $site = self::storedSite($migration) ?? new SourceSite($client->homeUrl());
            $client->logIn($site, (string) $access->username, (string) $access->password);
        }

        return $client;
    }

    private function find(SourceWordpressClient $client, WordpressMigration $migration): SourceSite
    {
        $access = SourceAccess::of($migration);
        $resolver = new SourceSiteResolver($client);
        $site = $access->usesManualPlugin()
            ? $resolver->findPlugin(self::withSecret($access, $access->sourceToken, 'source token'))
            : $resolver->logIn(self::withSecret($access, $access->password, 'wp-admin password'));

        $migration->storeResolvedSource($site->url, $site->loginUrl, $site->restMode);

        return $site;
    }

    private function clientFor(WordpressMigration $migration): SourceWordpressClient
    {
        if ($this->client === null || $this->migrationId !== $migration->id) {
            $this->client = new SourceWordpressClient(($this->httpFactory)(SourceAccess::of($migration)));
            $this->client->http()->whileWaiting($this->keepWaiting());
            $this->migrationId = $migration->id;
        }

        return $this->client;
    }

    /**
     * @return (Closure(): bool)|null
     */
    private function keepWaiting(): ?Closure
    {
        return $this->heartbeat === null ? null : $this->heartbeat->keepAlive(...);
    }

    private static function storedSite(WordpressMigration $migration): ?SourceSite
    {
        if (! in_array($migration->rest_mode, SourceSite::REST_MODES, true)) {
            return null;
        }

        return new SourceSite($migration->source_url, $migration->login_url, $migration->rest_mode);
    }

    private static function withSecret(SourceAccess $access, ?string $secret, string $name): SourceAccess
    {
        if ($secret === null || $secret === '') {
            throw new RuntimeException("Cannot reach the source site: encrypted {$name} is missing");
        }

        return $access;
    }
}
