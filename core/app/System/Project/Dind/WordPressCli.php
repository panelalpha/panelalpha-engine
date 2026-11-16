<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;
use App\System\Project\PhpHosting\WordPress\DatabaseClientMissing;
use App\System\Project\PhpHosting\WordPress\MustUsePlugin;
use App\System\Project\PhpHosting\WordPress\WordPressNotFound;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Illuminate\Support\Str;

/**
 * WP-CLI in a project's own WordPress container, as wp-content's owner so installs get the web server's ownership and
 * the container's environment; database commands need a MySQL client the official image lacks, so go to one that has it.
 */
class WordPressCli
{
    public const CONTAINER_PHAR = '/tmp/panelalpha-wp-cli.phar';

    /** The engine's phar, below the dind project template; the root broker copies it in by {@see ENGINE_FILE}. */
    public const ENGINE_PHAR = 'wp-cli/wp-cli.phar';

    public const ENGINE_FILE = 'wp-cli';

    /** The `wp db` subcommands that run the mysql, mysqldump or mysqlcheck client rather than PHP. */
    public const DATABASE_CLIENT_COMMANDS = ['create', 'drop', 'reset', 'clean', 'check', 'optimize', 'repair', 'cli', 'query', 'export', 'import'];

    /**
     * Commands that write into the install directory itself (maintenance mode's .maintenance), which on a
     * bind-mounted checkout is the account's and not writable by wp-content's owner.
     */
    public const INSTALL_DIRECTORY_COMMANDS = ['maintenance-mode'];

    /**
     * Runs in the container as its default user: prints the install path, the owner to run as, the size of the
     * phar there, 1 when the MySQL client is there (else 0) and the install's owner; exits 3 without PHP, 4 without WordPress.
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

    public const MEMORY_LIMIT = '512M';

    private const SERVICE_LABEL = 'com.docker.compose.service';

    private const ENVIRONMENT = ['HOME' => '/tmp', 'WP_CLI_CACHE_DIR' => '/tmp/.wp-cli-cache'];

    private const TIMEOUT_SECONDS = 600;

    public function __construct(
        private DindProject $dind,
    ) {}

    /**
     * A database command runs as the install's owner too, so a dump it writes or reads there is the account's file.
     *
     * @param  list<string>  $args  WP-CLI arguments; a --path= among them is
     *                              the install's path inside its container,
     *                              absolute or relative to its working directory
     * @param  string|null  $service  compose service to run in; omitted, the
     *                                first container with PHP and WordPress
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(array $args, ?string $service = null): array
    {
        [$path, $rest] = self::pathAndRest($args);
        $service = (string) $service;
        $needsClient = self::needsDatabaseClient($rest);
        $asInstallOwner = $needsClient || self::writesInstallDirectory($rest);
        $size = (string) $this->enginePharSize();
        $withoutClient = false;
        foreach ($this->candidates($service) as $id) {
            $found = $this->probe($id, $path);
            if ($found === null) {
                continue;
            }
            if ($needsClient && $found['client'] !== '1') {
                $withoutClient = true;

                continue;
            }
            if ($found['have'] !== $size) {
                $this->copyPhar($id);
            }

            return $this->wpCli($id, $found['path'], $asInstallOwner ? $found['tree'] : $found['owner'], $rest);
        }

        throw self::nothingFound($path, $service, $withoutClient);
    }

    /**
     * @param  list<string>  $args
     * @return array{0: string, 1: list<string>}
     */
    private static function pathAndRest(array $args): array
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

        return [$path, $rest];
    }

    private function enginePharSize(): int
    {
        $phar = $this->dind->system()->projectFilesTemplateDirPath('dind').'/'.self::ENGINE_PHAR;
        $size = is_file($phar) && is_readable($phar) ? filesize($phar) : false;
        if ($size === false) {
            throw new \RuntimeException("WP-CLI is missing from the engine: {$phar} is not readable.");
        }

        return $size;
    }

    /**
     * The running containers' ids, $service's alone when given, WordPress ones first and WP-CLI ones last.
     *
     * @return list<string>
     */
    private function candidates(string $service): array
    {
        $rows = $this->runningContainers();
        if ($rows === []) {
            throw new \RuntimeException('The application is not running. Deploy or start the project, then try again.');
        }
        if ($service !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['service'] === $service || $row['names'] === $service));
            if ($rows === []) {
                throw new \RuntimeException("No running container of this project belongs to service {$service}.");
            }
        }
        usort($rows, static fn (array $a, array $b): int => self::preference($a) <=> self::preference($b));

        return array_column($rows, 'id');
    }

    /**
     * What `docker ps` prints of each running container; none when the daemon cannot say.
     *
     * @return list<array{id: string, service: string, image: string, names: string}>
     */
    private function runningContainers(): array
    {
        try {
            $listed = $this->dind->accountDaemon()->listContainers(false, [], self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return [];
        }
        $rows = [];
        foreach ($listed as $row) {
            if (is_string($row['Id'] ?? null)) {
                $labels = is_array($row['Labels'] ?? null) ? $row['Labels'] : [];
                $rows[] = [
                    'id' => $row['Id'],
                    'service' => (string) ($labels[self::SERVICE_LABEL] ?? ''),
                    'image' => (string) ($row['Image'] ?? ''),
                    'names' => self::names(is_array($row['Names'] ?? null) ? $row['Names'] : []),
                ];
            }
        }

        return $rows;
    }

    /**
     * `{{.Names}}`: the names without their leading slash, only the first one that is not a link's when there is one.
     *
     * @param  array<int, mixed>  $names
     */
    private static function names(array $names): string
    {
        $names = array_map(static fn (mixed $name): string => preg_replace('~\A/~', '', (string) $name) ?? '', array_values($names));
        foreach ($names as $name) {
            if (! str_contains($name, '/')) {
                return $name;
            }
        }

        return implode(',', $names);
    }

    /** @param  array{id: string, service: string, image: string, names: string}  $row */
    private static function preference(array $row): int
    {
        return match (true) {
            str_contains($row['service'].$row['image'], 'cli') => 3,
            str_contains($row['service'], 'wordpress') || str_starts_with($row['service'], 'wp') || str_contains($row['image'], 'wordpress') => 0,
            preg_match('/\A(app|php|web|fpm)/', $row['service']) === 1 => 1,
            default => 2,
        };
    }

    /**
     * {@see PROBE}'s five lines, or null when it fails in $id.
     *
     * @return array{path: string, owner: string, have: string, client: string, tree: string}|null
     */
    private function probe(string $id, string $path): ?array
    {
        $probe = $this->dind->accountDaemon()->exec(
            $id,
            ['sh', '-c', self::PROBE, 'sh', $path, self::CONTAINER_PHAR],
            timeout: self::TIMEOUT_SECONDS,
        );
        if ($probe['exit_code'] !== 0) {
            return null;
        }
        $lines = explode("\n", rtrim($probe['stdout'], "\n"));
        $line = static fn (int $at): string => trim($lines[$at] ?? '', " \t");

        return ['path' => $line(0), 'owner' => $line(1), 'have' => $line(2), 'client' => $line(3), 'tree' => $line(4)];
    }

    private function copyPhar(string $id): void
    {
        try {
            $this->dind->accountDaemon()->putEngineFile($id, self::ENGINE_FILE, self::CONTAINER_PHAR);
        } catch (\Throwable) {
            throw new \RuntimeException('Could not copy WP-CLI into the container: its /tmp is not writable.');
        }
    }

    /**
     * @param  list<string>  $rest
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function wpCli(string $id, string $path, string $owner, array $rest): array
    {
        $allowRoot = explode(':', $owner, 2)[0] === '0' ? ['--allow-root'] : [];
        $result = $this->dind->accountDaemon()->exec($id, [
            'php', '-d', 'memory_limit='.self::MEMORY_LIMIT, ...WpCli::PHP_FLAGS, self::CONTAINER_PHAR, '--path='.$path,
            '--exec='.MustUsePlugin::MEND_ON_LOAD, ...$allowRoot, ...$rest,
        ], $owner, $path, self::ENVIRONMENT, self::TIMEOUT_SECONDS);

        return ['stdout' => $result['stdout'], 'stderr' => $result['stderr'], 'exit_code' => $result['exit_code']];
    }

    private static function nothingFound(string $path, string $service, bool $withoutClient): \RuntimeException
    {
        $where = $service === '' ? '' : " in service {$service}";
        if ($withoutClient) {
            return new DatabaseClientMissing("WP-CLI's database commands need the MySQL client (mysql, mysqldump and mysqlcheck), and no running container{$where} that has WordPress has one. Run them in a service whose image has the client, such as wordpress:cli with the same volumes.");
        }
        if ($path !== '') {
            return new WordPressNotFound("No WordPress with PHP was found at {$path}{$where}.");
        }
        if ($service !== '') {
            return new WordPressNotFound("No WordPress with PHP was found{$where}. Pass path with the WordPress directory as the container sees it.");
        }

        return new WordPressNotFound("No WordPress install was found in this project's containers. Pass path with the WordPress directory as the container sees it.");
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
