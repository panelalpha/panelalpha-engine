<?php

namespace App\Lib\Deploy\Platform\Runtime\Python;

/**
 * The apt packages a Python image needs to build the project's dependencies
 * from source.
 *
 * Wheels hide this most of the time. When there is no wheel for this
 * interpreter, pip falls back to compiling, and on python:3.12-slim — no
 * compiler, no headers — that is not a slow install but a dead deploy:
 * `psycopg2-binary` stops with `Error: pg_config executable not found` for a
 * Django project whose requirements.txt looks entirely ordinary.
 *
 * Installing every database client would double the image and installing only
 * Postgres breaks every MySQL app, so the manifest decides. Same shape as
 * {@see \App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages}, kept apart while
 * the lists are all that differ — the lists being what anyone comes here to
 * edit.
 *
 * No Laravel dependencies — unit-testable.
 */
final class SystemPackages
{
    /**
     * Needed to build anything at all. Many setup.py builds shell out to
     * `pkg-config`, whose absence reads as a missing library rather than a
     * missing tool.
     *
     * @var list<string>
     */
    private const ALWAYS = ['build-essential', 'pkg-config', 'git', 'curl'];

    /**
     * Distribution name as requirements.txt spells it => apt package that lets
     * it compile. `psycopg2-binary` is here beside `psycopg2` because when its
     * promised wheel is missing pip builds the same C source.
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
            foreach (explode(' ', $libraries) as $library) {
                if (! in_array($library, $packages, true)) {
                    $packages[] = $library;
                }
            }
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
     * Bounded on both sides by the character set a distribution name may use,
     * because these names are substrings of one another constantly: `psycopg`
     * must not fire inside `psycopg2-binary`, and nothing should match inside a
     * URL or a comment.
     */
    private static function mentions(string $text, string $distribution): bool
    {
        $pattern = '/(?<![a-z0-9_.-])'.preg_quote($distribution, '/').'(?![a-z0-9_.-])/';

        return preg_match($pattern, $text) === 1;
    }
}
