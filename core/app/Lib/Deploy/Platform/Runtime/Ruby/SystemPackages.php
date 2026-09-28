<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

/**
 * The apt packages a Ruby image needs to compile the project's native gems.
 *
 * Native builds need the client library of whichever database the Gemfile
 * actually asks for. Installing all of them would triple the image;
 * installing only Postgres breaks every mysql2 and sqlite3 app.
 */
final class SystemPackages
{
    /**
     * Needed by every Ruby project, whatever it stores its data in.
     *
     * `libyaml-dev` is the one that is not obvious and is not optional. psych
     * is Ruby's YAML parser; it ships as a default gem but builds a native
     * extension against libyaml, and since Ruby 3.4 it is no longer bundled
     * with the interpreter -- so bundler resolves and compiles it. Anything
     * pulling in rdoc does, which is railties, which is every Rails app. The
     * failure is `An error occurred while installing psych (5.2.6), and
     * Bundler cannot continue`, four dependency levels from anything the
     * Gemfile mentions, and it stops the whole install.
     *
     * @var list<string>
     */
    private const ALWAYS = ['curl', 'git', 'libjemalloc2', 'build-essential', 'libyaml-dev'];

    /** @var array<string, string> database gem => apt package */
    private const DATABASE_PACKAGES = [
        'pg' => 'libpq-dev',
        'mysql2' => 'default-libmysqlclient-dev',
        'trilogy' => 'default-libmysqlclient-dev',
        'sqlite3' => 'libsqlite3-dev',
    ];

    /**
     * Gems whose C extension links a system library they do not vendor, by
     * gem => apt package. Looked up in Gemfile.lock too, since these arrive
     * transitively: idn-ruby through twitter-text, and extconf fails without
     * the headers.
     *
     * @var array<string, string>
     */
    private const NATIVE_GEM_PACKAGES = [
        'idn-ruby' => 'libidn-dev',
    ];

    /**
     * Postgres is the Rails 7+ default and the cheapest safe guess when no
     * database gem is recognised; an unused -dev package only costs build time.
     */
    private const DEFAULT_DATABASE_PACKAGE = 'libpq-dev';

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
            if (($gemfile->requires($gem) || $gemfile->locks($gem)) && !in_array($package, $packages, true)) {
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
            if ($gemfile->requires($gem) && !in_array($package, $packages, true)) {
                $packages[] = $package;
            }
        }

        return $packages ?: [self::DEFAULT_DATABASE_PACKAGE];
    }
}
