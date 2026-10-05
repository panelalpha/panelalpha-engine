<?php

namespace App\System\Project\Dind;

use App\Models\User;
use App\System\Project\Dind;

/**
 * The second of {@see AppHealth}'s two questions: can anyone reach the
 * application? The same request through the webserver, from the host, with
 * the project's own Host header, compared with what the application answers
 * itself. A red answer means the routing is broken while the application is
 * fine.
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

    public function __construct(private readonly Dind $dind)
    {
    }

    /**
     * Ask the question a visitor asks: does this project's domain serve this
     * project's application?
     *
     * Compared by content, not by status code. The outage this exists for
     * answered 200 through the proxy and 200 in the container while serving a
     * different customer's site -- statuses matched, and only the bytes told
     * them apart. A hash of the first 2KB is enough to separate two documents
     * without carrying either of them back.
     *
     * Never throws: a check that cannot run reports that it could not.
     *
     * @param list<array<string, mixed>> $ports as {@see check()} produced them
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public function check(array $ports, int $timeout = 5): array
    {
        try {
            return $this->probe($ports, $timeout);
        } catch (\Throwable $e) {
            return self::skipped(null, 'the check could not run: ' . AppHealth::trimReason($e->getMessage()));
        }
    }

    /**
     * @param list<array<string, mixed>> $ports
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
            // No port served a page (silent, or a 5xx), which report() has
            // already said. Saying "unreachable" here would blame the proxy for the app.
            return self::skipped($domain, 'no port served a page, so there is nothing to compare');
        }

        // The address the vhost listens on, which is the address a visitor
        // arrives at -- and under NAT the local one, not the public one.
        $ip = User::defaultBindIpAddresses()['ipv4'][0] ?? '127.0.0.1';

        $app = self::parseFingerprint($this->dind->shell()->execQuiet(
            ['bash', '-c', self::appProbeScript((string) $served['scheme'], (int) $served['port'], $timeout, $domain)],
            [],
            2 * $timeout + 10
        ));

        return self::awaitRoute(
            $domain,
            fn (): array => self::parseFingerprint($this->dind->system()->execOnHost(
                ['bash', '-c', self::edgeProbeScript($domain, $ip, $timeout, $main->sslEnabled())],
            )),
            $app
        );
    }

    /** Seconds between edge re-probes while a just-reloaded vhost settles. */
    public const EDGE_RETRY_DELAYS = [1, 2, 4, 8, 15];

    /**
     * Probe the edge and compare, re-probing while it looks unrouted: the
     * webserver reload the deploy just issued may not have taken effect yet.
     *
     * @param callable(): array{code: int, hash: string, default404: bool} $probeEdge
     * @param array{code: int, hash: string, default404: bool} $app
     * @param (callable(int): void)|null $sleep
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public static function awaitRoute(string $domain, callable $probeEdge, array $app, ?callable $sleep = null): array
    {
        $sleep ??= static fn (int $seconds) => sleep($seconds);
        $verdict = self::compareFingerprints($domain, $probeEdge(), $app);

        foreach (self::EDGE_RETRY_DELAYS as $delay) {
            if (!in_array($verdict['verdict'], [self::NOT_ROUTED, self::UNREACHABLE], true)) {
                break;
            }
            $sleep($delay);
            $verdict = self::compareFingerprints($domain, $probeEdge(), $app);
        }

        return $verdict;
    }

    /**
     * @param array{code: int, hash: string, default404: bool} $edge
     * @param array{code: int, hash: string, default404: bool} $app
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public static function compareFingerprints(string $domain, array $edge, array $app): array
    {
        $scheme = ($edge['scheme'] ?? '') === 'https' ? 'https' : 'http';

        return self::compare($domain, $edge, $app) + ['scheme' => $scheme];
    }

    /**
     * @param array{code: int, hash: string, default404: bool} $edge
     * @param array{code: int, hash: string, default404: bool} $app
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

        // The bodies differ because the application canonicalises: probed on
        // 127.0.0.1 it redirects to the address it was installed under, and
        // through the domain it serves the page. That is WordPress on every
        // site, and it is the healthy shape -- the request that matters
        // succeeded. Comparing content is still what catches a domain serving
        // somebody else's application, which answers 200 at both ends.
        if ($edge['code'] >= 200 && $edge['code'] < 300 && $app['code'] >= 300 && $app['code'] < 400) {
            return [
                'verdict' => self::OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => $edge['code'] . ' (the application redirects to its own address when probed locally)',
            ];
        }

        // The mirror image: the vhost forwards a plain-http request as
        // `X-Forwarded-Proto: http`, and an app that knows its https URL
        // (OpenCloud's 308) upgrades it. Visitors arrive over https and are
        // served, so an upgrade to this same address is the healthy answer.
        if ($edge['code'] >= 300 && $edge['code'] < 400 && self::upgradesToHttps($domain, (string) ($edge['location'] ?? ''))) {
            return [
                'verdict' => self::OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => $edge['code'] . ' (a redirect to https://' . $domain . '/, where visitors arrive)',
            ];
        }

        // The application does not reproduce its own bytes, so a difference
        // between it and the edge says nothing. Comparing what is left --
        // the status code -- still catches the domain that answers with
        // somebody else's error page, and stops reporting every CSRF-token
        // application on the host as unreachable.
        if (!self::bodyIsReproducible($app) && $edge['code'] === $app['code']) {
            return [
                'verdict' => self::OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => $edge['code'] . ' (the application varies its own response, so only the status was compared)',
            ];
        }

        // Deliberately not called "another project's site": an application
        // that varies by Host -- a framework redirecting to its configured
        // APP_URL -- lands here too, and is not broken.
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
        if (!is_array($url) || strtolower($url['scheme'] ?? '') !== 'https') {
            return false;
        }

        return strtolower($url['host'] ?? '') === strtolower($domain)
            && in_array($url['port'] ?? 443, [443], true)
            && in_array($url['path'] ?? '/', ['', '/'], true)
            && !isset($url['query']);
    }

    /** @return array{verdict: string, domain: ?string, http_code: ?int, detail: string} */
    private static function skipped(?string $domain, string $detail): array
    {
        return ['verdict' => self::SKIPPED, 'domain' => $domain, 'http_code' => null, 'detail' => $detail];
    }

    /**
     * @param list<array<string, mixed>> $ports
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
     * Fetch through the webserver as a visitor would, from the host.
     *
     * No DNS: the Host header carries the name and the address is the one the
     * vhost binds, so the check works for a domain whose DNS has not been
     * pointed here yet -- which is most of them, most of the time.
     *
     * Over https when the domain has TLS, as visitors arrive: over http the
     * vhost forwards `X-Forwarded-Proto: http`, and an app that insists on a
     * secure request answers that with a 500 nobody visiting ever gets. Plain
     * http only when nothing answered on https.
     */
    public static function edgeProbeScript(string $domain, string $ip, int $timeout = 5, bool $https = false): string
    {
        $timeout = max(1, $timeout);
        $domain = escapeshellarg($domain);
        $ip = escapeshellarg($ip);
        $secure = $https ? 1 : 0;

        return <<<SH
set -u
host={$domain}
addr={$ip}
f=\$(mktemp)
scheme=http
out=000
if [ {$secure} = 1 ]; then
  out=\$(curl -sS -k -m {$timeout} -o "\$f" -w '%{http_code} %{redirect_url}' --resolve "\$host:443:\$addr" "https://\$host/" 2>/dev/null) || out=000
  [ "\${out%% *}" = 000 ] || scheme=https
fi
if [ "\$scheme" = http ]; then
  out=\$(curl -sS -m {$timeout} -o "\$f" -w '%{http_code} %{redirect_url}' -H "Host: \$host" "http://\$addr/" 2>/dev/null) || out=000
fi
code=\${out%% *}
location=\$(printf '%s' "\${out#* }" | tr -d '\t\r\n')
[ "\$location" = "\$out" ] && location=
marker=no
if [ "\$code" = "404" ] && grep -q 'Page Not Found' "\$f" && grep -q 'error-page' "\$f"; then marker=yes; fi
printf '%s\t%s\t%s\t\t%s\t%s' "\${code:-000}" "\$(head -c 2048 "\$f" | sha256sum | cut -d' ' -f1)" "\$marker" "\$location" "\$scheme"
rm -f "\$f"
exit 0
SH;
    }

    /**
     * The same fetch against the application itself, for the comparison --
     * twice, so the caller can tell whether this application's body is stable
     * enough to compare at all.
     *
     * Nextcloud, ownCloud and every framework with CSRF protection stamp a
     * fresh token into each response, so two identical requests a millisecond
     * apart already differ. Hashing one of them and comparing it to the edge
     * declared healthy sites unreachable. Fetching twice answers that without
     * having to guess at token formats: if the application cannot reproduce
     * its own bytes, neither can the edge, and the body is not evidence.
     */
    public static function appProbeScript(string $scheme, int $port, int $timeout = 5, ?string $domain = null): string
    {
        $timeout = max(1, $timeout);
        $scheme = $scheme === 'https' ? 'https' : 'http';
        // The headers the vhost forwards for a visitor on https, as the health
        // probe sends them: an app that needs them answers 403 or 500 without.
        // Same Host as the edge fetch, so an app that echoes it into the page still matches.
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
     * @return array{code: int, hash: string, default404: bool, hash2: string, location: string, scheme: string}
     */
    public static function parseFingerprint(string $raw): array
    {
        $parts = explode("\t", trim($raw));

        return [
            'code' => (int) ($parts[0] ?? '0'),
            'hash' => trim($parts[1] ?? ''),
            'default404' => trim($parts[2] ?? 'no') === 'yes',
            // Only the application probe sends this: the hash of a second,
            // identical fetch. Empty from the edge probe, which fetches once.
            'hash2' => trim($parts[3] ?? ''),
            // Only the edge probe sends this: where a redirect pointed.
            'location' => trim($parts[4] ?? ''),
            // Only the edge probe sends this: which scheme it answered on.
            'scheme' => trim($parts[5] ?? ''),
        ];
    }

    /**
     * Whether this application reproduces its own bytes between two identical
     * requests. When it does not -- a CSRF token, a nonce, a timestamp -- the
     * body cannot be used as evidence about the edge.
     *
     * Absent means not measured, which reads as reproducible: a probe that
     * did not ask the question must not weaken the comparison.
     *
     * @param array<string, mixed> $app
     */
    private static function bodyIsReproducible(array $app): bool
    {
        $second = (string) ($app['hash2'] ?? '');

        return $second === '' || $second === (string) ($app['hash'] ?? '');
    }

    /**
     * One human-readable line for the reachability verdict.
     *
     * @param array{verdict: string, domain: ?string, http_code: ?int, detail: string} $result
     */
    public static function describe(array $result): string
    {
        $url = ($result['scheme'] ?? 'http') . '://' . ($result['domain'] ?? '?') . '/';

        return match ($result['verdict']) {
            self::OK => "Reachable: {$url} answered {$result['detail']} through the webserver",
            self::SKIPPED => "Reachability check skipped: {$result['detail']}",
            default => "Not reachable: {$url} {$result['detail']}",
        };
    }
}
