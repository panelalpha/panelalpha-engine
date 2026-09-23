<?php

namespace App\Lib\Apis;

use App\Lib\Apis\Cloudflare\CloudflareException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Thin Cloudflare API client for remotely-managed tunnels + DNS CNAMEs.
 */
class Cloudflare
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    private string $apiToken;

    public function __construct(string $apiToken)
    {
        $this->apiToken = trim($apiToken);
    }

    /**
     * The account a tunnel will be created in, and the account's name when
     * Cloudflare is willing to tell us it.
     *
     * Two routes, tried in that order, and the second is not a nicety.
     * `GET /accounts` is the obvious one and needs a permission Cloudflare's
     * own tunnel guide does not list -- so a token created exactly as
     * documented, with `Account: Cloudflare Tunnel Edit` and `Zone: DNS Edit`,
     * can be refused the account *object* while being perfectly able to manage
     * tunnels inside it:
     *
     *   GET /accounts                          200  result: []
     *   GET /accounts/{id}                     403  9109 Unauthorized
     *   GET /accounts/{id}/cfd_tunnel          200  works
     *
     * That is a working token failing before the engine has asked it to do
     * anything, which reads as a rejected token and is not one. The id is not
     * secret to a token that can see a zone in that account: `GET /zones`
     * returns each zone's `account.id`, and managing DNS in the zone is a
     * permission this feature already requires.
     *
     * The list is tried first, deliberately. It is the route Cloudflare
     * documents, it says which account the token belongs to when the token can
     * see several, and it is the only one that works for an account with no
     * zones yet.
     *
     * @return array{id: string, name: string}
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

        // A token Cloudflare refused outright is a different problem from a
        // token it accepted and scoped too narrowly, and only the second is
        // about Account Resources. Both routes used to swallow every
        // CloudflareException, so an expired or mistyped token -- the commoner
        // case by far -- was answered with a lecture about resource scoping on
        // a token that was never valid. Cloudflare's own sentence says it
        // better, so it goes first and the scoping advice follows it.
        if ($refused !== null) {
            throw new CloudflareException(
                'Cloudflare rejected this API token: ' . $refused->getMessage(),
                previous: $refused
            );
        }

        // Neither route produced an id. Name both, because the operator cannot
        // otherwise tell a missing permission from a missing zone -- and the
        // sentence Cloudflare's guide would lead them to expect ("check the
        // permissions") is not the answer.
        throw new CloudflareException(
            'Cloudflare API token has no accessible accounts. Check that it selects an '
            . 'account under Account Resources, and that Zone Resources includes at least '
            . 'one zone in that account -- the engine can take the account id from either.'
        );
    }

    /**
     * `GET /accounts`, or null when it is empty or refused.
     *
     * Refused is caught rather than propagated: a 403 here is the exact case
     * the zone route exists for, and turning it into a hard failure would leave
     * a usable token rejected for a permission nothing needs.
     *
     * @return array{id: string, name: string}|null
     */
    private function accountFromList(?CloudflareException &$refused = null): ?array
    {
        try {
            $json = $this->get('/accounts', ['per_page' => 50]);
        } catch (CloudflareException $e) {
            // Kept rather than discarded: {@see resolveAccount()} needs it to
            // tell "Cloudflare said no" from "the token sees nothing here".
            $refused ??= $e;

            return null;
        }

        $accounts = $json['result'] ?? [];
        if (!is_array($accounts) || $accounts === []) {
            return null;
        }

        $first = $accounts[0];
        $id = is_array($first) ? (string) ($first['id'] ?? '') : '';
        if ($id === '') {
            return null;
        }

        return ['id' => $id, 'name' => is_array($first) ? (string) ($first['name'] ?? '') : ''];
    }

    /**
     * The account id a visible zone names, or null.
     *
     * The longest-suffix match is not the question here -- any zone the token
     * can see is in an account the token can work in, and a token scoped to one
     * account can only see that account's zones.
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
        if (!is_array($zones)) {
            return null;
        }

        $accounts = [];
        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }
            $account = $zone['account'] ?? null;
            if (!is_array($account)) {
                continue;
            }
            $id = (string) ($account['id'] ?? '');
            if ($id !== '') {
                $accounts[$id] = (string) ($account['name'] ?? '');
            }
        }

        if ($accounts === []) {
            return null;
        }

        // This route infers the account from a zone, which is sound only while
        // the premise above holds -- one account in view. A token that can see
        // zones in several accounts breaks it, and silently: the id would come
        // from whichever zone Cloudflare happened to list first, while
        // findZoneForHostname() picks by longest suffix across all of them. The
        // tunnel would be created in one account and the CNAME written into a
        // zone in another, and the hostname would simply never route, with
        // nothing anywhere saying why. Refuse instead, and name them.
        if (count($accounts) > 1) {
            throw new CloudflareException(
                'This API token can see zones in more than one Cloudflare account ('
                . implode(', ', array_map(
                    static fn (string $id, string $name): string => $name !== '' ? "{$name} [{$id}]" : $id,
                    array_keys($accounts),
                    $accounts
                ))
                . '), so the engine cannot tell which one the tunnel belongs in. Give the token '
                . 'Account Resources for the account you want, so /accounts answers directly, or '
                . 'scope Zone Resources to zones in that one account.'
            );
        }

        $id = (string) array_key_first($accounts);

        return ['id' => $id, 'name' => $accounts[$id]];
    }

    /**
     * Longest-suffix zone match for a hostname (e.g. a.b.example.com → example.com).
     *
     * @return array{id: string, name: string}
     */
    public function findZoneForHostname(string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $json = $this->get('/zones', ['per_page' => 50]);
        $zones = $json['result'] ?? [];
        if (!is_array($zones) || $zones === []) {
            // Three causes, and the operator can only tell them apart if all
            // three are named: the permission, the account resource, and
            // whether the zone is in this Cloudflare account at all.
            throw new CloudflareException(
                "No Cloudflare zones are visible to this API token. Check that it has "
                . 'Zone:DNS Edit, that Zone Resources includes the zone this hostname is '
                . 'under, and that the zone belongs to the account the token selects.'
            );
        }

        $best = null;
        $bestLen = -1;
        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }
            $name = strtolower(trim((string) ($zone['name'] ?? '')));
            $id = (string) ($zone['id'] ?? '');
            if ($name === '' || $id === '') {
                continue;
            }
            if ($hostname === $name || str_ends_with($hostname, '.' . $name)) {
                $len = strlen($name);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = ['id' => $id, 'name' => $name];
                }
            }
        }

        if ($best === null) {
            throw new CloudflareException(
                "Hostname '{$hostname}' is not under any Cloudflare zone accessible with this API token."
            );
        }

        return $best;
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
        if (!is_array($tunnels)) {
            return null;
        }

        foreach ($tunnels as $tunnel) {
            if (!is_array($tunnel)) {
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
        if (!is_array($result)) {
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
        if (!is_string($token) || trim($token) === '') {
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
        if (!is_array($result)) {
            return [];
        }
        $config = $result['config'] ?? [];
        if (!is_array($config)) {
            return [];
        }
        $ingress = $config['ingress'] ?? [];

        return is_array($ingress) ? array_values($ingress) : [];
    }

    /**
     * @param list<array<string, mixed>> $ingress
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
                && !empty($existing['proxied'])
            ) {
                return [
                    'id' => (string) $existing['id'],
                    'name' => (string) ($existing['name'] ?? $hostname),
                    'content' => $target,
                ];
            }
            $json = $this->put("/zones/{$zoneId}/dns_records/{$existing['id']}", [
                'type' => 'CNAME',
                'name' => $hostname,
                'content' => $target,
                'proxied' => true,
                'ttl' => 1,
            ]);
        } else {
            $json = $this->post("/zones/{$zoneId}/dns_records", [
                'type' => 'CNAME',
                'name' => $hostname,
                'content' => $target,
                'proxied' => true,
                'ttl' => 1,
            ]);
        }

        $result = $json['result'] ?? [];
        if (!is_array($result) || empty($result['id'])) {
            throw new CloudflareException("Failed to upsert DNS CNAME for '{$hostname}'.");
        }

        return [
            'id' => (string) $result['id'],
            'name' => (string) ($result['name'] ?? $hostname),
            'content' => $target,
        ];
    }

    public function deleteDnsRecord(string $zoneId, string $recordId): void
    {
        try {
            $this->delete("/zones/{$zoneId}/dns_records/{$recordId}");
        } catch (CloudflareException $e) {
            // Already gone is fine.
            if (!str_contains(strtolower($e->getMessage()), 'could not find')
                && !str_contains(strtolower($e->getMessage()), 'not found')
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
            if (!str_contains(strtolower($e->getMessage()), 'not found')) {
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
        if (!is_array($records)) {
            return null;
        }
        foreach ($records as $record) {
            if (!is_array($record) || empty($record['id'])) {
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
     * Build ingress list with catch-all, replacing any existing rule for hostname.
     *
     * @param list<array<string, mixed>> $existing
     * @param array<string, mixed>|\stdClass $originRequest
     * @return list<array<string, mixed>>
     */
    public static function upsertHostnameIngress(
        array $existing,
        string $hostname,
        string $service,
        array|\stdClass $originRequest = new \stdClass(),
    ): array {
        $hostname = strtolower(trim($hostname));
        $rules = [];
        foreach ($existing as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            // Drop catch-all; re-append at end.
            if (!isset($rule['hostname']) || $rule['hostname'] === '' || $rule['hostname'] === null) {
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
     * @param list<array<string, mixed>> $existing
     * @return list<array<string, mixed>>
     */
    public static function removeHostnameIngress(array $existing, string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $rules = [];
        foreach ($existing as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (!isset($rule['hostname']) || $rule['hostname'] === '' || $rule['hostname'] === null) {
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
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send($this->http()->get(self::API_BASE . $path, $query));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->send($this->http()->post(self::API_BASE . $path, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function put(string $path, array $body): array
    {
        return $this->send($this->http()->put(self::API_BASE . $path, $body));
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $path): array
    {
        return $this->send($this->http()->delete(self::API_BASE . $path));
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
    private function send(\Illuminate\Http\Client\Response $response): array
    {
        try {
            $response->throw();
        } catch (RequestException $e) {
            $body = $response->json();
            $errors = is_array($body) ? ($body['errors'] ?? null) : null;
            $message = 'Cloudflare API request failed';
            if (is_array($errors) && $errors !== []) {
                $parts = [];
                foreach ($errors as $error) {
                    if (is_array($error) && isset($error['message'])) {
                        $parts[] = (string) $error['message'];
                    }
                }
                if ($parts !== []) {
                    $message .= ': ' . implode('; ', $parts);
                }
            } else {
                $message .= ' (HTTP ' . $response->status() . ')';
            }
            throw new CloudflareException($message, previous: $e);
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new CloudflareException('Cloudflare API returned a non-JSON response.');
        }
        if (($json['success'] ?? true) === false) {
            $errors = $json['errors'] ?? [];
            $parts = [];
            if (is_array($errors)) {
                foreach ($errors as $error) {
                    if (is_array($error) && isset($error['message'])) {
                        $parts[] = (string) $error['message'];
                    }
                }
            }
            throw new CloudflareException(
                'Cloudflare API error' . ($parts !== [] ? ': ' . implode('; ', $parts) : '')
            );
        }

        return $json;
    }
}
