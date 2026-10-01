<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\PortMapping;
use App\Lib\Deploy\Sidecar\ServiceRole;

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
     * A key that names a backing service rather than the site. Its
     * `http://localhost:<port>` is the sidecar's own address and has to
     * survive: rewriting it to the public origin points the app at its own
     * website instead of its search index, object store or mail catcher.
     *
     * Anchored to the start of the key or a `_` boundary, so `MAIL_URL` is a
     * mail server but `WEBMAIL_URL` is a webmail app's own address, and
     * `MEILISEARCH_URL` -- the key Meilisearch actually ships -- is caught
     * where a trailing-underscore form only ever caught `MEILI_URL`.
     */
    private const SIDECAR_KEY_PATTERN = '/(^|_)(REDIS|VALKEY|DATABASE|POSTGRES|PGSQL|MYSQL|MARIADB|MONGO|CACHE|QUEUE|BROKER|UPSTASH|TYPESENSE|MEILI|SRH|ELASTIC|OPENSEARCH|SOLR|CLICKHOUSE|MINIO|S3|BUCKET|QDRANT|CHROMA|WEAVIATE|OLLAMA|MAIL|SMTP|AMQP|RABBIT|KAFKA|NATS|INFLUX)/i';

    /**
     * `${JWT_SECRET:?JWT_SECRET must be set}` -- Compose's fail-closed form,
     * used by projects that would rather not start than start on a guessable
     * secret. Nothing supplies a value here, so `docker compose up` refuses to
     * interpolate and the deploy dies before a container exists.
     *
     * Only the whole value, for {@see requiredSecret()}; {@see fill()} finds
     * every reference itself with REFERENCE_PATTERN.
     */
    private const REQUIRED_VAR_PATTERN = '/^\$\{([A-Za-z_][A-Za-z0-9_]*):\?([^}]*)\}$/';

    /**
     * A length the `:?` message asks for: `openssl rand -hex 32` is 64
     * characters, "at least 64 characters" is 64.
     */
    private const LENGTH_HINT_PATTERN = '/rand\s+-hex\s+(\d+)|(\d+)\s*(?:characters|chars)\b/i';

    private const MAX_HINTED_LENGTH = 256;

    /**
     * Any `${VAR}` / `${VAR<op>word}` reference, or Compose's `$$` escape so a
     * literal `$${VAR}` is skipped. A nested `${A:-${B}}` is not matched.
     */
    private const REFERENCE_PATTERN = '/\$\$|\$\{([A-Za-z_][A-Za-z0-9_]*)(:?[-?+][^}$]*)?\}/';

    /**
     * The account's address, given to every service that is not a datastore
     * and substituted where a compose file writes `${PA_PUBLIC_URL}`, so a
     * stack can hand it to whatever key its app reads (engine#192).
     */
    public const PUBLIC_URL_VARIABLE = 'PA_PUBLIC_URL';

    public const PUBLIC_HOST_VARIABLE = 'PA_PUBLIC_HOST';

    /**
     * Keys that hold the whole public URL. Set but empty, the app gets `''`,
     * which is worse than unset (SvelteKit: `Invalid ORIGIN: ''`), so an empty
     * one is filled. The short list on purpose: an empty `WEBHOOK_URL` is a
     * third party's address, not ours. BASE_URL is left out: blank there
     * usually means "serve from /" ({@see PublicUrlEnvironment::blankFillKeys()}).
     */
    private const BLANK_URL_KEYS = ['URL', 'PUBLIC_URL', 'APP_URL', 'ASSET_URL', 'SITE_URL', 'ORIGIN'];

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

        // Settled across the whole file first: Compose interpolates every
        // string in it, `x-` fragments and DSNs included, so one miss aborts.
        [$required, $generated] = self::requiredVariables($compose, $seed, $accountEnv);
        if ($required !== []) {
            $compose = self::withRequiredVariables($compose, $required);
            $services = $compose['services'];
        }

        $tokens = self::collectPlaceholders($services);
        $replacements = [];
        foreach (array_keys($tokens) as $token) {
            $replacements[(string) $token] = self::generatedSecret((string) $token, $seed);
        }

        $touchedSecrets = array_fill_keys($generated, true);
        $touchedUrls = [];
        $touchedPublished = [];
        $publicUrl = self::normalisedPublicUrl($publicUrl);
        $publishedPorts = self::publishedPorts($services);

        foreach ($services as $name => $service) {
            if (!is_array($service) || !isset($service['environment'])) {
                continue;
            }
            $services[$name]['environment'] = self::rewriteEnvironment(
                $service['environment'],
                static function (string $key, string $value) use ($replacements, $seed, $publicUrl, $accountEnv, $publishedPorts, &$touchedSecrets, &$touchedUrls, &$touchedPublished): string {
                    $published = self::isPublishedSecret($key, $value);
                    // APP_KEY skips the hex filler: Laravel needs base64:<32 bytes>.
                    $filled = $published && strcasecmp($key, 'APP_KEY') === 0
                        ? $value
                        : self::applyReplacements($key, $value, $replacements);
                    $own = (string) ($accountEnv[$key] ?? '');
                    if ($filled !== $value) {
                        // Inline beats env_file, so the account's value has to be written here to win.
                        if ($own !== '') {
                            return self::composeLiteral($own);
                        }
                        $touchedSecrets[$key] = true;

                        return $filled;
                    }
                    if ($published) {
                        if ($own !== '') {
                            return self::composeLiteral($own);
                        }
                        $touchedPublished[$key] = true;

                        return self::publishedSecret($key, $seed, $value);
                    }
                    if ($publicUrl !== null && self::isLocalPublicUrl($key, $value, $publishedPorts)) {
                        $touchedUrls[$key] = true;

                        return $publicUrl;
                    }
                    if ($publicUrl !== null) {
                        $withUrl = self::withPublicUrl($key, $value, $publicUrl);
                        if ($withUrl !== $value) {
                            $touchedUrls[$key] = true;

                            return $withUrl;
                        }
                    }

                    return $value;
                }
            );
        }
        if ($publicUrl !== null) {
            $services = self::withPublicUrlVariables($services, $publicUrl);
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

        // fill() reads no message past a `$`, so neither does this.
        return self::requiredSecretValue($m[1], $seed, str_contains($m[2], '$') ? 0 : self::hintedLength($m[2]));
    }

    /**
     * The generated value of a required variable: {@see generatedSecret()},
     * lengthened when its message asks for more than that. Rustrak's
     * `openssl rand -hex 32` key refuses 48 characters ("at least 64 are
     * required"). Never shortened, so a value that already works stays put.
     */
    public static function requiredSecretValue(string $name, string $seed, int $length = 0): string
    {
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

    /**
     * Seeded by key name, so it survives redeploys; APP_KEY gets Laravel's format.
     * A placeholder longer than 48 that is one character repeated keeps its
     * length: that is a fixed-length key, and Homarr refuses its 64-zero
     * SECRET_ENCRYPTION_KEY's replacement at 48 ("has to be 64 characters").
     */
    public static function publishedSecret(string $key, string $seed, string $placeholder = ''): string
    {
        if (strcasecmp($key, 'APP_KEY') === 0) {
            return 'base64:' . base64_encode(hash_hmac('sha256', 'compose-placeholder:APP_KEY', $seed, true));
        }

        $placeholder = trim(trim($placeholder), '"\'');
        if (strlen($placeholder) > 48 && preg_match('/^(.)\1+$/', $placeholder) === 1) {
            return self::generatedSecretOfLength($key, $seed, strlen($placeholder));
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

    /** Hex of exactly $length characters, starting with {@see generatedSecret()}. */
    private static function generatedSecretOfLength(string $token, string $seed, int $length): string
    {
        $hex = hash_hmac('sha256', 'compose-placeholder:' . $token, $seed);
        for ($block = 1; strlen($hex) < $length; $block++) {
            $hex .= hash_hmac('sha256', 'compose-placeholder:' . $token . ':' . $block, $seed);
        }

        return substr($hex, 0, $length);
    }

    /**
     * Every required (`:?` / `?`) variable naming a credential, with the one
     * value it gets everywhere: the account's own, else one from the seed.
     * A credential key vouches for its variable only as the key's whole value.
     *
     * @param array<string, mixed> $compose
     * @param array<string, string> $accountEnv
     * @return array{0: array<string, string>, 1: list<string>} name => value, and the names generated
     */
    private static function requiredVariables(array $compose, string $seed, array $accountEnv): array
    {
        $names = [];
        self::walkStrings($compose, static function (string $key, string $value) use (&$names): string {
            preg_match_all(self::REFERENCE_PATTERN, $value, $refs, PREG_SET_ORDER);
            foreach ($refs as $ref) {
                $name = $ref[1] ?? '';
                if ($name === '' || preg_match('/^:?\?/', $ref[2] ?? '') !== 1) {
                    continue;
                }
                if (self::isSecretKey($name) || (self::isSecretKey($key) && trim($value) === $ref[0])) {
                    // The longest any reference asks for, so every reference agrees.
                    $names[$name] = max($names[$name] ?? 0, self::hintedLength($ref[2]));
                }
            }

            return $value;
        });

        $values = [];
        $generated = [];
        foreach ($names as $name => $length) {
            $name = (string) $name;
            $own = (string) ($accountEnv[$name] ?? '');
            if ($own !== '') {
                $values[$name] = self::composeLiteral($own);
                continue;
            }
            $values[$name] = self::requiredSecretValue($name, $seed, $length);
            $generated[] = $name;
        }

        return [$values, $generated];
    }

    /**
     * Resolve every reference to the given variables as Compose would with
     * them set: `${VAR:+alt}` answers alt, every other form answers the value.
     *
     * @param array<string, mixed> $compose
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private static function withRequiredVariables(array $compose, array $values): array
    {
        return self::walkStrings($compose, static fn (string $key, string $value): string => (string) preg_replace_callback(
            self::REFERENCE_PATTERN,
            static function (array $m) use ($values): string {
                $name = $m[1] ?? '';
                if ($name === '' || !isset($values[$name])) {
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
     * Every string scalar in a compose document, with the key it sits under
     * (a list entry `KEY=value` counts as KEY); map keys are never touched.
     *
     * @param callable(string, string): string $rewrite
     */
    private static function walkStrings(mixed $node, callable $rewrite, string $key = ''): mixed
    {
        if (is_string($node)) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $node, $m) === 1) {
                return $m[1] . '=' . $rewrite($m[1], $m[2]);
            }

            return $rewrite($key, $node);
        }
        if (!is_array($node)) {
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

    /** The password slot of scheme://user:password@host, rewritten. */
    private static function withEmbeddedPassword(string $value, string $password): string
    {
        return (string) preg_replace_callback(
            '#^(\s*[a-z][a-z0-9+.-]*://[^/@\s:]+:)[^/@\s]+@#i',
            static fn (array $m): string => $m[1] . $password . '@',
            $value,
            1
        );
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
            // The password slot is exact, so even `changeme` is safe to replace there.
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
     * @param array<int, true> $publishedPorts
     */
    private static function isLocalPublicUrl(string $key, string $value, array $publishedPorts = []): bool
    {
        if (preg_match(self::PUBLIC_URL_KEY_PATTERN, $key) !== 1) {
            return false;
        }
        if (preg_match(self::LOCAL_URL_PATTERN, trim($value), $m) !== 1) {
            return false;
        }
        // A port nothing in the stack publishes is a hop inside the container
        // (Invio's frontend reaching its own backend on :3000), never the
        // address a browser was given.
        $port = (int) ltrim($m[2] ?? '', ':');
        if ($port > 0 && $publishedPorts !== [] && !isset($publishedPorts[$port])) {
            return false;
        }

        // Datastore / cache / sidecar HTTP gateways use
        // http://localhost:<sidecar-port>. Rewriting those to the public site
        // URL breaks Upstash-style clients, and is now the only thing standing
        // between a sidecar and the rewrite: the port allowlist that used to
        // catch what this pattern missed is gone.
        if (preg_match(self::SIDECAR_KEY_PATTERN, $key) === 1) {
            return false;
        }

        // The whole value is replaced by the public origin, so the placeholder's
        // port is discarded either way -- any localhost port is fair game here.
        return true;
    }

    /**
     * Every port a service publishes, host and container side.
     *
     * @param array<array-key, mixed> $services
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
     * `${PA_PUBLIC_URL}` / `${PA_PUBLIC_HOST}` (with or without a default)
     * resolved, and an empty whole-URL key filled.
     */
    private static function withPublicUrl(string $key, string $value, string $publicUrl): string
    {
        if (trim($value) === '' && in_array(strtoupper($key), self::BLANK_URL_KEYS, true)) {
            return $publicUrl;
        }

        return self::withPublicAddress($value, $publicUrl);
    }

    /**
     * `${PA_PUBLIC_URL}` / `${PA_PUBLIC_HOST}` (with or without a default)
     * resolved in one value; unchanged when there is no public URL. The
     * generated compose of the framework strategies uses it for a recipe's `env:`.
     */
    public static function withPublicAddress(string $value, ?string $publicUrl): string
    {
        $publicUrl = self::normalisedPublicUrl($publicUrl);
        if ($publicUrl === null) {
            return $value;
        }
        $host = (string) parse_url($publicUrl, PHP_URL_HOST);

        return (string) preg_replace_callback(
            '/\$\{(' . self::PUBLIC_URL_VARIABLE . '|' . self::PUBLIC_HOST_VARIABLE . ')(?::?-[^}]*)?\}/',
            static fn (array $m): string => $m[1] === self::PUBLIC_URL_VARIABLE ? $publicUrl : $host,
            $value
        );
    }

    /**
     * Every service that is not a datastore learns the address under the
     * engine's own names; one that sets them itself keeps its values.
     *
     * @param array<array-key, mixed> $services
     * @return array<array-key, mixed>
     */
    private static function withPublicUrlVariables(array $services, string $publicUrl): array
    {
        $values = [
            self::PUBLIC_URL_VARIABLE => $publicUrl,
            self::PUBLIC_HOST_VARIABLE => (string) parse_url($publicUrl, PHP_URL_HOST),
        ];
        foreach ($services as $name => $service) {
            if (!is_array($service) || ServiceRole::isKnownDatastore((string) $name, $service)) {
                continue;
            }
            $environment = $service['environment'] ?? [];
            if (!is_array($environment)) {
                continue;
            }
            $isList = $environment !== [] && array_is_list($environment);
            foreach ($values as $key => $value) {
                if ($isList) {
                    if (preg_grep('/^' . $key . '(=|$)/', array_map('strval', $environment)) === []) {
                        $environment[] = $key . '=' . $value;
                    }
                } elseif (!array_key_exists($key, $environment)) {
                    $environment[$key] = $value;
                }
            }
            $services[$name]['environment'] = $environment;
        }

        return $services;
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
