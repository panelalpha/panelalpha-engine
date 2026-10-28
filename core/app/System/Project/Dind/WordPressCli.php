<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\System\Project\PhpHosting\WordPress\DatabaseClientMissing;
use App\System\Project\PhpHosting\WordPress\MustUsePlugin;
use App\System\Project\PhpHosting\WordPress\WordPressNotFound;
use Illuminate\Support\Str;

/**
 * WP-CLI for WordPress running in a project's own containers: the engine's
 * recipe, the official image from a client compose file, Bedrock, Bitnami.
 *
 * One script runs in the account: it finds the running container that has
 * both PHP and a WordPress tree, streams the account's wp-cli.phar into it
 * when missing or stale, and execs WP-CLI there as the owner of wp-content,
 * so plugin installs land with the web server's ownership and the container's
 * own environment (database credentials) applies. A command that writes into
 * the install directory itself runs as that directory's owner instead.
 *
 * WP-CLI's database commands shell out to mysql and mysqldump, which the
 * official wordpress image lacks: they run in the first container that has a
 * client as well, such as a wordpress:cli service beside the app.
 */
class WordPressCli
{
    public const PHAR = '/opt/wp-cli.phar';

    public const CONTAINER_PHAR = '/tmp/panelalpha-wp-cli.phar';

    /** Prefix of the script's own failures on stderr, as opposed to WP-CLI's. */
    public const MARKER = 'PANELALPHA_WPCLI:';

    /** The script's exit code when no container has PHP and WordPress at the requested path. */
    public const NOT_FOUND_EXIT = 71;

    /** The script's exit code when a database command finds WordPress only where there is no MySQL client. */
    public const NO_DATABASE_CLIENT_EXIT = 72;

    /** The `wp db` subcommands that run the mysql, mysqldump or mysqlcheck client rather than PHP. */
    public const DATABASE_CLIENT_COMMANDS = ['create', 'drop', 'reset', 'clean', 'check', 'optimize', 'repair', 'cli', 'query', 'export', 'import'];

    /**
     * The commands that write into the install directory itself: maintenance
     * mode creates and removes .maintenance there. On a bind-mounted checkout
     * that directory is the account's, which wp-content's owner cannot write.
     */
    public const INSTALL_DIRECTORY_COMMANDS = ['maintenance-mode'];

    /**
     * Runs in the target container with its default user and working
     * directory. A relative path is taken from that directory; with none,
     * the usual install directories are tried, then a search below them.
     * Prints the install path, the owner to run as, the size of the phar
     * already there, 1 when the MySQL client is there (else 0) and the owner
     * of the install directory; exits 3 without PHP, 4 without WordPress.
     */
    public const PROBE = <<<'SH'
        want=$1; phar=$2
        command -v php >/dev/null 2>&1 || exit 3
        found=
        case $want in ''|/*) ;; *) want=${PWD%/}/$want ;; esac
        if [ -n "$want" ]; then
          [ -f "$want/wp-includes/version.php" ] && found=$want
        else
          for d in "$PWD" "$PWD/public" "$PWD/web/wp" "$PWD/wp" "$PWD/wordpress" /var/www/html /var/www/html/wp \
                   /app /app/public /app/web/wp /opt/bitnami/wordpress /var/www/wordpress /srv/www/wordpress; do
            if [ -f "$d/wp-includes/version.php" ]; then found=$d; break; fi
          done
          if [ -z "$found" ]; then
            here=$PWD; [ "$here" = / ] && here=/var/www
            f=$(find "$here" /var/www /app /srv /opt /home -maxdepth 5 -path '*/wp-includes/version.php' 2>/dev/null | grep -v '^/usr/src/' | head -n 1)
            [ -n "$f" ] && found=${f%/wp-includes/version.php}
          fi
        fi
        [ -n "$found" ] || exit 4
        owner=
        for o in "$found/wp-content" "$found/../app" "$found"; do
          if [ -d "$o" ]; then owner=$(stat -c %u:%g "$o" 2>/dev/null) && break; fi
        done
        client=0
        command -v mysql >/dev/null 2>&1 && command -v mysqldump >/dev/null 2>&1 && command -v mysqlcheck >/dev/null 2>&1 && client=1
        tree=$(stat -c %u:%g "$found" 2>/dev/null)
        printf '%s\n%s\n%s\n%s\n%s\n' "$found" "${owner:-0:0}" "$(stat -c %s "$phar" 2>/dev/null || echo 0)" "$client" "${tree:-${owner:-0:0}}"
        SH;

    /**
     * Runs in the account as its user: $1 the requested path and $2 the
     * service (either may be empty), $3 the PHP memory limit, $4 code WP-CLI
     * runs first, $5 1 when the command needs the MySQL client, $6 1 when it
     * runs as the owner of the install directory, the rest WP-CLI's arguments.
     * A database command runs as that owner too, so a dump it writes or reads
     * there is the account's file. The last step execs WP-CLI, so stdout,
     * stderr and the exit code are WP-CLI's own.
     */
    public const SCRIPT = <<<'SH'
        set -u
        want=$1; service=$2; memory=$3; mend=$4; needs_client=$5; as_install_owner=$6; shift 6
        phar=__PHAR__; dest=__DEST__; probe=__PROBE__
        fail() { printf '%s %s\n' '__MARKER__' "$1" >&2; exit 70; }
        missing() { printf '%s %s\n' '__MARKER__' "$1" >&2; exit __NOT_FOUND__; }
        no_client() { printf '%s %s\n' '__MARKER__' "$1" >&2; exit __NO_CLIENT__; }
        without_client=
        [ -r "$phar" ] || fail 'WP-CLI is missing from this account. Rebuild the project to restore it.'
        size=$(stat -c %s "$phar")
        rows=$(docker ps --format '{{.ID}}|{{.Label "com.docker.compose.service"}}|{{.Image}}|{{.Names}}' 2>/dev/null)
        [ -n "$rows" ] || fail 'The application is not running. Deploy or start the project, then try again.'
        if [ -n "$service" ]; then
          rows=$(printf '%s\n' "$rows" | awk -F'|' -v s="$service" '$2 == s || $4 == s')
          [ -n "$rows" ] || fail "No running container of this project belongs to service $service."
        fi
        ids=$(printf '%s\n' "$rows" | awk -F'|' '{ p = 2; if ($2 $3 ~ /cli/) p = 3; else if ($2 ~ /wordpress|^wp/ || $3 ~ /wordpress/) p = 0; else if ($2 ~ /^(app|php|web|fpm)/) p = 1; print p, $1 }' | sort -s -n -k1,1 | cut -d' ' -f2)
        for id in $ids; do
          out=$(docker exec "$id" sh -c "$probe" sh "$want" "$dest" 2>/dev/null) || continue
          { read -r path; read -r owner; read -r have; read -r client; read -r tree; } <<< "$out"
          if [ "$needs_client" = 1 ]; then
            [ "$client" = 1 ] || { without_client=1; continue; }
          fi
          if [ "$as_install_owner" = 1 ]; then owner=$tree; fi
          if [ "$have" != "$size" ]; then
            docker exec -i "$id" sh -c 'cat > "$1.$$" && chmod 0644 "$1.$$" && mv -f "$1.$$" "$1"' sh "$dest" < "$phar" \
              || fail "Could not copy WP-CLI into the container: its /tmp is not writable."
          fi
          root=()
          [ "${owner%%:*}" = 0 ] && root=(--allow-root)
          exec docker exec -u "$owner" -e HOME=/tmp -e WP_CLI_CACHE_DIR=/tmp/.wp-cli-cache -w "$path" "$id" \
            php -d "memory_limit=$memory" "$dest" "--path=$path" "--exec=$mend" ${root[@]+"${root[@]}"} "$@"
        done
        where=${service:+ in service $service}
        [ -z "$without_client" ] || no_client "WP-CLI's database commands need the MySQL client (mysql, mysqldump and mysqlcheck), and no running container$where that has WordPress has one. Run them in a service whose image has the client, such as wordpress:cli with the same volumes."
        if [ -n "$want" ]; then
          missing "No WordPress with PHP was found at $want$where."
        fi
        [ -z "$service" ] || missing "No WordPress with PHP was found$where. Pass path with the WordPress directory as the container sees it."
        missing 'No WordPress install was found in this project'"'"'s containers. Pass path with the WordPress directory as the container sees it.'
        SH;

    public const MEMORY_LIMIT = '512M';

    public function __construct(
        private DindProject $dind,
    ) {}

    /**
     * @param  list<string>  $args  WP-CLI arguments; a --path= among them is
     *                              the install's path inside its container,
     *                              absolute or relative to its working directory
     * @param  string|null  $service  compose service to run in; omitted, the
     *                                first container with PHP and WordPress
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(array $args, ?string $service = null): array
    {
        $process = $this->dind->shell()->runProcessAsUser($this->command($args, $service));
        $stderr = $process->getErrorOutput();
        $exitCode = $process->getExitCode() ?? 1;

        $marker = strpos($stderr, self::MARKER);
        if ($exitCode !== 0 && $marker !== false) {
            $message = trim(substr($stderr, $marker + strlen(self::MARKER)));
            throw match ($exitCode) {
                self::NOT_FOUND_EXIT => new WordPressNotFound($message),
                self::NO_DATABASE_CLIENT_EXIT => new DatabaseClientMissing($message),
                default => new \RuntimeException($message),
            };
        }

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $stderr,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    public function command(array $args, ?string $service = null): array
    {
        $path = '';
        $rest = [];
        foreach ($args as $arg) {
            if (Str::startsWith($arg, '--path=')) {
                $path = rtrim(Str::after($arg, '--path='), '/');

                continue;
            }
            $rest[] = $arg;
        }

        $script = strtr(self::SCRIPT, [
            '__PHAR__' => escapeshellarg(self::PHAR),
            '__DEST__' => escapeshellarg(self::CONTAINER_PHAR),
            '__PROBE__' => escapeshellarg(self::PROBE),
            '__MARKER__' => self::MARKER,
            '__NOT_FOUND__' => (string) self::NOT_FOUND_EXIT,
            '__NO_CLIENT__' => (string) self::NO_DATABASE_CLIENT_EXIT,
        ]);
        $needsClient = self::needsDatabaseClient($rest);
        $asInstallOwner = $needsClient || self::writesInstallDirectory($rest);

        return [
            'bash', '-c', $script, 'wp', $path, (string) $service, self::MEMORY_LIMIT, MustUsePlugin::MEND_ON_LOAD,
            $needsClient ? '1' : '0', $asInstallOwner ? '1' : '0', ...$rest,
        ];
    }

    /** @param  list<string>  $args */
    public static function needsDatabaseClient(array $args): bool
    {
        $words = self::words($args);

        return ($words[0] ?? null) === 'db' && in_array($words[1] ?? null, self::DATABASE_CLIENT_COMMANDS, true);
    }

    /** @param  list<string>  $args */
    public static function writesInstallDirectory(array $args): bool
    {
        return in_array(self::words($args)[0] ?? null, self::INSTALL_DIRECTORY_COMMANDS, true);
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private static function words(array $args): array
    {
        return array_values(array_filter($args, fn (string $arg): bool => ! str_starts_with($arg, '-')));
    }
}
