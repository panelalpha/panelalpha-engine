<?php

namespace App\System\Project\Dind;

use App\Models\User;
use App\System\Privileged;
use App\System\Project\Dind;

/**
 * {@see AppHealth}'s second question, can anyone reach the app: the request through the webserver with the
 * project's Host header, compared with what the app answers itself. Red means routing broke, not the app.
 */
final class AppReachability
{
    /** The domain answers with what the application answers. */
    public const OK = 'ok';

    /** The webserver answered its own 404: no vhost is loaded for this name. */
    public const NOT_ROUTED = 'not_routed';

    /** Something answered, but not this application. */
    public const DIFFERS = 'differs';

    /** Nothing accepted the connection at all. */
    public const UNREACHABLE = 'unreachable';

    /** No domain, or no answering port to compare against. */
    public const SKIPPED = 'skipped';

    public function __construct(private readonly Dind $dind) {}

    /**
     * Does this project's domain serve this project's app? Compared by a hash of the first 2KB, since a
     * domain serving another customer's site answered 200 at both ends. Never throws.
     *
     * @param  list<array<string, mixed>>  $ports  as {@see check()} produced them
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public function check(array $ports, int $timeout = 5): array
    {
        try {
            return $this->probe($ports, $timeout);
        } catch (\Throwable $e) {
            return self::skipped(null, 'the check could not run: '.AppHealth::trimReason($e->getMessage()));
        }
    }

    /**
     * Skipped, not unreachable, when no port served a page, which report() said already. The edge is asked
     * at the address the vhost listens on, where a visitor arrives: under NAT the local one.
     *
     * @param  list<array<string, mixed>>  $ports
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    private function probe(array $ports, int $timeout): array
    {
        $main = $this->dind->userModel()->getMainDomain();
        $domain = $main?->domain;
        if ($domain === null || $domain === '') {
            return self::skipped(null, 'the project has no domain yet');
        }

        $served = self::firstAnsweringPort($ports);
        if ($served === null) {
            return self::skipped($domain, 'no port served a page, so there is nothing to compare');
        }

        $ip = User::defaultBindIpAddresses()['ipv4'][0] ?? '127.0.0.1';

        $app = self::parseFingerprint($this->dind->shell()->execQuiet(
            ['bash', '-c', self::appProbeScript((string) $served['scheme'], (int) $served['port'], $timeout, $domain)],
            [],
            2 * $timeout + 10
        ));

        return self::awaitRoute(
            $domain,
            fn (): array => self::parseFingerprint($this->dind->system()->exec(
                Privileged::host()->edgeProbe($domain, $ip, $timeout, $main->sslEnabled()),
            )),
            $app
        );
    }

    /** Seconds between edge re-probes while a just-reloaded vhost settles. */
    public const EDGE_RETRY_DELAYS = [1, 2, 4, 8, 15];

    /**
     * Probe the edge and compare, re-probing while it looks unrouted: the webserver reload the deploy just
     * issued may not have taken effect yet.
     *
     * @param  callable(): array{code: int, hash: string, default404: bool}  $probeEdge
     * @param  array{code: int, hash: string, default404: bool}  $app
     * @param  (callable(int): void)|null  $sleep
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public static function awaitRoute(string $domain, callable $probeEdge, array $app, ?callable $sleep = null): array
    {
        $sleep ??= static fn (int $seconds) => sleep($seconds);
        $verdict = self::compareFingerprints($domain, $probeEdge(), $app);

        foreach (self::EDGE_RETRY_DELAYS as $delay) {
            if (! in_array($verdict['verdict'], [self::NOT_ROUTED, self::UNREACHABLE], true)) {
                break;
            }
            $sleep($delay);
            $verdict = self::compareFingerprints($domain, $probeEdge(), $app);
        }

        return $verdict;
    }

    /**
     * @param  array{code: int, hash: string, default404: bool}  $edge
     * @param  array{code: int, hash: string, default404: bool}  $app
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public static function compareFingerprints(string $domain, array $edge, array $app): array
    {
        $scheme = ($edge['scheme'] ?? '') === 'https' ? 'https' : 'http';

        return self::compare($domain, $edge, $app) + ['scheme' => $scheme];
    }

    /**
     * @param  array{code: int, hash: string, default404: bool}  $edge
     * @param  array{code: int, hash: string, default404: bool}  $app
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    private static function compare(string $domain, array $edge, array $app): array
    {
        if ($edge['code'] === 0) {
            return [
                'verdict' => self::UNREACHABLE,
                'domain' => $domain,
                'http_code' => null,
                'detail' => 'the webserver did not accept the connection',
            ];
        }

        if ($edge['default404']) {
            return [
                'verdict' => self::NOT_ROUTED,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => 'the webserver answered its own 404 page, so no vhost is loaded for this domain',
            ];
        }

        if ($edge['hash'] === $app['hash'] && $edge['hash'] !== '') {
            return [
                'verdict' => self::OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => (string) $edge['code'],
            ];
        }

        return self::localRedirectVerdict($domain, $edge, $app)
            ?? self::httpsUpgradeVerdict($domain, $edge)
            ?? self::statusOnlyVerdict($domain, $edge, $app)
            ?? self::differsVerdict($domain, $edge);
    }

    /**
     * Probed on 127.0.0.1 the app redirects to the address it was installed under, through the domain it
     * serves the page: WordPress everywhere, and healthy.
     *
     * @param  array{code: int, hash: string, default404: bool}  $edge
     * @param  array{code: int, hash: string, default404: bool}  $app
     * @return ?array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    private static function localRedirectVerdict(string $domain, array $edge, array $app): ?array
    {
        if ($edge['code'] >= 200 && $edge['code'] < 300 && $app['code'] >= 300 && $app['code'] < 400) {
            return [
                'verdict' => self::OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => $edge['code'].' (the application redirects to its own address when probed locally)',
            ];
        }

        return null;
    }

    /**
     * The vhost forwards plain http as `X-Forwarded-Proto: http`, and an app that knows its https URL
     * (OpenCloud's 308) upgrades it to this same address, where visitors arrive: healthy.
     *
     * @param  array{code: int, hash: string, default404: bool}  $edge
     * @return ?array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    private static function httpsUpgradeVerdict(string $domain, array $edge): ?array
    {
        if ($edge['code'] >= 300 && $edge['code'] < 400 && self::upgradesToHttps($domain, (string) ($edge['location'] ?? ''))) {
            return [
                'verdict' => self::OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => $edge['code'].' (a redirect to https://'.$domain.'/, where visitors arrive)',
            ];
        }

        return null;
    }

    /**
     * An app that does not reproduce its own bytes is compared by status alone, which still catches somebody
     * else's error page without calling every CSRF-token app unreachable.
     *
     * @param  array{code: int, hash: string, default404: bool}  $edge
     * @param  array{code: int, hash: string, default404: bool}  $app
     * @return ?array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    private static function statusOnlyVerdict(string $domain, array $edge, array $app): ?array
    {
        if (! self::bodyIsReproducible($app) && $edge['code'] === $app['code']) {
            return [
                'verdict' => self::OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => $edge['code'].' (the application varies its own response, so only the status was compared)',
            ];
        }

        return null;
    }

    /**
     * Not called "another project's site": an app that varies by Host, such as a framework redirecting to
     * its APP_URL, lands here too and is not broken.
     *
     * @param  array{code: int, hash: string, default404: bool}  $edge
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    private static function differsVerdict(string $domain, array $edge): array
    {
        return [
            'verdict' => self::DIFFERS,
            'domain' => $domain,
            'http_code' => $edge['code'],
            'detail' => "answered {$edge['code']} with a different response than the application itself",
        ];
    }

    /** Whether $location is https://$domain/ -- the edge's own `/`, only upgraded. */
    private static function upgradesToHttps(string $domain, string $location): bool
    {
        $url = parse_url($location);
        if (! is_array($url) || strtolower($url['scheme'] ?? '') !== 'https') {
            return false;
        }

        return strtolower($url['host'] ?? '') === strtolower($domain)
            && in_array($url['port'] ?? 443, [443], true)
            && in_array($url['path'] ?? '/', ['', '/'], true)
            && ! isset($url['query']);
    }

    /** @return array{verdict: string, domain: ?string, http_code: ?int, detail: string} */
    private static function skipped(?string $domain, string $detail): array
    {
        return ['verdict' => self::SKIPPED, 'domain' => $domain, 'http_code' => null, 'detail' => $detail];
    }

    /**
     * @param  list<array<string, mixed>>  $ports
     * @return array<string, mixed>|null
     */
    private static function firstAnsweringPort(array $ports): ?array
    {
        foreach ($ports as $port) {
            if (($port['status'] ?? null) === AppHealth::STATUS_OK) {
                return $port;
            }
        }

        return null;
    }

    /**
     * The same fetch against the app itself, twice, since CSRF tokens make bodies differ; with the visitor
     * headers the vhost forwards and the edge's Host, or an app needing them answers 403 or 500.
     */
    public static function appProbeScript(string $scheme, int $port, int $timeout = 5, ?string $domain = null): string
    {
        $timeout = max(1, $timeout);
        $scheme = $scheme === 'https' ? 'https' : 'http';
        $host = AppHealth::visitorHeaders($domain !== '' ? $domain : null);

        return <<<SH
set -u
f=\$(mktemp)
g=\$(mktemp)
code=\$(curl -sS -k -m {$timeout} {$host}-o "\$f" -w '%{http_code}' '{$scheme}://127.0.0.1:{$port}/' 2>/dev/null) || code=000
# A second apart: a page stamping the current second (Immich Kiosk) must not
# read as reproducible because both fetches landed in the same second.
sleep 1
curl -sS -k -m {$timeout} {$host}-o "\$g" '{$scheme}://127.0.0.1:{$port}/' >/dev/null 2>&1 || :
printf '%s\t%s\t%s\t%s' "\${code:-000}" "\$(head -c 2048 "\$f" | sha256sum | cut -d' ' -f1)" "no" "\$(head -c 2048 "\$g" | sha256sum | cut -d' ' -f1)"
rm -f "\$f" "\$g"
exit 0
SH;
    }

    /**
     * `hash2`, a second identical fetch, comes from the app probe only; `location` and `scheme` from the edge probe only.
     *
     * @return array{code: int, hash: string, default404: bool, hash2: string, location: string, scheme: string}
     */
    public static function parseFingerprint(string $raw): array
    {
        $parts = explode("\t", trim($raw));

        return [
            'code' => (int) ($parts[0] ?? '0'),
            'hash' => trim($parts[1] ?? ''),
            'default404' => trim($parts[2] ?? 'no') === 'yes',
            'hash2' => trim($parts[3] ?? ''),
            'location' => trim($parts[4] ?? ''),
            'scheme' => trim($parts[5] ?? ''),
        ];
    }

    /**
     * Whether the app reproduces its own bytes between two identical requests; unknown reads as
     * reproducible, so a probe that did not ask cannot weaken the comparison.
     *
     * @param  array<string, mixed>  $app
     */
    private static function bodyIsReproducible(array $app): bool
    {
        $second = (string) ($app['hash2'] ?? '');

        return $second === '' || $second === (string) ($app['hash'] ?? '');
    }

    /**
     * One human-readable line for the reachability verdict.
     *
     * @param  array{verdict: string, domain: ?string, http_code: ?int, detail: string}  $result
     */
    public static function describe(array $result): string
    {
        $url = ($result['scheme'] ?? 'http').'://'.($result['domain'] ?? '?').'/';

        return match ($result['verdict']) {
            self::OK => "Reachable: {$url} answered {$result['detail']} through the webserver",
            self::SKIPPED => "Reachability check skipped: {$result['detail']}",
            default => "Not reachable: {$url} {$result['detail']}",
        };
    }
}
