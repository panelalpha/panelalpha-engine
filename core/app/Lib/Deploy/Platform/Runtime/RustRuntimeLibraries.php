<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * Shared libraries a Rust binary links that its runtime image does not have.
 *
 * Rust compiles in the full `rust:1-bookworm` and runs in `rust:1-slim-bookworm`.
 * A binary linking something only the full image carries -- diesel's libpq is
 * the common one -- compiles and then dies at start with
 * `libpq.so.5: cannot open shared object file`. Those libraries, and only
 * those, are copied from the build image into {@see DIR}, where the start
 * command points `LD_LIBRARY_PATH`. Both images are bookworm, so the copies
 * are the same package versions apt would install.
 *
 * Three scripts, each run in the project as the account: {@see checkScript()}
 * in the runtime image, and only when it finds a gap {@see bundleScript()} in
 * the build image and {@see verifyScript()} in the runtime image again.
 */
final class RustRuntimeLibraries
{
    /** Relative to the project, beside cargo's own output. */
    public const DIR = 'target/.panelalpha-libs';

    /** Written by the check only when something is missing: the signal to bundle. */
    public const MISSING_FILE = self::DIR . '/.missing';

    /** Sonames the runtime image resolves itself, which are never copied. */
    private const PROVIDED_FILE = self::DIR . '/.provided';

    /** The candidates the start command picks from. {@see RustRuntime::startCommand()} */
    private const BINARIES = "find ./target/release -maxdepth 1 -type f -perm -u+x ! -name '*.d' ! -name '*.so'";

    /** Each binary on its own, so ldd prints no per-file header. */
    private const LDD = self::BINARIES . ' -exec ldd {} \; 2>/dev/null';

    private const NOT_FOUND = "awk '\$2 == \"=>\" && \$3 == \"not\" {print \$1}' | sort -u";

    /**
     * Runtime image. Clears the last deploy's bundle, and writes
     * {@see MISSING_FILE} only when a binary names a library it cannot resolve.
     * A runtime image with no ldd is left alone, as before.
     */
    public static function checkScript(): string
    {
        return 'd=' . self::DIR . '; rm -rf "$d";'
            . ' command -v ldd >/dev/null 2>&1 || exit 0;'
            . ' m=$(' . self::LDD . ' | ' . self::NOT_FOUND . ');'
            . ' [ -z "$m" ] && exit 0;'
            . ' mkdir -p "$d" && printf \'%s\n\' $m > ' . self::MISSING_FILE . ' &&'
            . ' { ldconfig -p 2>/dev/null | awk \'NR > 1 {print $1}\';'
            . ' ' . self::LDD . ' | awk \'$2 == "=>" && $3 ~ /^\// {print $1}\'; } | sort -u > ' . self::PROVIDED_FILE . ' &&'
            . ' echo "PANELALPHA: the runtime image has no" $m';
    }

    /**
     * Build image, where the binary was linked. ldd's answer here is the whole
     * closure, so a library the missing one needs in turn (libpq's Kerberos
     * and LDAP) is found in the same pass; whatever the runtime image already
     * resolves stays out, glibc included.
     */
    public static function bundleScript(): string
    {
        return 'd=' . self::DIR . ';'
            . ' ' . self::LDD . ' | awk \'$2 == "=>" && $3 ~ /^\// {print $1, $3}\' | sort -u |'
            . ' while read -r so path; do'
            . ' grep -qxF "$so" ' . self::PROVIDED_FILE . ' && continue;'
            . ' cp -L "$path" "$d/$so" || exit 1;'
            . ' echo "PANELALPHA: bundling $so from the build image";'
            . ' done';
    }

    /**
     * Runtime image again, with the bundle on the path the start command will
     * set. Anything still unresolved fails the deploy here, naming it, rather
     * than as a container that restart-loops behind a 502.
     */
    public static function verifyScript(): string
    {
        return 'd=' . self::DIR . ';'
            . ' m=$(LD_LIBRARY_PATH="$PWD/$d" ' . self::LDD . ' | ' . self::NOT_FOUND . ');'
            . ' rm -f ' . self::MISSING_FILE . ' ' . self::PROVIDED_FILE . ';'
            . ' [ -z "$m" ] && exit 0;'
            . ' echo "PANELALPHA: the Rust binary needs" $m "and neither the runtime nor the build image has it" >&2;'
            . ' exit 1';
    }

    /**
     * The start command's first statement. A no-op for every binary that
     * needed nothing, which is every Rust deploy before this existed.
     */
    public static function startPrefix(): string
    {
        return 'if [ -d ' . self::DIR . ' ]; then'
            . ' export LD_LIBRARY_PATH="$PWD/' . self::DIR . '${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"; fi;';
    }
}
