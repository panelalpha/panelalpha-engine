<?php

namespace App\Lib\Deploy\Platform\Runtime\Python;

/**
 * The apt packages a Python image needs when pip finds no wheel and must compile on a headerless slim
 * image; kept apart from the Ruby class while only the edited lists differ. No Laravel dependencies.
 *
 * @see \App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages
 */
final class SystemPackages
{
    /**
     * Needed to build anything at all; a missing `pkg-config` reads as a missing library.
     *
     * @var list<string>
     */
    private const ALWAYS = ['build-essential', 'pkg-config', 'git', 'curl'];

    /**
     * Distribution name as requirements.txt spells it => apt packages that let it compile;
     * `psycopg2-binary` builds the same C source when its wheel is missing.
     *
     * @var array<string, string>
     */
    private const PACKAGE_LIBRARIES = [
        'psycopg2' => 'libpq-dev',
        'psycopg2-binary' => 'libpq-dev',
        'psycopg' => 'libpq-dev',
        'mysqlclient' => 'default-libmysqlclient-dev',
        'pymysql' => 'default-libmysqlclient-dev',
        'pillow' => 'libjpeg-dev zlib1g-dev',
        'lxml' => 'libxml2-dev libxslt1-dev',
        'pyyaml' => 'libyaml-dev',
        'cryptography' => 'libssl-dev libffi-dev',
        'pycurl' => 'libcurl4-openssl-dev libssl-dev',
        'python-ldap' => 'libldap2-dev libsasl2-dev',
        'cffi' => 'libffi-dev',
    ];

    /**
     * The guess when nothing is recognised: Django's default, and an unused
     * -dev package costs host build time once rather than a failed deploy.
     */
    private const DEFAULT_DATABASE_PACKAGE = 'libpq-dev';

    /**
     * What every base of this runtime carries, whatever the project asks for: a catalogue entry names only what it adds.
     *
     * @return list<string>
     */
    public static function always(): array
    {
        return self::ALWAYS;
    }

    /**
     * Only the database clients the manifests mention: all of them would double the image, and
     * Postgres alone breaks every MySQL app.
     *
     * @param  list<string>  $manifests  contents of requirements.txt, pyproject.toml,
     *                                   Pipfile — whatever the project ships
     * @return list<string>
     */
    public static function for(array $manifests): array
    {
        $text = strtolower(implode("\n", array_filter($manifests, 'is_string')));

        $packages = self::ALWAYS;
        $matched = false;
        foreach (self::PACKAGE_LIBRARIES as $distribution => $libraries) {
            if (! self::mentions($text, $distribution)) {
                continue;
            }
            $packages = self::withLibraries($packages, $libraries);
            if ($libraries === self::DEFAULT_DATABASE_PACKAGE
                || str_contains($libraries, 'libmysqlclient')
            ) {
                $matched = true;
            }
        }

        if (! $matched && ! in_array(self::DEFAULT_DATABASE_PACKAGE, $packages, true)) {
            $packages[] = self::DEFAULT_DATABASE_PACKAGE;
        }

        return $packages;
    }

    /**
     * @param  list<string>  $packages
     * @return list<string>
     */
    private static function withLibraries(array $packages, string $libraries): array
    {
        foreach (explode(' ', $libraries) as $library) {
            if (! in_array($library, $packages, true)) {
                $packages[] = $library;
            }
        }

        return $packages;
    }

    /**
     * Bounded on both sides by the distribution-name character set, so `psycopg` does not fire
     * inside `psycopg2-binary`, a URL or a comment.
     */
    private static function mentions(string $text, string $distribution): bool
    {
        $pattern = '/(?<![a-z0-9_.-])'.preg_quote($distribution, '/').'(?![a-z0-9_.-])/';

        return preg_match($pattern, $text) === 1;
    }
}
