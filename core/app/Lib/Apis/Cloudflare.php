<?php

namespace App\Lib\Apis;

use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\Cloudflare\ZoneNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin Cloudflare API client for remotely-managed tunnels + DNS CNAMEs.
 */
class Cloudflare
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    /** The values Cloudflare accepts for the zone's `security_level` setting. */
    public const array SECURITY_LEVELS = ['off', 'essentially_off', 'low', 'medium', 'high', 'under_attack'];

    private string $apiToken;

    public function __construct(string $apiToken)
    {
        $this->apiToken = trim($apiToken);
    }

    /**
     * The tunnel's account and, if told, its name: `GET /accounts` first, as documented, refusing several and working
     * with no zones; else a zone's `account.id`, as a token made per Cloudflare's tunnel guide may lack /accounts.
     *
     * @return array{id: string, name: string}
     *
     * @throws CloudflareException
     */
    public function resolveAccount(): array
    {
        $refused = null;

        $fromList = $this->accountFromList($refused);
        if ($fromList !== null) {
            return $fromList;
        }

        $fromZone = $this->accountFromZone($refused);
        if ($fromZone !== null) {
            return $fromZone;
        }

        $this->failWithoutAccount($refused);
    }

    /**
     * A token Cloudflare refused outright gets Cloudflare's own sentence, not the Account Resources advice for a token
     * scoped too narrowly; that advice names both routes, or a missing permission reads as a missing zone.
     */
    private function failWithoutAccount(?CloudflareException $refused): never
    {
        if ($refused !== null) {
            throw new CloudflareException(
                'Cloudflare rejected this API token: '.$refused->getMessage(),
                previous: $refused
            );
        }

        throw new CloudflareException(
            'Cloudflare API token has no accessible accounts. Check that it selects an '
            .'account under Account Resources, and that Zone Resources includes at least '
            .'one zone in that account -- the engine can take the account id from either.'
        );
    }

    /**
     * `GET /accounts`, or null when it is empty or refused: a 403 is what the zone route is for, so it is not thrown but
     * kept in $refused, for {@see resolveAccount()} to tell "Cloudflare said no" from "the token sees nothing here".
     *
     * @return array{id: string, name: string}|null
     */
    private function accountFromList(?CloudflareException &$refused = null): ?array
    {
        try {
            $json = $this->get('/accounts', ['per_page' => 50]);
        } catch (CloudflareException $e) {
            $refused ??= $e;

            return null;
        }

        $accounts = self::listedAccounts($json['result'] ?? null);
        if ($accounts === []) {
            return null;
        }

        self::assertOneAccount($accounts);

        $id = (string) array_key_first($accounts);

        return ['id' => $id, 'name' => $accounts[$id]];
    }

    /** @return array<string, string> account id => name */
    private static function listedAccounts(mixed $accounts): array
    {
        $listed = [];
        foreach (is_array($accounts) ? $accounts : [] as $account) {
            $id = is_array($account) ? (string) ($account['id'] ?? '') : '';
            if ($id !== '') {
                $listed[$id] = (string) ($account['name'] ?? '');
            }
        }

        return $listed;
    }

    /**
     * The account id a visible zone names, which the zone access this feature needs already exposes, or null. Any zone
     * will do, not the longest-suffix match: a token scoped to one account sees only that account's zones.
     *
     * @return array{id: string, name: string}|null
     */
    private function accountFromZone(?CloudflareException &$refused = null): ?array
    {
        try {
            $json = $this->get('/zones', ['per_page' => 50]);
        } catch (CloudflareException $e) {
            $refused ??= $e;

            return null;
        }

        $zones = $json['result'] ?? [];
        if (! is_array($zones)) {
            return null;
        }

        $accounts = self::zoneAccounts($zones);

        if ($accounts === []) {
            return null;
        }

        self::assertOneAccount($accounts);

        $id = (string) array_key_first($accounts);

        return ['id' => $id, 'name' => $accounts[$id]];
    }

    /**
     * @param  array<mixed>  $zones
     * @return array<string, string> account id => name
     */
    private static function zoneAccounts(array $zones): array
    {
        $accounts = [];
        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            $account = $zone['account'] ?? null;
            if (! is_array($account)) {
                continue;
            }
            $id = (string) ($account['id'] ?? '');
            if ($id !== '') {
                $accounts[$id] = (string) ($account['name'] ?? '');
            }
        }

        return $accounts;
    }

    /**
     * Several accounts are refused, not guessed: the tunnel would go to the first one and the CNAME, picked by longest
     * suffix, into a zone of another, and the hostname would silently never route.
     *
     * @param  array<string, string>  $accounts
     */
    private static function assertOneAccount(array $accounts): void
    {
        if (count($accounts) > 1) {
            throw new CloudflareException(
                'This API token can see zones in more than one Cloudflare account ('
                .implode(', ', array_map(
                    static fn (string $id, string $name): string => $name !== '' ? "{$name} [{$id}]" : $id,
                    array_keys($accounts),
                    $accounts
                ))
                .'), so the engine cannot tell which one the tunnel belongs in. Give the token '
                .'Account Resources for the account you want, so /accounts answers directly, or '
                .'scope Zone Resources to zones in that one account.'
            );
        }
    }

    /**
     * The hostname's zone, asked for by name from the longest parent down (a.b.example.com, then
     * b.example.com, then example.com), so it is found however many zones the token can see.
     *
     * @return array{id: string, name: string}
     */
    public function findZoneForHostname(string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        foreach (self::zoneCandidates($hostname) as $candidate) {
            $zone = $this->zoneNamed($candidate);
            if ($zone !== null) {
                return $zone;
            }
        }

        throw new ZoneNotFoundException(
            "Hostname '{$hostname}' is not under any Cloudflare zone this API token can read. Check that "
            .'it has Zone > Zone > Read, that Zone Resources includes the zone this hostname is under, '
            .'and that the zone belongs to the account the token selects.'
        );
    }

    /**
     * The hostname and each parent name that still has two labels.
     *
     * @return list<string>
     */
    private static function zoneCandidates(string $hostname): array
    {
        $labels = explode('.', $hostname);

        return array_map(
            static fn (int $first): string => implode('.', array_slice($labels, $first)),
            range(0, max(0, count($labels) - 2))
        );
    }

    /**
     * @return ?array{id: string, name: string}
     */
    private function zoneNamed(string $name): ?array
    {
        $zones = $this->get('/zones', ['name' => $name])['result'] ?? [];
        foreach (is_array($zones) ? $zones : [] as $zone) {
            if (is_array($zone) && strtolower((string) ($zone['name'] ?? '')) === $name && ($zone['id'] ?? '') !== '') {
                return ['id' => (string) $zone['id'], 'name' => $name];
            }
        }

        return null;
    }

    /**
     * @return array{id: string, name: string, token: string}
     */
    public function findTunnelByName(string $accountId, string $name): ?array
    {
        $json = $this->get("/accounts/{$accountId}/cfd_tunnel", [
            'name' => $name,
            'is_deleted' => 'false',
            'per_page' => 50,
        ]);
        $tunnels = $json['result'] ?? [];
        if (! is_array($tunnels)) {
            return null;
        }

        foreach ($tunnels as $tunnel) {
            if (! is_array($tunnel)) {
                continue;
            }
            if (($tunnel['name'] ?? null) !== $name) {
                continue;
            }
            $id = (string) ($tunnel['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $token = $this->getTunnelToken($accountId, $id);

            return ['id' => $id, 'name' => $name, 'token' => $token];
        }

        return null;
    }

    /**
     * @return array{id: string, name: string, token: string}
     */
    public function createTunnel(string $accountId, string $name): array
    {
        $json = $this->post("/accounts/{$accountId}/cfd_tunnel", [
            'name' => $name,
            'config_src' => 'cloudflare',
        ]);
        $result = $json['result'] ?? [];
        if (! is_array($result)) {
            throw new CloudflareException('Unexpected response creating Cloudflare tunnel.');
        }
        $id = (string) ($result['id'] ?? '');
        $token = (string) ($result['token'] ?? '');
        if ($id === '') {
            throw new CloudflareException('Cloudflare tunnel id missing from create response.');
        }
        if ($token === '') {
            $token = $this->getTunnelToken($accountId, $id);
        }

        return ['id' => $id, 'name' => $name, 'token' => $token];
    }

    public function getTunnelToken(string $accountId, string $tunnelId): string
    {
        $json = $this->get("/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/token");
        $token = $json['result'] ?? null;
        if (! is_string($token) || trim($token) === '') {
            throw new CloudflareException("Could not fetch token for Cloudflare tunnel '{$tunnelId}'.");
        }

        return trim($token);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getIngress(string $accountId, string $tunnelId): array
    {
        $json = $this->get("/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations");
        $result = $json['result'] ?? [];
        if (! is_array($result)) {
            return [];
        }
        $config = $result['config'] ?? [];
        if (! is_array($config)) {
            return [];
        }
        $ingress = $config['ingress'] ?? [];

        return is_array($ingress) ? array_values($ingress) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $ingress
     */
    public function putIngress(string $accountId, string $tunnelId, array $ingress): void
    {
        $this->put("/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations", [
            'config' => [
                'ingress' => $ingress,
            ],
        ]);
    }

    /**
     * @return array{id: string, name: string, content: string}
     */
    public function ensureDnsCname(
        string $zoneId,
        string $hostname,
        string $tunnelId
    ): array {
        $target = "{$tunnelId}.cfargotunnel.com";
        $existing = $this->findDnsRecord($zoneId, $hostname);
        if ($existing !== null) {
            if (
                strtoupper((string) ($existing['type'] ?? '')) === 'CNAME'
                && strtolower((string) ($existing['content'] ?? '')) === strtolower($target)
                && ! empty($existing['proxied'])
            ) {
                return [
                    'id' => (string) $existing['id'],
                    'name' => (string) ($existing['name'] ?? $hostname),
                    'content' => $target,
                ];
            }
            $json = $this->put("/zones/{$zoneId}/dns_records/{$existing['id']}", self::cnameRecord($hostname, $target));
        } else {
            $json = $this->post("/zones/{$zoneId}/dns_records", self::cnameRecord($hostname, $target));
        }

        return self::upsertedCname($json, $hostname, $target);
    }

    /** @return array<string, mixed> */
    private static function cnameRecord(string $hostname, string $target): array
    {
        return [
            'type' => 'CNAME',
            'name' => $hostname,
            'content' => $target,
            'proxied' => true,
            'ttl' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{id: string, name: string, content: string}
     */
    private static function upsertedCname(array $json, string $hostname, string $target): array
    {
        $result = $json['result'] ?? [];
        if (! is_array($result) || empty($result['id'])) {
            throw new CloudflareException("Failed to upsert DNS CNAME for '{$hostname}'.");
        }

        return [
            'id' => (string) $result['id'],
            'name' => (string) ($result['name'] ?? $hostname),
            'content' => $target,
        ];
    }

    /** A record that is already gone is fine. */
    public function deleteDnsRecord(string $zoneId, string $recordId): void
    {
        try {
            $this->delete("/zones/{$zoneId}/dns_records/{$recordId}");
        } catch (CloudflareException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'could not find')
                && ! str_contains(strtolower($e->getMessage()), 'not found')
            ) {
                throw $e;
            }
        }
    }

    public function deleteTunnel(string $accountId, string $tunnelId): void
    {
        try {
            $this->delete("/accounts/{$accountId}/cfd_tunnel/{$tunnelId}");
        } catch (CloudflareException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'not found')) {
                throw $e;
            }
        }
    }

    /**
     * @return ?array{id: string, type: string, name: string, content: string, proxied: bool}
     */
    public function findDnsRecord(string $zoneId, string $hostname): ?array
    {
        $json = $this->get("/zones/{$zoneId}/dns_records", [
            'name' => strtolower($hostname),
            'per_page' => 50,
        ]);
        $records = $json['result'] ?? [];
        if (! is_array($records)) {
            return null;
        }
        foreach ($records as $record) {
            if (! is_array($record) || empty($record['id'])) {
                continue;
            }

            return [
                'id' => (string) $record['id'],
                'type' => (string) ($record['type'] ?? ''),
                'name' => (string) ($record['name'] ?? $hostname),
                'content' => (string) ($record['content'] ?? ''),
                'proxied' => (bool) ($record['proxied'] ?? false),
            ];
        }

        return null;
    }

    /**
     * Purge a zone's cached assets: everything, or the listed URLs.
     *
     * @param  list<string>  $files
     */
    public function purgeCache(string $zoneId, bool $everything, array $files = []): void
    {
        $this->post(
            "/zones/{$zoneId}/purge_cache",
            $everything ? ['purge_everything' => true] : ['files' => array_values($files)]
        );
    }

    /** One zone setting, e.g. `security_level` or `development_mode`. */
    public function getSetting(string $zoneId, string $name): string
    {
        return $this->settingValue(
            $this->get("/zones/{$zoneId}/settings/{$name}"),
            $zoneId,
            $name
        );
    }

    /** Set one zone setting and answer its new value. */
    public function setSetting(string $zoneId, string $name, string $value): string
    {
        return $this->settingValue(
            $this->patch("/zones/{$zoneId}/settings/{$name}", ['value' => $value]),
            $zoneId,
            $name
        );
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function settingValue(array $json, string $zoneId, string $name): string
    {
        $result = $json['result'] ?? [];
        if (! is_array($result) || ! array_key_exists('value', $result)) {
            throw new CloudflareException(
                "Cloudflare did not answer with the '{$name}' setting for zone '{$zoneId}'."
            );
        }

        return (string) $result['value'];
    }

    /**
     * Build ingress list with catch-all last, replacing any existing rule for hostname and dropping the old catch-all.
     *
     * @param  list<array<string, mixed>>  $existing
     * @param  array<string, mixed>|\stdClass  $originRequest
     * @return list<array<string, mixed>>
     */
    public static function upsertHostnameIngress(
        array $existing,
        string $hostname,
        string $service,
        array|\stdClass $originRequest = new \stdClass,
    ): array {
        $hostname = strtolower(trim($hostname));
        $rules = [];
        foreach ($existing as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if (! isset($rule['hostname']) || $rule['hostname'] === '' || $rule['hostname'] === null) {
                continue;
            }
            if (strtolower((string) $rule['hostname']) === $hostname) {
                continue;
            }
            $rules[] = $rule;
        }
        $rules[] = [
            'hostname' => $hostname,
            'service' => $service,
            'originRequest' => $originRequest,
        ];
        $rules[] = ['service' => 'http_status:404'];

        return $rules;
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    public static function removeHostnameIngress(array $existing, string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $rules = [];
        foreach ($existing as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if (! isset($rule['hostname']) || $rule['hostname'] === '' || $rule['hostname'] === null) {
                continue;
            }
            if (strtolower((string) $rule['hostname']) === $hostname) {
                continue;
            }
            $rules[] = $rule;
        }
        $rules[] = ['service' => 'http_status:404'];

        return $rules;
    }

    /**
     * Map CLI-style upstream host:port (inside DinD) to a cloudflared service URL.
     */
    public static function originServiceUrl(int $port): string
    {
        return "http://127.0.0.1:{$port}";
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send($this->http()->get(self::API_BASE.$path, $query));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->send($this->http()->post(self::API_BASE.$path, $body));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function put(string $path, array $body): array
    {
        return $this->send($this->http()->put(self::API_BASE.$path, $body));
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $path): array
    {
        return $this->send($this->http()->delete(self::API_BASE.$path));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function patch(string $path, array $body): array
    {
        return $this->send($this->http()->patch(self::API_BASE.$path, $body));
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(Response $response): array
    {
        try {
            $response->throw();
        } catch (RequestException $e) {
            $body = $response->json();
            $errors = is_array($body) ? ($body['errors'] ?? null) : null;
            $message = self::requestFailedMessage($errors, $response);
            throw new CloudflareException($message, $response->status(), $e, self::errorsOf($errors));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new CloudflareException('Cloudflare API returned a non-JSON response.');
        }
        if (($json['success'] ?? true) === false) {
            $errors = $json['errors'] ?? [];
            throw new CloudflareException(
                self::apiErrorMessage($errors),
                $response->status(),
                errors: self::errorsOf($errors)
            );
        }

        return $json;
    }

    private static function requestFailedMessage(mixed $errors, Response $response): string
    {
        $message = 'Cloudflare API request failed';
        if (is_array($errors) && $errors !== []) {
            $parts = [];
            foreach ($errors as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $parts[] = (string) $error['message'];
                }
            }
            if ($parts !== []) {
                $message .= ': '.implode('; ', $parts);
            }
        } else {
            $message .= ' (HTTP '.$response->status().')';
        }

        return $message;
    }

    private static function apiErrorMessage(mixed $errors): string
    {
        $parts = [];
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $parts[] = (string) $error['message'];
                }
            }
        }

        return 'Cloudflare API error'.($parts !== [] ? ': '.implode('; ', $parts) : '');
    }

    /**
     * Cloudflare's errors as code and message, with the ones their error chains add.
     *
     * @return list<array{code: int, message: string}>
     */
    private static function errorsOf(mixed $errors): array
    {
        if (! is_array($errors)) {
            return [];
        }

        $found = [];
        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }
            $found[] = [
                'code' => is_numeric($error['code'] ?? null) ? (int) $error['code'] : 0,
                'message' => is_scalar($error['message'] ?? null) ? (string) $error['message'] : '',
            ];
            array_push($found, ...self::errorsOf($error['error_chain'] ?? null));
        }

        return $found;
    }
}
