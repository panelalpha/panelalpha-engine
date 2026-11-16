<?php

namespace App\System\Privileged;

/** Refuses values a root command could misread, such as an option or a path with `..`. Not business rules. */
final class Arguments
{
    private const NAME = '/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/';

    private const USERNAME = '/\A[a-z_][a-z0-9_-]{0,31}\$?\z/';

    private const OWNER_PART = '(?:[a-z_][a-z0-9_-]{0,31}\$?|[0-9]+)';

    private const OCTAL_MODE = '/\A[0-7]{3,4}\z/';

    private const SYMBOLIC_MODE = '/\A[ugoa]*[-+=][rwxXst]*(?:,[ugoa]*[-+=][rwxXst]*)*\z/';

    private const IMAGE_DOMAIN = '(?:[a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9])(?:\.(?:[a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]))*(?::[0-9]+)?';

    private const IMAGE_PATH = '[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*';

    private const IMAGE_TAG = '[\w][\w.-]{0,127}';

    private const IMAGE_DIGEST = '[A-Za-z][A-Za-z0-9]*(?:[-_+.][A-Za-z][A-Za-z0-9]*)*:[0-9a-fA-F]{32,}';

    private const IMAGE_ID = '/\A(?:sha256:)?[0-9a-f]{12,64}\z/';

    private const APACHE_MODULE = '/\A[a-z0-9_]+\z/';

    private const ENVIRONMENT_NAME = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    private const SETTING_VALUE = '~\A[A-Za-z0-9_./@:,*+-]{0,16384}\z~';

    private const HOSTNAME = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*';

    public static function path(string $path): string
    {
        if (! str_starts_with($path, '/') || preg_match('~[\x00\n\r]~', $path) === 1 || preg_match('~(?:^|/)\.\.(?:/|$)~', $path) === 1) {
            throw InvalidArgument::of('path', $path);
        }

        return $path;
    }

    /** An absolute path without the `:` that would split `-v source:target`. */
    public static function mountPath(string $path): string
    {
        if (str_contains(self::path($path), ':')) {
            throw InvalidArgument::of('mount path', $path);
        }

        return $path;
    }

    /**
     * @param  array<int, string>  $paths
     * @return list<string>
     */
    public static function paths(array $paths): array
    {
        return array_map(self::path(...), array_values($paths));
    }

    /** A container, service, network or volume name, as Docker allows one. */
    public static function name(string $name): string
    {
        return self::matching(self::NAME, 'name', $name);
    }

    public static function username(string $username): string
    {
        return self::matching(self::USERNAME, 'username', $username);
    }

    /** `user`, `user:group`, `uid` or `uid:gid`. */
    public static function owner(string $owner): string
    {
        return self::matching('/\A'.self::OWNER_PART.'(?::'.self::OWNER_PART.')?\z/', 'owner', $owner);
    }

    public static function mode(string $mode): string
    {
        if (preg_match(self::OCTAL_MODE, $mode) !== 1 && preg_match(self::SYMBOLIC_MODE, $mode) !== 1) {
            throw InvalidArgument::of('mode', $mode);
        }

        return $mode;
    }

    /** An image reference (`registry:port/path:tag@digest`) or an image ID. */
    public static function image(string $image): string
    {
        $reference = '/\A(?:'.self::IMAGE_DOMAIN.'\/)?'.self::IMAGE_PATH.'(?:\/'.self::IMAGE_PATH.')*'
            .'(?::'.self::IMAGE_TAG.')?(?:@'.self::IMAGE_DIGEST.')?\z/';
        if (preg_match($reference, $image) !== 1 && preg_match(self::IMAGE_ID, $image) !== 1) {
            throw InvalidArgument::of('image', $image);
        }

        return $image;
    }

    /**
     * @param  array<int, string>  $images
     * @return list<string>
     */
    public static function images(array $images): array
    {
        return array_map(self::image(...), array_values($images));
    }

    /** A registry repository name (lowercase path parts, no tag), never the traversal a `rm -rf` on its directory must refuse. */
    public static function registryRepository(string $repository): string
    {
        $pattern = '/\A'.self::IMAGE_PATH.'(?:\/'.self::IMAGE_PATH.')*\z/';
        if (preg_match($pattern, $repository) !== 1) {
            throw InvalidArgument::of('registry repository', $repository);
        }

        return $repository;
    }

    public static function apacheModule(string $module): string
    {
        return self::matching(self::APACHE_MODULE, 'Apache module', $module);
    }

    /** A DNS name, and with $wildcard a leading `*.` too. */
    public static function hostname(string $name, bool $wildcard = false): string
    {
        $pattern = '/\A(?=.{1,253}\z)'.($wildcard ? '(?:\*\.)?' : '').self::HOSTNAME.'\z/';

        return self::matching($pattern, 'hostname', $name);
    }

    /** An environment variable or build argument name. */
    public static function environmentName(string $name): string
    {
        return self::matching(self::ENVIRONMENT_NAME, 'environment variable name', $name);
    }

    /** An argv word that is not an option and holds no NUL or line break. */
    public static function operand(string $value): string
    {
        if (str_starts_with($value, '-') || preg_match('~[\x00\n\r]~', $value) === 1) {
            throw InvalidArgument::of('argument', $value);
        }

        return $value;
    }

    /** `.` or one name below tar's `-C`, so tar meets no link on the way to it. */
    public static function archiveEntry(string $entry): string
    {
        if ($entry !== '.' && (in_array($entry, ['', '..'], true) || str_contains($entry, '/'))) {
            throw InvalidArgument::of('archive entry', $entry);
        }

        return self::operand($entry);
    }

    /** A tar `--exclude` pattern with no leading `/` and no `..`. */
    public static function archivePattern(string $pattern): string
    {
        if ($pattern === '' || str_starts_with($pattern, '/') || in_array('..', explode('/', $pattern), true)) {
            throw InvalidArgument::of('archive pattern', $pattern);
        }

        return self::operand($pattern);
    }

    /** A setting's value as `.env` keeps it unquoted: one word, no quote, space or line break. */
    public static function settingValue(string $value): string
    {
        return self::matching(self::SETTING_VALUE, 'setting value', $value);
    }

    private static function matching(string $pattern, string $kind, string $value): string
    {
        if (preg_match($pattern, $value) !== 1) {
            throw InvalidArgument::of($kind, $value);
        }

        return $value;
    }
}
