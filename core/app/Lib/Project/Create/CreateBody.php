<?php

namespace App\Lib\Project\Create;

use App\Exceptions\ProblemException;
use App\Http\Requests\Wp\WpCoreDownloadRequest;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Vault\RequestVault;

/**
 * A typed create (or source attach) body, checked for shape and mapped onto the flat fields the create validates,
 * so both bodies answer the same codes.
 */
final class CreateBody
{
    /** @var list<array<string, mixed>> */
    private array $problems = [];

    /**
     * @param  array<string, mixed>  $body
     * @param  bool  $attach  a PUT /projects/{name}/source body: `source` and `build` only
     */
    private function __construct(private readonly array $body, private readonly bool $attach) {}

    /** @param array<string, mixed> $body */
    public static function forCreate(array $body): self
    {
        return new self($body, false);
    }

    /** @param array<string, mixed> $body */
    public static function forAttach(array $body): self
    {
        return new self($body, true);
    }

    /**
     * @throws ProblemException every shape problem at once
     */
    public function assertWellFormed(): void
    {
        $this->problems = [];
        $this->checkTopLevel();
        $this->checkGroups();
        $this->checkSource();
        $this->checkCombinations();

        if ($this->problems !== []) {
            throw ProblemException::of($this->problems);
        }
    }

    public function sourceType(): string
    {
        return CreateShape::sourceTypeOf($this->body['source'] ?? null);
    }

    public function source(): SourceSpec
    {
        $source = $this->body['source'] ?? null;

        return SourceSpec::of(is_array($source) ? $source : ['type' => CreateShape::SOURCE_NONE]);
    }

    /** The runtime asked for, else the one the source implies, else a container. */
    public function runtime(): string
    {
        $runtime = $this->body['runtime'] ?? null;
        if (is_string($runtime) && $runtime !== '') {
            return $runtime;
        }

        return CreateShape::impliedRuntime($this->sourceType()) ?? CreateShape::RUNTIME_CONTAINER;
    }

    /**
     * The flat create fields this body maps onto.
     *
     * @return array<string, mixed>
     */
    public function flat(): array
    {
        $flat = [];
        foreach (CreateShape::SCALARS as $key => $field) {
            if (array_key_exists($key, $this->body)) {
                $flat[$field] = $this->body[$key];
            }
        }
        foreach (CreateShape::GROUPS as $group => $fields) {
            $flat += $this->flatGroup($group, $fields);
        }
        if (! $this->attach && $this->sourceType() !== CreateShape::SOURCE_PROJECT) {
            $flat['template'] = CreateShape::templateFor($this->runtime());
        }

        return $flat + $this->flatGitSource();
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function flatGroup(string $group, array $fields): array
    {
        $values = $this->body[$group] ?? null;
        if (! is_array($values)) {
            return [];
        }

        $flat = [];
        foreach ($fields as $key => $field) {
            if (array_key_exists($key, $values)) {
                $flat[$field] = $values[$key];
            }
        }

        return $flat;
    }

    /** @return array<string, mixed> */
    private function flatGitSource(): array
    {
        if ($this->sourceType() !== CreateShape::SOURCE_GIT) {
            return [];
        }

        $source = $this->source();
        $flat = ['git_repo' => $source->url(), 'git_branch' => $source->branch()];
        if ($source->authType() === CreateShape::AUTH_TOKEN) {
            $flat['git_token'] = $source->token();
        }

        return array_filter($flat, static fn (mixed $v): bool => $v !== null);
    }

    private function checkTopLevel(): void
    {
        $allowed = $this->attach
            ? ['source', 'build']
            : ['name', 'runtime', 'email', 'site_password', 'domain', 'limits', 'settings', 'network', 'source', 'build'];

        foreach (array_keys($this->body) as $key) {
            if (in_array($key, $allowed, true)) {
                continue;
            }
            $this->legacyOrUnknown((string) $key);
        }

        $runtime = $this->body['runtime'] ?? null;
        if (! $this->attach && $runtime !== null && ! in_array($runtime, CreateShape::RUNTIMES, true)) {
            $this->problem('runtime', 'runtime_invalid',
                'runtime must be one of: '.implode(', ', CreateShape::RUNTIMES).'.');
        }
    }

    private function legacyOrUnknown(string $key): void
    {
        $replacement = CreateShape::LEGACY_FIELDS[$key] ?? null;
        if ($replacement !== null && ! $this->attach) {
            $this->problem($key, 'legacy_field',
                "`{$key}` belongs to the flat create body; this body takes it as `{$replacement}`.",
                ['replacement' => $replacement]);

            return;
        }

        $this->problem($key, 'unknown_field', "`{$key}` is not a field of this body.");
    }

    private function checkGroups(): void
    {
        $groups = $this->attach ? ['build' => CreateShape::GROUPS['build']] : CreateShape::GROUPS;
        foreach ($groups as $group => $fields) {
            if (! array_key_exists($group, $this->body) || $this->body[$group] === null) {
                continue;
            }
            if (! $this->isObject($this->body[$group])) {
                $this->mustBeObject($group, $group === 'domain' ? '{"name": "shop.example.com"}' : null);

                continue;
            }
            $this->unknownKeys($group, $this->body[$group], array_keys($fields));
        }
    }

    private function checkSource(): void
    {
        $source = $this->body['source'] ?? null;
        if ($source === null) {
            if ($this->attach) {
                $this->problem('source', 'source_required', 'source is required.');
            }

            return;
        }
        if (! $this->isObject($source)) {
            $this->mustBeObject('source', '{"type": "git", "url": "https://github.com/owner/repo"}');

            return;
        }

        $type = $source['type'] ?? null;
        $types = $this->attach ? CreateShape::ATTACHABLE_SOURCE_TYPES : CreateShape::SOURCE_TYPES;
        if ($type === null) {
            $this->problem('source.type', 'source_type_required', 'source.type is required.');

            return;
        }
        if (! in_array($type, $types, true)) {
            $this->problem('source.type', 'source_type_invalid', 'source.type must be one of: '.implode(', ', $types).'.');

            return;
        }

        $this->unknownKeys('source', $source, ['type', ...$this->sourceKeys($type)], "a {$type} source");
        match ($type) {
            CreateShape::SOURCE_GIT => $this->checkGitSource($source),
            CreateShape::SOURCE_ARCHIVE => $this->checkArchiveSource($source),
            CreateShape::SOURCE_PROJECT => $this->checkProjectSource($source),
            CreateShape::SOURCE_WORDPRESS => $this->checkWordpressSource($source),
            default => null,
        };
    }

    /** @return list<string> */
    private function sourceKeys(string $type): array
    {
        $keys = CreateShape::SOURCE_KEYS[$type];

        return $this->attach ? $keys : array_values(array_diff($keys, ['path']));
    }

    /** @param array<string, mixed> $source */
    private function checkGitSource(array $source): void
    {
        $url = $source['url'] ?? null;
        if (! $this->requireString('source.url', $url)) {
            return;
        }
        $this->optionalString('source.branch', $source['branch'] ?? null);

        $authType = $this->checkAuth($source['auth'] ?? null);
        if ($authType === null) {
            return;
        }
        $this->checkTransport((string) $url, $authType);
    }

    /** The auth type given, `none` when absent; null when the auth object is malformed. */
    private function checkAuth(mixed $auth): ?string
    {
        if ($auth === null) {
            return CreateShape::AUTH_NONE;
        }
        if (! $this->isObject($auth)) {
            $this->mustBeObject('source.auth', '{"type": "token", "token": "vault:Kq3xT9bLmW2vR8nYp4sZ6cHd"}');

            return null;
        }
        $this->unknownKeys('source.auth', $auth, CreateShape::AUTH_KEYS);

        $type = $auth['type'] ?? null;
        if (! in_array($type, CreateShape::AUTH_TYPES, true)) {
            $this->problem('source.auth.type', $type === null ? 'source_auth_type_required' : 'source_auth_type_invalid',
                'source.auth.type must be one of: '.implode(', ', CreateShape::AUTH_TYPES).'.');

            return null;
        }

        $this->checkAuthSecret($type, $auth);

        return $type;
    }

    /** @param array<string, mixed> $auth */
    private function checkAuthSecret(string $type, array $auth): void
    {
        $needs = match ($type) {
            CreateShape::AUTH_TOKEN => 'token',
            CreateShape::AUTH_DEPLOY_KEY => 'key',
            default => null,
        };
        foreach (['token', 'key'] as $key) {
            if ($key !== $needs && array_key_exists($key, $auth)) {
                $this->problem("source.auth.{$key}", 'field_not_allowed', "source.auth.{$key} goes only with auth type ".($key === 'token' ? 'token' : 'deploy_key').'.');
            }
        }
        if ($needs === null || ! $this->requireString("source.auth.{$needs}", $auth[$needs] ?? null)) {
            return;
        }
        if ($needs === 'key' && ! RequestVault::isReference($auth['key'])) {
            $this->problem('source.auth.key', 'source_auth_key_invalid',
                'source.auth.key must be a vault reference (vault:<key>) to a deploy key created in the vault.');
        }
    }

    /** An SSH remote authenticates only with a deploy key, and a deploy key only over SSH. */
    private function checkTransport(string $url, string $authType): void
    {
        $ssh = GitRepoInput::isSsh($url);
        if ($ssh && $authType !== CreateShape::AUTH_DEPLOY_KEY) {
            $this->problem('source.url', 'source_ssh_needs_deploy_key',
                'An SSH remote clones only with a deploy key: create one in the vault, add its public key to the '
                .'repository, and send it as source.auth {"type": "deploy_key", "key": "vault:<key>"}.',
                array_filter(['suggestion' => GitRepoInput::httpsEquivalent($url)]));
        }
        if (! $ssh && $authType === CreateShape::AUTH_DEPLOY_KEY) {
            $this->problem('source.auth.type', 'source_deploy_key_needs_ssh',
                'A deploy key authenticates an SSH remote (git@host:owner/repo.git or ssh://...); '
                .'an HTTPS URL takes auth type token or none.');
        }
    }

    /** @param array<string, mixed> $source */
    private function checkArchiveSource(array $source): void
    {
        $given = array_values(array_filter(
            $this->sourceKeys(CreateShape::SOURCE_ARCHIVE),
            static fn (string $key): bool => ($source[$key] ?? null) !== null,
        ));
        if (count($given) !== 1) {
            $this->problem('source', 'source_archive_needs_one',
                'An archive source takes exactly one of: '.implode(', ', $this->sourceKeys(CreateShape::SOURCE_ARCHIVE)).'.');

            return;
        }

        $key = $given[0];
        if (! $this->requireString("source.{$key}", $source[$key])) {
            return;
        }
        if ($key === 'url' && ! preg_match('#^https?://#i', (string) $source['url'])) {
            $this->problem('source.url', 'source_url_invalid', 'source.url must be an http:// or https:// URL of a .zip or .tar.gz archive.');
        }
    }

    /** @param array<string, mixed> $source */
    private function checkProjectSource(array $source): void
    {
        $this->requireString('source.from', $source['from'] ?? null);
        $copy = $source['copy_databases'] ?? null;
        if ($copy !== null && ! is_bool($copy)) {
            $this->problem('source.copy_databases', 'source_copy_databases_invalid', 'source.copy_databases must be true or false.');
        }
    }

    /** @param array<string, mixed> $source */
    private function checkWordpressSource(array $source): void
    {
        if ($this->requireString('source.title', $source['title'] ?? null) && mb_strlen((string) $source['title']) > 255) {
            $this->problem('source.title', 'source_title_too_long', 'source.title must be at most 255 characters.');
        }
        $this->checkPattern('source.locale', $source['locale'] ?? null, WpCoreDownloadRequest::LOCALE_PATTERN, 'a WordPress locale such as en_US or de_DE');
        $this->checkPattern('source.version', $source['version'] ?? null, WpCoreDownloadRequest::VERSION_PATTERN, 'latest, or a version such as 6.6 or 6.6.2');

        $admin = $source['admin'] ?? null;
        if (! $this->isObject($admin)) {
            $admin === null
                ? $this->problem('source.admin', 'source_admin_required', 'source.admin is required: {"user", "email", "password"}.')
                : $this->mustBeObject('source.admin', '{"user": "admin", "email": "you@example.com", "password": "..."}');

            return;
        }
        $this->unknownKeys('source.admin', $admin, CreateShape::WORDPRESS_ADMIN_KEYS);
        $this->checkWordpressAdmin($admin);
    }

    /** @param array<string, mixed> $admin */
    private function checkWordpressAdmin(array $admin): void
    {
        if ($this->requireString('source.admin.user', $admin['user'] ?? null) && ! preg_match('/^[A-Za-z0-9 _.@-]{1,60}$/', (string) $admin['user'])) {
            $this->problem('source.admin.user', 'source_admin_user_invalid', 'source.admin.user must be 1-60 letters, digits, spaces or _ . @ -.');
        }
        if ($this->requireString('source.admin.email', $admin['email'] ?? null) && filter_var($admin['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->problem('source.admin.email', 'source_admin_email_invalid', 'source.admin.email must be an email address.');
        }
        if ($this->requireString('source.admin.password', $admin['password'] ?? null) && mb_strlen((string) $admin['password']) < 8) {
            $this->problem('source.admin.password', 'source_admin_password_too_short', 'source.admin.password must be at least 8 characters.');
        }
    }

    private function checkCombinations(): void
    {
        if ($this->attach) {
            return;
        }

        $type = $this->sourceType();
        $runtime = $this->body['runtime'] ?? null;
        $implied = CreateShape::impliedRuntime($type);

        if ($type === CreateShape::SOURCE_PROJECT) {
            $this->refuseOnCopy();

            return;
        }
        if (is_string($runtime) && $implied !== null && $runtime !== $implied) {
            $this->problem('runtime', 'source_conflicts_with_runtime',
                "A {$type} source runs as {$implied}; omit runtime or send {$implied}.");
        }
        $this->checkBuildFor($type);
    }

    /** A copy takes everything but its name and domain from the project it copies. */
    private function refuseOnCopy(): void
    {
        foreach (['runtime', 'email', 'site_password', 'limits', 'settings', 'network', 'build'] as $key) {
            if (($this->body[$key] ?? null) !== null) {
                $this->problem($key, 'field_not_allowed',
                    "A project source copies {$key} from the original project, so it is not sent.");
            }
        }
        $domain = $this->body['domain'] ?? null;
        foreach (['tunnel', 'redirect_url'] as $key) {
            if (is_array($domain) && ($domain[$key] ?? null) !== null) {
                $this->problem("domain.{$key}", 'field_not_allowed', "A project source takes domain.name only, not domain.{$key}.");
            }
        }
    }

    /** Only a container project builds, and an empty one has nothing to build yet. */
    private function checkBuildFor(string $type): void
    {
        $build = $this->body['build'] ?? null;
        if (! is_array($build)) {
            return;
        }
        if ($this->runtime() === CreateShape::RUNTIME_PHP_HOSTING) {
            $this->problem('build', 'field_not_allowed', 'build applies to a container project; a php_hosting project builds nothing.');

            return;
        }
        if ($type !== CreateShape::SOURCE_NONE) {
            return;
        }
        foreach (['recipe', 'stages'] as $key) {
            if (($build[$key] ?? null) !== null) {
                $this->problem("build.{$key}", 'field_not_allowed',
                    "An empty project deploys nothing yet; send build.{$key} with the source that brings its files.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $allowed
     */
    private function unknownKeys(string $path, array $values, array $allowed, ?string $what = null): void
    {
        foreach (array_keys($values) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->problem("{$path}.{$key}", 'unknown_field',
                    "`{$key}` is not a key of ".($what ?? $path).'; it takes: '.implode(', ', $allowed).'.');
            }
        }
    }

    private function requireString(string $path, mixed $value): bool
    {
        if (is_string($value) && trim($value) !== '') {
            return true;
        }

        $code = str_replace('.', '_', $path);
        $value === null
            ? $this->problem($path, "{$code}_required", "{$path} is required.")
            : $this->problem($path, "{$code}_invalid", "{$path} must be a non-empty string.");

        return false;
    }

    private function optionalString(string $path, mixed $value): void
    {
        if ($value !== null && ! is_string($value)) {
            $this->problem($path, str_replace('.', '_', $path).'_invalid', "{$path} must be a string.");
        }
    }

    private function checkPattern(string $path, mixed $value, string $pattern, string $expected): void
    {
        if ($value === null) {
            return;
        }
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            $this->problem($path, str_replace('.', '_', $path).'_invalid', "{$path} must be {$expected}.");
        }
    }

    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private function mustBeObject(string $path, ?string $example): void
    {
        $this->problem($path, str_replace('.', '_', $path).'_must_be_object',
            "{$path} must be an object".($example !== null ? ", for example {$example}" : '').'.');
    }

    /** @param array<string, mixed> $extra */
    private function problem(string $field, string $code, string $message, array $extra = []): void
    {
        $this->problems[] = ['field' => $field, 'code' => $code, 'message' => $message] + $extra;
    }
}
