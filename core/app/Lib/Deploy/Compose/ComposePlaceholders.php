<?php

namespace App\Lib\Deploy\Compose;

/**
 * Fill in the blanks a project's own docker-compose.yml expects a human to
 * edit before the first `docker compose up`.
 *
 * Self-hosted projects ship a compose file with the secrets left out on
 * purpose — APP_SECRET: REPLACE_WITH_LONG_SECRET, POSTGRES_PASSWORD:
 * STRONG_DB_PASSWORD — and a README telling the operator to replace them.
 * Deployed verbatim, the app either refuses to start ("APP_SECRET must be
 * longer than or equal to 32 characters") or comes up on a password that is
 * printed in a public repository.
 *
 * There is nobody to edit that file here: the customer pasted a Git URL and
 * expects a running application. So the placeholders are filled in for them,
 * with values derived from the account secret — stable across rebuilds, so a
 * redeploy does not log everyone out or lock the app out of its own database.
 *
 * The same placeholder text is replaced everywhere it appears, which is what
 * keeps POSTGRES_PASSWORD and the password embedded in DATABASE_URL equal to
 * each other without having to understand either.
 *
 *
 * No Laravel dependencies — unit-testable.
 */
class ComposePlaceholders
{
    /**
     * Env names whose value is a credential. A placeholder anywhere else
     * (APP_NAME: CHANGE_ME) is cosmetic and left alone.
     */
    private const SECRET_KEY_PATTERN =
        '/(^|_)(SECRET|SECRETS|PASSWORD|PASSWD|PASS|TOKEN|KEY|KEYS|APIKEY|SALT|PEPPER|CREDENTIAL|CREDENTIALS|PASSPHRASE|SIGNING|ENCRYPTION)(_|$)/i';

    /**
     * Values a project ships meaning "put your own here". Anchored to the
     * whole value: a real secret that merely starts with one of these words
     * (testKey_9f3b…) does not match.
     */
    private const PLACEHOLDER_PATTERNS = [
        // REPLACE_WITH_LONG_SECRET, CHANGE_ME, YOUR_API_KEY, STRONG_DB_PASSWORD…
        '/^(replace|replaceme|change|changeme|change_?this|your|my|our|some|insert|put|enter|fill|set|generate|generated|random|example|sample|dummy|placeholder|todo|tbd|unset|none|secret|password|passwd|pass|admin|strong|long|super_?secret|top_?secret|s3cr3t)([_\- ].*)?$/i',
        // …_HERE, …_GOES_HERE, …_ME, …_THIS
        '/[_\- ](here|goes[_\- ]here|me|this|placeholder|example)$/i',
        // <your-secret>, {{ secret }}, ${CHANGE_ME} with no default
        '/^<.+>$/',
        '/^\{\{.*\}\}$/',
        // xxxxxx, ******, ......
        '/^(x{4,}|\*{3,}|\.{3,}|-{3,})$/i',
    ];

    /**
     * Names that sign or encrypt for the app itself, so a value we invent is
     * as good as any. Third-party credentials are excluded: inventing one only
     * breaks the integration.
     */
    private const PUBLISHED_SECRET_KEY_PATTERN =
        '/SECRET|SALT|PEPPER|SIGNING|ENCRYPTION|^(?=.*(APP_|JWT|SESSION|COOKIE)).*_KEY$/i';
    private const THIRD_PARTY_KEY_PATTERN = '/API_?KEY|PUBLIC_KEY|ACCESS_KEY|CLIENT_SECRET/i';

    /** Well-known repo placeholders (Planka's notsecretkey, Saleor's changeme). */
    private const PUBLISHED_SECRET_VALUES = [
        'changeme', 'change_me', 'change-me', 'changethis', 'notsecretkey', 'secret', 'mysecret',
        'your-secret-key', 'your_secret_key', 'yoursecretkey', 'please-change-me', 'replace-me', 'todo',
    ];

    /**
     * Env names that name a public address. Left at http://localhost:3000 the
     * app builds every link and redirect against a host the visitor's browser
     * cannot reach.
     */
    private const PUBLIC_URL_KEY_PATTERN = '/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i';

    private const LOCAL_URL_PATTERN = '#^https?://(localhost|127\.0\.0\.1|0\.0\.0\.0|host\.docker\.internal)(:\d+)?/?$#i';

    /**
     * `${JWT_SECRET:?JWT_SECRET must be set}` -- Compose's fail-closed form,
     * used by projects that would rather not start than start on a guessable
     * secret. Nothing supplies a value here, so `docker compose up` refuses to
     * interpolate and the deploy dies before a container exists.
     *
     * Only the whole value: a variable embedded in a URL is a different
     * problem and is left alone.
     */
    private const REQUIRED_VAR_PATTERN = '/^\$\{([A-Za-z_][A-Za-z0-9_]*):\?[^}]*\}$/';

    /**
     * @param array<string, mixed> $compose
     * @param string $seed per-account secret the generated values derive from
     * @return array{
     *   compose: array<string, mixed>,
     *   secrets: list<string>,
     *   urls: list<string>,
     *   published: list<string>,
     * } the rewritten compose plus the env names touched, for the deploy log
     * @param array<string, string> $accountEnv the account's env_vars, which win over a generated value
     */
    public static function fill(array $compose, string $seed, ?string $publicUrl = null, array $accountEnv = []): array
    {
        $services = $compose['services'] ?? null;
        if (!is_array($services) || $services === []) {
            return ['compose' => $compose, 'secrets' => [], 'urls' => [], 'published' => []];
        }

        $tokens = self::collectPlaceholders($services);
        $replacements = [];
        foreach (array_keys($tokens) as $token) {
            $replacements[(string) $token] = self::generatedSecret((string) $token, $seed);
        }

        $touchedSecrets = [];
        $touchedUrls = [];
        $touchedPublished = [];
        $publicUrl = self::normalisedPublicUrl($publicUrl);

        foreach ($services as $name => $service) {
            if (!is_array($service) || !isset($service['environment'])) {
                continue;
            }
            $services[$name]['environment'] = self::rewriteEnvironment(
                $service['environment'],
                static function (string $key, string $value) use ($replacements, $seed, $publicUrl, $accountEnv, &$touchedSecrets, &$touchedUrls, &$touchedPublished): string {
                    $published = self::isPublishedSecret($key, $value);
                    // APP_KEY skips the hex filler: Laravel needs base64:<32 bytes>.
                    $filled = $published && strcasecmp($key, 'APP_KEY') === 0
                        ? $value
                        : self::applyReplacements($key, $value, $replacements);
                    if ($filled !== $value) {
                        $touchedSecrets[$key] = true;

                        return $filled;
                    }
                    $required = self::requiredSecret($key, $value, $seed);
                    if ($required !== null) {
                        $touchedSecrets[$key] = true;

                        return $required;
                    }
                    if ($published) {
                        $own = (string) ($accountEnv[$key] ?? '');
                        if ($own !== '') {
                            return $own;
                        }
                        $touchedPublished[$key] = true;

                        return self::publishedSecret($key, $seed);
                    }
                    if ($publicUrl !== null && self::isLocalPublicUrl($key, $value)) {
                        $touchedUrls[$key] = true;

                        return $publicUrl;
                    }

                    return $value;
                }
            );
        }

        $compose['services'] = $services;

        return [
            'compose' => $compose,
            'secrets' => array_keys($touchedSecrets),
            'urls' => array_keys($touchedUrls),
            'published' => array_keys($touchedPublished),
        ];
    }

    /**
     * A value for a required variable the project left to the operator.
     *
     * Derived from the *variable* name rather than the key, so the same
     * variable referenced from two services resolves to the same secret --
     * Etherpad's postgres password is read by both the app and the database,
     * and they have to agree.
     *
     * Only credentials. A required variable naming a hostname or a port is
     * not something to invent, and inventing one would start an app pointed
     * at somewhere that does not exist rather than let it say what it needs.
     */
    public static function requiredSecret(string $key, string $value, string $seed): ?string
    {
        if (preg_match(self::REQUIRED_VAR_PATTERN, trim($value), $m) !== 1) {
            return null;
        }
        if (!self::isSecretKey($m[1]) && !self::isSecretKey($key)) {
            return null;
        }

        return self::generatedSecret($m[1], $seed);
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

    /** A signing/encryption key still set to a placeholder everyone can read. */
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
            // One character repeated: the shape a template uses when the key
            // has to be a fixed length. Homarr's SECRET_ENCRYPTION_KEY is 64
            // zeroes, which is valid 32-byte hex, so its own validation
            // accepts it and nothing anywhere fails -- every deploy would
            // encrypt its stored integration credentials under a value
            // published in the upstream repository. Nothing that is actually
            // a secret is one character repeated.
            || preg_match('/^(.)\1{7,}$/', $value) === 1;
    }

    /**
     * A value that is a blank to fill in rather than a value.
     *
     * Only ever asked of a `.env.example`, which is by convention a template
     * of things the operator must replace -- that is what the suffix means.
     * Treating one as runtime configuration inverts its purpose, and doing so
     * through `env_file:` puts it *above* the image's own ENV: Homarr's image
     * ships `DB_URL=/appdata/db/db.sqlite` and its template says
     * `DB_URL=FULL_PATH_TO_YOUR_SQLITE_DB_FILE`, so the account ran with the
     * blank. The migration wrote `/app/FULL_PATH_TO_YOUR_SQLITE_DB_FILE` and
     * the server opened a different, empty one: `no such table: session`,
     * 1026 restarts in 35 minutes, behind a deploy reported successful.
     *
     * Deliberately narrow. A placeholder here is either bracketed, or an
     * all-caps sentence in snake case carrying a word that says "fill me in"
     * -- so `LOG_LEVEL=DEBUG`, `APP_ENV=production` and `TZ=UTC` are values,
     * not blanks. Being wrong in the other direction costs an override the
     * image would have supplied anyway; being wrong this way is what it was
     * doing already.
     */
    public static function isTemplatePlaceholder(string $value): bool
    {
        $value = trim(trim($value), '"\'');
        if ($value === '') {
            return false;
        }

        // <your-token>, {{TOKEN}}, [TOKEN] -- unambiguous in any casing.
        if (preg_match('/^(<.+>|\{\{.+\}\}|\[.+\])$/', $value) === 1) {
            return true;
        }

        // An all-caps snake-case phrase, which is a sentence rather than a
        // value: at least one underscore, no lowercase, nothing but word
        // characters. `DEBUG` and `UTC` do not qualify on the underscore.
        if (preg_match('/^[A-Z0-9]+(_[A-Z0-9]+)+$/', $value) !== 1) {
            return false;
        }

        return preg_match(self::PLACEHOLDER_WORD_PATTERN, $value) === 1;
    }

    /**
     * The words that turn an all-caps phrase into an instruction.
     *
     * `PATH_TO` rather than `PATH`, because `DEFAULT_PATH` is a value and
     * `FULL_PATH_TO_YOUR_DB` is not.
     */
    private const PLACEHOLDER_WORD_PATTERN =
        '/(^|_)(YOUR|YOURS|CHANGE|CHANGEME|REPLACE|TODO|FIXME|PLACEHOLDER|INSERT|ENTER|SOME)(_|$)'
        . '|PATH_TO|_HERE$|^XXX/';

    /** Seeded by key name, so it survives redeploys; APP_KEY gets Laravel's format. */
    public static function publishedSecret(string $key, string $seed): string
    {
        if (strcasecmp($key, 'APP_KEY') === 0) {
            return 'base64:' . base64_encode(hash_hmac('sha256', 'compose-placeholder:APP_KEY', $seed, true));
        }

        return self::generatedSecret($key, $seed);
    }

    /**
     * Distinctive enough to search for inside other values. STRONG_DB_PASSWORD
     * can safely be replaced wherever it appears — including in the middle of
     * postgresql://user:STRONG_DB_PASSWORD@db/app. A token like "secret" or
     * "admin" cannot: it occurs inside ordinary words.
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
     * Hex, so the value survives being embedded in a URL, a YAML scalar or a
     * shell command without quoting or escaping; 48 characters, so it clears
     * the "at least 32" minimum these projects check for.
     */
    public static function generatedSecret(string $token, string $seed): string
    {
        return substr(hash_hmac('sha256', 'compose-placeholder:' . $token, $seed), 0, 48);
    }

    /**
     * @param array<string, mixed> $services
     * @return array<string, true> placeholder text => seen
     */
    private static function collectPlaceholders(array $services): array
    {
        $tokens = [];
        foreach ($services as $service) {
            if (!is_array($service) || !isset($service['environment'])) {
                continue;
            }
            self::rewriteEnvironment(
                $service['environment'],
                static function (string $key, string $value) use (&$tokens): string {
                    if (!self::isSecretKey($key)) {
                        // A credential hidden in a connection string still
                        // counts: DATABASE_URL is not a "password" key.
                        $embedded = self::embeddedPassword($value);
                        if ($embedded !== null && self::isPlaceholder($embedded)) {
                            $tokens[$embedded] = true;
                        }

                        return $value;
                    }
                    $trimmed = trim($value);
                    if (self::isPlaceholder($trimmed)) {
                        $tokens[$trimmed] = true;
                    }

                    return $value;
                }
            );
        }

        return $tokens;
    }

    /**
     * The password out of scheme://user:password@host, or null.
     */
    private static function embeddedPassword(string $value): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/@\s:]+:([^/@\s]+)@#i', trim($value), $m) !== 1) {
            return null;
        }

        return rawurldecode($m[1]);
    }

    /**
     * @param array<string, string> $replacements
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
            if (self::isDistinctiveToken($token) && str_contains($value, $token)) {
                $value = str_replace($token, $secret, $value);
                $trimmed = trim($value);
            }
        }

        return $value;
    }

    private static function isLocalPublicUrl(string $key, string $value): bool
    {
        if (preg_match(self::PUBLIC_URL_KEY_PATTERN, $key) !== 1) {
            return false;
        }
        if (preg_match(self::LOCAL_URL_PATTERN, trim($value), $m) !== 1) {
            return false;
        }

        // Datastore / cache HTTP gateways use http://localhost:<sidecar-port>.
        // Rewriting those to the public site URL breaks Upstash-style clients.
        if (preg_match('/(REDIS|DATABASE|POSTGRES|MYSQL|MONGO|CACHE|QUEUE|UPSTASH|TYPESENSE|MEILI|SRH)_/i', $key) === 1
            || preg_match('/_(REDIS|DATABASE|POSTGRES|MYSQL|MONGO|CACHE|QUEUE)_URL$/i', $key) === 1
        ) {
            return false;
        }

        $port = isset($m[2]) ? (int) substr($m[2], 1) : 80;
        if ($port > 0 && !in_array($port, [80, 443, 3000, 3001, 5173, 8000, 8080, 8081], true)) {
            return false;
        }

        return true;
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
     * Walks either compose environment form — the `KEY: value` map or the
     * `- KEY=value` list — and writes the callback's result back in the same
     * form. List entries with no `=` inherit from the host environment; they
     * carry no value to replace and are passed through untouched.
     *
     * @param mixed $environment
     * @param callable(string, string): string $rewrite
     * @return mixed
     */
    private static function rewriteEnvironment($environment, callable $rewrite)
    {
        if (!is_array($environment)) {
            return $environment;
        }

        $out = [];
        foreach ($environment as $key => $entry) {
            if (is_int($key)) {
                if (!is_string($entry) || !str_contains($entry, '=')) {
                    $out[$key] = $entry;
                    continue;
                }
                [$name, $value] = explode('=', $entry, 2);
                $out[$key] = $name . '=' . $rewrite(trim($name), $value);
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
}
