<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\PortMapping;
use App\Lib\Deploy\Sidecar\ServiceRole;

/**
 * Fills in the blanks a project's own docker-compose.yml leaves for a human, with values derived from the
 * account secret so a redeploy keeps them; the same placeholder text gets the same value everywhere.
 */
class ComposePlaceholders
{
    /** Env names whose value is a credential; a placeholder anywhere else (APP_NAME: CHANGE_ME) is cosmetic. */
    private const SECRET_KEY_PATTERN =
        '/(^|_)(SECRET|SECRETS|PASSWORD|PASSWD|PASS|TOKEN|KEY|KEYS|APIKEY|SALT|PEPPER|CREDENTIAL|CREDENTIALS|PASSPHRASE|SIGNING|ENCRYPTION)(_|$)/i';

    /**
     * Values meaning "put your own here" (CHANGE_ME, …_HERE, <your-secret>, {{ secret }}, xxxxxx), anchored
     * to the whole value so a real secret that merely starts with one of these words does not match.
     */
    private const PLACEHOLDER_PATTERNS = [
        '/^(replace|replaceme|change|changeme|change_?this|your|my|our|some|insert|put|enter|fill|set|generate|generated|random|example|sample|dummy|placeholder|todo|tbd|unset|none|secret|password|passwd|pass|admin|strong|long|super_?secret|top_?secret|s3cr3t)([_\- ].*)?$/i',
        '/[_\- ](here|goes[_\- ]here|me|this|placeholder|example)$/i',
        '/^<.+>$/',
        '/^\{\{.*\}\}$/',
        '/^(x{4,}|\*{3,}|\.{3,}|-{3,})$/i',
    ];

    /**
     * Names that sign or encrypt for the app itself, so a value we invent is as good as any.
     * Third-party credentials are excluded: inventing one only breaks the integration.
     */
    private const PUBLISHED_SECRET_KEY_PATTERN =
        '/SECRET|SALT|PEPPER|SIGNING|ENCRYPTION|^(?=.*(APP_|JWT|SESSION|COOKIE)).*_KEY$/i';

    private const THIRD_PARTY_KEY_PATTERN = '/API_?KEY|PUBLIC_KEY|ACCESS_KEY|CLIENT_SECRET/i';

    /** Well-known repo placeholders (Planka's notsecretkey, Saleor's changeme). */
    private const PUBLISHED_SECRET_VALUES = [
        'changeme', 'change_me', 'change-me', 'changethis', 'notsecretkey', 'secret', 'mysecret',
        'your-secret-key', 'your_secret_key', 'yoursecretkey', 'please-change-me', 'replace-me', 'todo',
    ];

    /** Env names that name a public address, which left at localhost points every link at an unreachable host. */
    private const PUBLIC_URL_KEY_PATTERN = '/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i';

    /**
     * A required variable that is the app's own address (`${EGMA_BASE_URL:?}`), filled with the account's URL.
     * Narrower than PUBLIC_URL_KEY_PATTERN: a required DATABASE_URL is not ours to invent.
     */
    private const REQUIRED_OWN_URL_PATTERN = '/(^|_)(BASE_URL|PUBLIC_URL|APP_URL|SITE_URL|EXTERNAL_URL|ORIGIN)$/i';

    private const LOCAL_URL_PATTERN = '#^https?://(localhost|127\.0\.0\.1|0\.0\.0\.0|host\.docker\.internal)(:\d+)?(/[^\s?\#]*)?$#i';

    /**
     * A key naming a backing service, whose `http://localhost:<port>` is the sidecar's own address and survives.
     * Anchored to the key's start or a `_`, so `MAIL_URL` matches but `WEBMAIL_URL` does not.
     */
    private const SIDECAR_KEY_PATTERN = '/(^|_)(REDIS|VALKEY|DATABASE|POSTGRES|PGSQL|MYSQL|MARIADB|MONGO|CACHE|QUEUE|BROKER|UPSTASH|TYPESENSE|MEILI|SRH|ELASTIC|OPENSEARCH|SOLR|CLICKHOUSE|MINIO|S3|BUCKET|QDRANT|CHROMA|WEAVIATE|OLLAMA|MAIL|SMTP|AMQP|RABBIT|KAFKA|NATS|INFLUX)/i';

    /**
     * `${JWT_SECRET:?JWT_SECRET must be set}`, Compose's fail-closed form, as a whole value for
     * {@see requiredSecret()}; {@see fill()} finds every reference itself with REFERENCE_PATTERN.
     */
    private const REQUIRED_VAR_PATTERN = '/^\$\{([A-Za-z_][A-Za-z0-9_]*):\?([^}]*)\}$/';

    /** A length the `:?` message asks for: `openssl rand -hex 32` is 64, "at least 64 characters" is 64. */
    private const LENGTH_HINT_PATTERN = '/rand\s+-hex\s+(\d+)|(\d+)\s*(?:characters|chars)\b/i';

    private const MAX_HINTED_LENGTH = 256;

    /** A message asking for base64 wants decoded bytes, not characters ("base64-encoded 32-byte key"). */
    private const BASE64_HINT_PATTERN = '/base[\s-]?64/i';

    private const BYTES_HINT_PATTERN = '/rand\s+-base64\s+(\d+)|(\d+)[\s-]*bytes?\b/i';

    private const DEFAULT_BASE64_BYTES = 32;

    private const MAX_BASE64_BYTES = 128;

    /**
     * Any `${VAR}` / `${VAR<op>word}` reference, or Compose's `$$` escape so a literal `$${VAR}` is skipped.
     * A nested `${A:-${B}}` is not matched.
     */
    private const REFERENCE_PATTERN = '/\$\$|\$\{([A-Za-z_][A-Za-z0-9_]*)(:?[-?+][^}$]*)?\}/';

    /**
     * The account's address, given to every service that is not a datastore and substituted where a
     * compose file writes `${PA_PUBLIC_URL}`.
     */
    public const PUBLIC_URL_VARIABLE = 'PA_PUBLIC_URL';

    public const PUBLIC_HOST_VARIABLE = 'PA_PUBLIC_HOST';

    /**
     * Keys holding the whole public URL, filled when set but empty, which is worse than unset. Short on
     * purpose; BASE_URL is left out, as blank there means "serve from /" ({@see PublicUrlEnvironment::blankFillKeys()}).
     */
    private const BLANK_URL_KEYS = ['URL', 'PUBLIC_URL', 'APP_URL', 'ASSET_URL', 'SITE_URL', 'ORIGIN', 'ROOT_URL'];

    /**
     * @param  array<string, mixed>  $compose
     * @param  string  $seed  per-account secret the generated values derive from
     * @param  array<string, string>  $accountEnv  the account's env_vars, which win over a generated value
     * @return array{
     *   compose: array<string, mixed>,
     *   secrets: list<string>,
     *   urls: list<string>,
     *   published: list<string>,
     * } the rewritten compose plus the env names touched, for the deploy log
     */
    public static function fill(array $compose, string $seed, ?string $publicUrl = null, array $accountEnv = []): array
    {
        $services = $compose['services'] ?? null;
        if (! is_array($services) || $services === []) {
            return ['compose' => $compose, 'secrets' => [], 'urls' => [], 'published' => []];
        }

        $publicUrl = self::normalisedPublicUrl($publicUrl);
        [$required, $generated, $requiredUrls] = self::requiredVariables($compose, $seed, $accountEnv, $publicUrl);
        if ($required !== []) {
            $compose = self::withRequiredVariables($compose, $required);
            $services = $compose['services'];
        }

        $touched = [
            'secrets' => array_fill_keys($generated, true),
            'urls' => array_fill_keys($requiredUrls, true),
            'published' => [],
        ];
        [$services, $touched] = self::withFilledEnvironments($services, $touched, $seed, $publicUrl, $accountEnv);
        if ($publicUrl !== null) {
            $services = self::withPublicUrlVariables($services, $publicUrl);
        }

        $compose['services'] = $services;

        return [
            'compose' => $compose,
            'secrets' => array_keys($touched['secrets']),
            'urls' => array_keys($touched['urls']),
            'published' => array_keys($touched['published']),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $services
     * @param  array{secrets: array<string, true>, urls: array<string, true>, published: array<string, true>}  $touched
     * @param  array<string, string>  $accountEnv
     * @return array{0: array<array-key, mixed>, 1: array{secrets: array<string, true>, urls: array<string, true>, published: array<string, true>}}
     */
    private static function withFilledEnvironments(array $services, array $touched, string $seed, ?string $publicUrl, array $accountEnv): array
    {
        $replacements = self::replacementsFor($services, $seed);
        $publishedPorts = self::publishedPorts($services);

        foreach ($services as $name => $service) {
            if (! is_array($service) || ! isset($service['environment'])) {
                continue;
            }
            $services[$name]['environment'] = self::rewriteEnvironment(
                $service['environment'],
                static function (string $key, string $value) use ($replacements, $seed, $publicUrl, $accountEnv, $publishedPorts, &$touched): string {
                    [$filled, $kind] = self::filledValue($key, $value, $replacements, $seed, $publicUrl, $accountEnv, $publishedPorts);
                    if ($kind !== null) {
                        $touched[$kind][$key] = true;
                    }

                    return $filled;
                }
            );
        }

        return [$services, $touched];
    }

    /**
     * @param  array<array-key, mixed>  $services
     * @return array<string, string> placeholder text => generated secret
     */
    private static function replacementsFor(array $services, string $seed): array
    {
        $tokens = self::collectPlaceholders($services);
        $replacements = [];
        foreach (array_keys($tokens) as $token) {
            $replacements[(string) $token] = self::generatedSecret((string) $token, $seed);
        }

        return $replacements;
    }

    /**
     * APP_KEY skips the hex filler (Laravel needs base64:<32 bytes>); the account's own value is written
     * inline, because inline beats env_file.
     *
     * @param  array<string, string>  $replacements
     * @param  array<string, string>  $accountEnv
     * @param  array<int, true>  $publishedPorts
     * @return array{0: string, 1: 'secrets'|'published'|'urls'|null}
     */
    private static function filledValue(string $key, string $value, array $replacements, string $seed, ?string $publicUrl, array $accountEnv, array $publishedPorts): array
    {
        $published = self::isPublishedSecret($key, $value);
        $filled = $published && strcasecmp($key, 'APP_KEY') === 0
            ? $value
            : self::applyReplacements($key, $value, $replacements);
        $own = (string) ($accountEnv[$key] ?? '');
        if ($filled !== $value) {
            if ($own !== '') {
                return [self::composeLiteral($own), null];
            }

            return [$filled, 'secrets'];
        }
        if ($published) {
            if ($own !== '') {
                return [self::composeLiteral($own), null];
            }

            return [self::publishedSecret($key, $seed, $value), 'published'];
        }

        return self::withPublicUrlFilled($key, $value, $publicUrl, $accountEnv, $publishedPorts);
    }

    /**
     * @param  array<string, string>  $accountEnv
     * @param  array<int, true>  $publishedPorts
     * @return array{0: string, 1: 'urls'|null}
     */
    private static function withPublicUrlFilled(string $key, string $value, ?string $publicUrl, array $accountEnv, array $publishedPorts): array
    {
        if ($publicUrl !== null && self::isLocalPublicUrl($key, $value, $publishedPorts)) {
            return [self::publicUrlWithLocalPath($value, $publicUrl), 'urls'];
        }
        if ($publicUrl !== null) {
            $withUrl = self::withPublicUrl($key, $value, $publicUrl, $accountEnv);
            if ($withUrl !== $value) {
                return [$withUrl, 'urls'];
            }
        }

        return [$value, null];
    }

    /**
     * A credential for a required variable, derived from the variable name so every service reading it
     * agrees. Like fill(), it reads no message past a `$`; a hostname or port is never invented.
     */
    public static function requiredSecret(string $key, string $value, string $seed): ?string
    {
        if (preg_match(self::REQUIRED_VAR_PATTERN, trim($value), $m) !== 1) {
            return null;
        }
        if (! self::isSecretKey($m[1]) && ! self::isSecretKey($key)) {
            return null;
        }

        $message = str_contains($m[2], '$') ? '' : $m[2];

        return self::requiredSecretValue($m[1], $seed, self::hintedLength($message), self::hintedBase64Bytes($message));
    }

    /**
     * The generated value of a required variable: {@see generatedSecret()}, lengthened (never shortened)
     * when its message asks for more than that.
     */
    public static function requiredSecretValue(string $name, string $seed, int $length = 0, int $base64Bytes = 0): string
    {
        if ($base64Bytes > 0) {
            return base64_encode(self::generatedBytes($name, $seed, $base64Bytes));
        }

        return $length > 48
            ? self::generatedSecretOfLength($name, $seed, $length)
            : self::generatedSecret($name, $seed);
    }

    /** The length a required variable's `:?` message asks for, or 0. */
    public static function hintedLength(string $message): int
    {
        $length = 0;
        preg_match_all(self::LENGTH_HINT_PATTERN, $message, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $asked = ($m[1] ?? '') !== '' ? 2 * (int) $m[1] : (int) ($m[2] ?? 0);
            $length = max($length, min($asked, self::MAX_HINTED_LENGTH));
        }

        return $length;
    }

    /** Decoded bytes a `:?` message asks base64 for, or 0 when it does not say base64. */
    public static function hintedBase64Bytes(string $message): int
    {
        if (preg_match(self::BASE64_HINT_PATTERN, $message) !== 1) {
            return 0;
        }
        $bytes = 0;
        preg_match_all(self::BYTES_HINT_PATTERN, $message, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $bytes = max($bytes, min((int) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? 0)), self::MAX_BASE64_BYTES));
        }

        return $bytes > 0 ? $bytes : self::DEFAULT_BASE64_BYTES;
    }

    /** A required variable naming the app's own public address. */
    public static function isRequiredOwnUrlKey(string $key): bool
    {
        return ! self::isSecretKey($key) && preg_match(self::REQUIRED_OWN_URL_PATTERN, $key) === 1;
    }

    public static function isSecretKey(string $key): bool
    {
        return preg_match(self::SECRET_KEY_PATTERN, $key) === 1;
    }

    public static function isPlaceholder(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64) {
            return false;
        }
        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A signing/encryption key still set to a placeholder everyone can read; one character repeated is
     * a fixed-length template value (Homarr's 64 zeroes), never a real secret.
     */
    public static function isPublishedSecret(string $key, string $value): bool
    {
        if (preg_match(self::PUBLISHED_SECRET_KEY_PATTERN, $key) !== 1
            || preg_match(self::THIRD_PARTY_KEY_PATTERN, $key) === 1
        ) {
            return false;
        }
        $value = strtolower(trim(trim($value), '"\''));

        return in_array($value, self::PUBLISHED_SECRET_VALUES, true)
            || preg_match('/^x{3,}$/', $value) === 1
            || preg_match('/^(django-)?insecure/', $value) === 1
            || preg_match('/^(.)\1{7,}$/', $value) === 1;
    }

    /**
     * A `.env.example` blank to fill in rather than a value: bracketed in any casing, or an all-caps
     * snake-case phrase (one underscore at least, so not `DEBUG`) carrying a "fill me in" word.
     */
    public static function isTemplatePlaceholder(string $value): bool
    {
        $value = trim(trim($value), '"\'');
        if ($value === '') {
            return false;
        }

        if (preg_match('/^(<.+>|\{\{.+\}\}|\[.+\])$/', $value) === 1) {
            return true;
        }

        if (preg_match('/^[A-Z0-9]+(_[A-Z0-9]+)+$/', $value) !== 1) {
            return false;
        }

        return preg_match(self::PLACEHOLDER_WORD_PATTERN, $value) === 1;
    }

    /** The words that turn an all-caps phrase into an instruction; `PATH_TO`, because `DEFAULT_PATH` is a value. */
    private const PLACEHOLDER_WORD_PATTERN =
        '/(^|_)(YOUR|YOURS|CHANGE|CHANGEME|REPLACE|TODO|FIXME|PLACEHOLDER|INSERT|ENTER|SOME)(_|$)'
        .'|PATH_TO|_HERE$|^XXX/';

    /**
     * Seeded by key name, so it survives redeploys; APP_KEY gets Laravel's format, and a repeated-character
     * placeholder longer than 48 keeps its length, as it is a fixed-length key.
     */
    public static function publishedSecret(string $key, string $seed, string $placeholder = ''): string
    {
        if (strcasecmp($key, 'APP_KEY') === 0) {
            return 'base64:'.base64_encode(hash_hmac('sha256', 'compose-placeholder:APP_KEY', $seed, true));
        }

        $placeholder = trim(trim($placeholder), '"\'');
        if (strlen($placeholder) > 48 && preg_match('/^(.)\1+$/', $placeholder) === 1) {
            return self::generatedSecretOfLength($key, $seed, strlen($placeholder));
        }

        return self::generatedSecret($key, $seed);
    }

    /**
     * Distinctive enough to replace inside other values (STRONG_DB_PASSWORD in a DSN), unlike "secret"
     * or "admin", which occur inside ordinary words.
     */
    public static function isDistinctiveToken(string $token): bool
    {
        if (strlen($token) < 12 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            return false;
        }

        return str_contains($token, '_')
            || str_contains($token, '-')
            || $token === strtoupper($token);
    }

    /**
     * Hex, so it survives a URL, a YAML scalar or a shell command unquoted; 48 characters, so it clears
     * the "at least 32" minimum these projects check for.
     */
    public static function generatedSecret(string $token, string $seed): string
    {
        return substr(hash_hmac('sha256', 'compose-placeholder:'.$token, $seed), 0, 48);
    }

    /** Hex of exactly $length characters, starting with {@see generatedSecret()}. */
    private static function generatedSecretOfLength(string $token, string $seed, int $length): string
    {
        $hex = hash_hmac('sha256', 'compose-placeholder:'.$token, $seed);
        for ($block = 1; strlen($hex) < $length; $block++) {
            $hex .= hash_hmac('sha256', 'compose-placeholder:'.$token.':'.$block, $seed);
        }

        return substr($hex, 0, $length);
    }

    /** $length raw bytes derived from the seed, stable across redeploys. */
    private static function generatedBytes(string $token, string $seed, int $length): string
    {
        $bytes = '';
        for ($block = 0; strlen($bytes) < $length; $block++) {
            $bytes .= hash_hmac('sha256', 'compose-placeholder-bytes:'.$token.':'.$block, $seed, true);
        }

        return substr($bytes, 0, $length);
    }

    /**
     * Every required (`:?` / `?`) variable naming a credential, with the one value it gets everywhere:
     * the account's own, else one from the seed. Settled across the whole file, as Compose interpolates it all.
     *
     * @param  array<string, mixed>  $compose
     * @param  array<string, string>  $accountEnv
     * @return array{0: array<string, string>, 1: list<string>, 2: list<string>} name => value, the names generated, the names given the public URL
     */
    private static function requiredVariables(array $compose, string $seed, array $accountEnv, ?string $publicUrl = null): array
    {
        [$names, $urls] = self::requiredReferences($compose, $publicUrl);

        [$values, $generated] = self::requiredSecretValues($names, $seed, $accountEnv);
        $filledUrls = [];
        foreach (array_keys($urls) as $name) {
            $name = (string) $name;
            $own = (string) ($accountEnv[$name] ?? '');
            $values[$name] = $own !== '' ? self::composeLiteral($own) : (string) $publicUrl;
            if ($own === '') {
                $filledUrls[] = $name;
            }
        }

        return [$values, $generated, $filledUrls];
    }

    /**
     * A credential key vouches for its variable only as the key's whole value; each name keeps the
     * longest length any reference asks for, so every reference agrees.
     *
     * @param  array<string, mixed>  $compose
     * @return array{0: array<string, array{0: int, 1: int}>, 1: array<string, true>}
     */
    private static function requiredReferences(array $compose, ?string $publicUrl): array
    {
        $names = [];
        $urls = [];
        self::walkStrings($compose, static function (string $key, string $value) use (&$names, &$urls, $publicUrl): string {
            preg_match_all(self::REFERENCE_PATTERN, $value, $refs, PREG_SET_ORDER);
            foreach ($refs as $ref) {
                $name = $ref[1] ?? '';
                if ($name === '' || preg_match('/^:?\?/', $ref[2] ?? '') !== 1) {
                    continue;
                }
                if ($publicUrl !== null && self::isRequiredOwnUrlKey($name)) {
                    $urls[$name] = true;

                    continue;
                }
                if (self::isSecretKey($name) || (self::isSecretKey($key) && trim($value) === $ref[0])) {
                    $names[$name] = [
                        max($names[$name][0] ?? 0, self::hintedLength($ref[2])),
                        max($names[$name][1] ?? 0, self::hintedBase64Bytes($ref[2])),
                    ];
                }
            }

            return $value;
        });

        return [$names, $urls];
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $names
     * @param  array<string, string>  $accountEnv
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function requiredSecretValues(array $names, string $seed, array $accountEnv): array
    {
        $values = [];
        $generated = [];
        foreach ($names as $name => [$length, $base64Bytes]) {
            $name = (string) $name;
            $own = (string) ($accountEnv[$name] ?? '');
            if ($own !== '') {
                $values[$name] = self::composeLiteral($own);

                continue;
            }
            $values[$name] = self::requiredSecretValue($name, $seed, $length, $base64Bytes);
            $generated[] = $name;
        }

        return [$values, $generated];
    }

    /**
     * Resolve every reference to the given variables as Compose would with them set: `${VAR:+alt}`
     * answers alt, every other form answers the value.
     *
     * @param  array<string, mixed>  $compose
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private static function withRequiredVariables(array $compose, array $values): array
    {
        return self::walkStrings($compose, static fn (string $key, string $value): string => (string) preg_replace_callback(
            self::REFERENCE_PATTERN,
            static function (array $m) use ($values): string {
                $name = $m[1] ?? '';
                if ($name === '' || ! isset($values[$name])) {
                    return $m[0];
                }
                $modifier = $m[2] ?? '';

                return preg_match('/^:?\+/', $modifier) === 1
                    ? substr($modifier, strpos($modifier, '+') + 1)
                    : $values[$name];
            },
            $value
        ));
    }

    /**
     * Every string scalar in a compose document, with the key it sits under (a list entry `KEY=value`
     * counts as KEY); map keys are never touched.
     *
     * @param  callable(string, string): string  $rewrite
     */
    private static function walkStrings(mixed $node, callable $rewrite, string $key = ''): mixed
    {
        if (is_string($node)) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $node, $m) === 1) {
                return $m[1].'='.$rewrite($m[1], $m[2]);
            }

            return $rewrite($key, $node);
        }
        if (! is_array($node)) {
            return $node;
        }
        foreach ($node as $k => $child) {
            $node[$k] = self::walkStrings($child, $rewrite, is_string($k) ? $k : $key);
        }

        return $node;
    }

    /** A literal value written into the compose file, where `$` would be interpolated. */
    private static function composeLiteral(string $value): string
    {
        return str_replace('$', '$$', $value);
    }

    /**
     * @param  array<string, mixed>  $services
     * @return array<string, true> placeholder text => seen
     */
    private static function collectPlaceholders(array $services): array
    {
        $tokens = [];
        foreach ($services as $service) {
            if (! is_array($service) || ! isset($service['environment'])) {
                continue;
            }
            self::rewriteEnvironment(
                $service['environment'],
                static function (string $key, string $value) use (&$tokens): string {
                    $token = self::placeholderIn($key, $value);
                    if ($token !== null) {
                        $tokens[$token] = true;
                    }

                    return $value;
                }
            );
        }

        return $tokens;
    }

    /** A credential hidden in a connection string still counts: DATABASE_URL is not a "password" key. */
    private static function placeholderIn(string $key, string $value): ?string
    {
        if (! self::isSecretKey($key)) {
            $embedded = self::embeddedPassword($value);

            return $embedded !== null && self::isPlaceholder($embedded) ? $embedded : null;
        }
        $trimmed = trim($value);

        return self::isPlaceholder($trimmed) ? $trimmed : null;
    }

    /** The password slot of scheme://user:password@host, rewritten. */
    private static function withEmbeddedPassword(string $value, string $password): string
    {
        return (string) preg_replace_callback(
            '#^(\s*[a-z][a-z0-9+.-]*://[^/@\s:]+:)[^/@\s]+@#i',
            static fn (array $m): string => $m[1].$password.'@',
            $value,
            1
        );
    }

    /** The password out of scheme://user:password@host, or null. */
    private static function embeddedPassword(string $value): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/@\s:]+:([^/@\s]+)@#i', trim($value), $m) !== 1) {
            return null;
        }

        return rawurldecode($m[1]);
    }

    /**
     * The password slot is exact, so even `changeme` is safe to replace there.
     *
     * @param  array<string, string>  $replacements
     */
    private static function applyReplacements(string $key, string $value, array $replacements): string
    {
        if ($replacements === []) {
            return $value;
        }
        $trimmed = trim($value);

        foreach ($replacements as $token => $secret) {
            $token = (string) $token;
            if ($trimmed === $token && self::isSecretKey($key)) {
                return $secret;
            }
            if (self::embeddedPassword($value) === $token) {
                $value = self::withEmbeddedPassword($value, $secret);
                $trimmed = trim($value);
            }
            if (self::isDistinctiveToken($token) && str_contains($value, $token)) {
                $value = str_replace($token, $secret, $value);
                $trimmed = trim($value);
            }
        }

        return $value;
    }

    /**
     * A port nothing in the stack publishes is a hop inside the container, and a sidecar key's localhost
     * URL is the sidecar's own; any other localhost port is fair game, as the whole value is replaced.
     *
     * @param  array<int, true>  $publishedPorts
     */
    private static function isLocalPublicUrl(string $key, string $value, array $publishedPorts = []): bool
    {
        if (preg_match(self::PUBLIC_URL_KEY_PATTERN, $key) !== 1) {
            return false;
        }
        if (preg_match(self::LOCAL_URL_PATTERN, trim($value), $m) !== 1) {
            return false;
        }
        $port = (int) ltrim($m[2] ?? '', ':');
        if ($port > 0 && $publishedPorts !== [] && ! isset($publishedPorts[$port])) {
            return false;
        }

        if (preg_match(self::SIDECAR_KEY_PATTERN, $key) === 1) {
            return false;
        }

        return true;
    }

    /** `http://localhost:4007/api` becomes `<public url>/api`: the path is the app's, only the address is ours. */
    private static function publicUrlWithLocalPath(string $value, string $publicUrl): string
    {
        preg_match(self::LOCAL_URL_PATTERN, trim($value), $m);
        $path = $m[3] ?? '';

        return $path === '' || $path === '/' ? $publicUrl : rtrim($publicUrl, '/').$path;
    }

    /**
     * Every port a service publishes, host and container side.
     *
     * @param  array<array-key, mixed>  $services
     * @return array<int, true>
     */
    private static function publishedPorts(array $services): array
    {
        $ports = [];
        foreach ($services as $service) {
            foreach (is_array($service) ? (array) ($service['ports'] ?? []) : [] as $entry) {
                if (is_array($entry)) {
                    $candidates = [$entry['target'] ?? null, $entry['published'] ?? null];
                } else {
                    $mapping = PortMapping::parse($entry);
                    $candidates = $mapping === null ? [] : [$mapping->hostPort, $mapping->containerPort];
                }
                foreach ($candidates as $port) {
                    if (is_numeric($port) && (int) $port > 0) {
                        $ports[(int) $port] = true;
                    }
                }
            }
        }

        return $ports;
    }

    /**
     * `${PA_PUBLIC_URL}` / `${PA_PUBLIC_HOST}` resolved, and an empty whole-URL key filled; `ROOT_URL=${ROOT_URL}`
     * with nothing setting it counts as empty.
     *
     * @param  array<string, string>  $accountEnv
     */
    private static function withPublicUrl(string $key, string $value, string $publicUrl, array $accountEnv = []): string
    {
        if (in_array(strtoupper($key), self::BLANK_URL_KEYS, true)) {
            $bare = preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)\}$/', trim($value), $m) === 1
                && trim((string) ($accountEnv[$m[1]] ?? '')) === '';
            if (trim($value) === '' || $bare) {
                return $publicUrl;
            }
        }

        return self::withPublicAddress($value, $publicUrl);
    }

    /**
     * `${PA_PUBLIC_URL}` / `${PA_PUBLIC_HOST}` (with or without a default) resolved in one value;
     * unchanged when there is no public URL.
     */
    public static function withPublicAddress(string $value, ?string $publicUrl): string
    {
        $publicUrl = self::normalisedPublicUrl($publicUrl);
        if ($publicUrl === null) {
            return $value;
        }
        $host = self::publicHostOf($publicUrl);

        return (string) preg_replace_callback(
            '/\$\{('.self::PUBLIC_URL_VARIABLE.'|'.self::PUBLIC_HOST_VARIABLE.')(?::?-[^}]*)?\}/',
            static fn (array $m): string => $m[1] === self::PUBLIC_URL_VARIABLE ? $publicUrl : $host,
            $value
        );
    }

    /** What a browser sends as Host for the URL: the name, with its port when that is not the scheme's own. */
    public static function publicHostOf(string $publicUrl): string
    {
        $host = (string) parse_url($publicUrl, PHP_URL_HOST);
        $port = parse_url($publicUrl, PHP_URL_PORT);

        return is_int($port) ? $host.':'.$port : $host;
    }

    /**
     * Every service that is not a datastore learns the address under the engine's own names; one that
     * sets them itself keeps its values.
     *
     * @param  array<array-key, mixed>  $services
     * @return array<array-key, mixed>
     */
    private static function withPublicUrlVariables(array $services, string $publicUrl): array
    {
        $values = [
            self::PUBLIC_URL_VARIABLE => $publicUrl,
            self::PUBLIC_HOST_VARIABLE => self::publicHostOf($publicUrl),
        ];
        foreach ($services as $name => $service) {
            if (! is_array($service) || ServiceRole::isKnownDatastore((string) $name, $service)) {
                continue;
            }
            $environment = $service['environment'] ?? [];
            if (! is_array($environment)) {
                continue;
            }
            $services[$name]['environment'] = self::withMissingVariables($environment, $values);
        }

        return $services;
    }

    /**
     * @param  array<array-key, mixed>  $environment
     * @param  array<string, string>  $values
     * @return array<array-key, mixed>
     */
    private static function withMissingVariables(array $environment, array $values): array
    {
        $isList = $environment !== [] && array_is_list($environment);
        foreach ($values as $key => $value) {
            if ($isList) {
                if (preg_grep('/^'.$key.'(=|$)/', array_map('strval', $environment)) === []) {
                    $environment[] = $key.'='.$value;
                }
            } elseif (! array_key_exists($key, $environment)) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    private static function normalisedPublicUrl(?string $publicUrl): ?string
    {
        if ($publicUrl === null) {
            return null;
        }
        $url = rtrim(trim($publicUrl), '/');

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    /**
     * Walks either compose environment form (map or `- KEY=value` list) and writes the callback's result
     * back in the same form; a list entry with no `=` inherits from the host and passes through untouched.
     *
     * @param  mixed  $environment
     * @param  callable(string, string): string  $rewrite
     * @return mixed
     */
    private static function rewriteEnvironment($environment, callable $rewrite)
    {
        if (! is_array($environment)) {
            return $environment;
        }

        $out = [];
        foreach ($environment as $key => $entry) {
            if (is_int($key)) {
                $out[$key] = self::rewrittenListEntry($entry, $rewrite);

                continue;
            }
            if ($entry === null || is_bool($entry) || is_array($entry)) {
                $out[$key] = $entry;

                continue;
            }
            $out[$key] = $rewrite((string) $key, (string) $entry);
        }

        return $out;
    }

    /**
     * @param  callable(string, string): string  $rewrite
     */
    private static function rewrittenListEntry(mixed $entry, callable $rewrite): mixed
    {
        if (! is_string($entry) || ! str_contains($entry, '=')) {
            return $entry;
        }
        [$name, $value] = explode('=', $entry, 2);

        return $name.'='.$rewrite(trim($name), $value);
    }
}
