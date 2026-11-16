<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

/**
 * The apt packages a Ruby image needs to compile the project's native gems, including the client
 * library of whichever database the Gemfile asks for.
 */
final class SystemPackages
{
    /**
     * Needed by every Ruby project; `libyaml-dev` because since Ruby 3.4 bundler compiles psych
     * against it for every Rails app, and the install stops without it.
     *
     * @var list<string>
     */
    private const ALWAYS = ['curl', 'git', 'libjemalloc2', 'build-essential', 'libyaml-dev'];

    /**
     * Only the client library the Gemfile asks for: all of them would triple the image, and Postgres
     * alone breaks every mysql2 and sqlite3 app.
     *
     * @var array<string, string> database gem => apt package
     */
    private const DATABASE_PACKAGES = [
        'pg' => 'libpq-dev',
        'mysql2' => 'default-libmysqlclient-dev',
        'trilogy' => 'default-libmysqlclient-dev',
        'sqlite3' => 'libsqlite3-dev',
    ];

    /**
     * Gems needing a system library they do not vendor, looked up in Gemfile.lock too; an FFI gem needs the
     * exact name its ffi_lib dlopens (`ffi_lib 'assimp'` wants the unversioned libassimp.so only -dev ships).
     *
     * @var array<string, string>
     */
    private const NATIVE_GEM_PACKAGES = [
        'idn-ruby' => 'libidn-dev',
        'ffi-libarchive' => 'libarchive13',
        'assimp-ffi' => 'libassimp-dev',
        'ruby-vips' => 'libvips42',
        'rbnacl' => 'libsodium23',
    ];

    /**
     * Postgres is the Rails 7+ default and the cheapest safe guess when no
     * database gem is recognised; an unused -dev package only costs build time.
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
     * @return list<string>
     */
    public static function for(Gemfile $gemfile): array
    {
        return array_merge(self::ALWAYS, self::databasePackages($gemfile), self::nativeGemPackages($gemfile));
    }

    /**
     * @return list<string>
     */
    private static function nativeGemPackages(Gemfile $gemfile): array
    {
        $packages = [];
        foreach (self::NATIVE_GEM_PACKAGES as $gem => $package) {
            if (($gemfile->requires($gem) || $gemfile->locks($gem)) && ! in_array($package, $packages, true)) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * @return list<string>
     */
    private static function databasePackages(Gemfile $gemfile): array
    {
        $packages = [];
        foreach (self::DATABASE_PACKAGES as $gem => $package) {
            if ($gemfile->requires($gem) && ! in_array($package, $packages, true)) {
                $packages[] = $package;
            }
        }

        return $packages ?: [self::DEFAULT_DATABASE_PACKAGE];
    }
}
