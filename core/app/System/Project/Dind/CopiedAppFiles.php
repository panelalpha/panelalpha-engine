<?php

namespace App\System\Project\Dind;

use App\Models\User;
use App\System\Project\Dind as DindProject;

/**
 * Files an application wrote with the source's database login (Matomo's config/config.ini.php) arrive in a
 * copy as they were, so the copy would sign in to the source's database. Each checkout file holding the
 * source's database password gets this project's login and address instead, rewritten as the account.
 */
final class CopiedAppFiles
{
    private const TIMEOUT_SECONDS = 300;

    /**
     * $1 the checkout, $2 the source's password, then sed arguments. Regular files only, never through a
     * link, below 1 MiB, outside .git and node_modules; each file changed is printed relative to the checkout.
     */
    private const REWRITE = 'root=$(cd -- "$1" 2>/dev/null && pwd -P) || exit 0; needle=$2; shift 2; '
        .'find "$root" \( -name .git -o -name node_modules \) -prune -o -type f -size -1025k -print '
        .'| while IFS= read -r f; do grep -qF -e "$needle" -- "$f" 2>/dev/null || continue; '
        .'sed -i "$@" -- "$f" && printf "%s\n" "${f#"$root"/}"; done';

    public function __construct(private DindProject $project) {}

    /**
     * @return list<string> the files rewritten, relative to the checkout
     */
    public function repointFrom(User $source): array
    {
        $from = AppDatabase::credentialsOf($source);
        $to = AppDatabase::credentialsOf($this->project->userModel());
        if ($from === null || $to === null || $from['password'] === '') {
            return [];
        }
        $arguments = self::sedArguments(self::replacements($from, $to, $this->hosts($source)));
        if ($arguments === []) {
            return [];
        }

        $logger = $this->project->shell()->logger();
        try {
            $output = $this->project->shell()->execAsUserQuiet(self::command(rtrim($this->project->userAppDirPath(), '/'), $from['password'], $arguments), [], self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            $logger?->warn('Could not point the copied application files at this project\'s database: '.trim($e->getMessage()));

            return [];
        }

        $rewritten = array_values(array_filter(array_map('trim', explode("\n", $output))));
        if ($rewritten !== []) {
            $logger?->info('Pointed the copied application files at this project\'s database: '.implode(', ', $rewritten));
        }

        return $rewritten;
    }

    /**
     * @param  list<string>  $sedArguments
     * @return list<string>
     */
    public static function command(string $projectDir, string $needle, array $sedArguments): array
    {
        return ['sh', '-c', self::REWRITE, 'sh', $projectDir, $needle, ...$sedArguments];
    }

    /**
     * The source's password, database and user names, and host, against this project's; a value equal on both
     * sides is left out.
     *
     * @param  array{database: string, username: string, password: string}  $from
     * @param  array{database: string, username: string, password: string}  $to
     * @param  array{0: string, 1: string}|null  $hosts
     * @return array<string, string>
     */
    public static function replacements(array $from, array $to, ?array $hosts): array
    {
        $pairs = [
            $from['password'] => $to['password'],
            $from['username'] => $to['username'],
            $from['database'] => $to['database'],
        ];
        if ($hosts !== null) {
            $pairs[$hosts[0]] = $hosts[1];
        }

        return array_filter($pairs, static fn (string $value, int|string $key): bool => (string) $key !== '' && (string) $key !== $value, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * One literal sed substitution per pair, in order.
     *
     * @param  array<string, string>  $replacements
     * @return list<string>
     */
    public static function sedArguments(array $replacements): array
    {
        $arguments = [];
        foreach ($replacements as $from => $to) {
            array_push($arguments, '-e', 's/'.self::literalPattern((string) $from).'/'.self::literalReplacement($to).'/g');
        }

        return $arguments;
    }

    /** A basic regular expression matching $value and nothing else. */
    private static function literalPattern(string $value): string
    {
        return (string) preg_replace('~[\\\\/.*\[^$]~', '\\\\$0', $value);
    }

    private static function literalReplacement(string $value): string
    {
        return (string) preg_replace('~[\\\\/&]~', '\\\\$0', $value);
    }

    /** @return array{0: string, 1: string}|null the source's public host and this project's */
    private function hosts(User $source): ?array
    {
        $from = (string) parse_url((string) Networking::publicUrlOf($source), PHP_URL_HOST);
        $to = (string) parse_url((string) Networking::publicUrlOf($this->project->userModel()), PHP_URL_HOST);

        return $from !== '' && $to !== '' && $from !== $to ? [$from, $to] : null;
    }
}
