<?php

namespace App\System\Privileged;

use App\Lib\Deploy\Telemetry\CheckoutFacts;

/** The broker's `account` actions. The broker checks the account against the host's passwd and becomes it. */
final class AccountCommands
{
    /** The read-only git subcommands {@see CheckoutFacts} runs, and the only options they take. */
    private const GIT_SUBCOMMANDS = ['rev-parse', 'config', 'log'];

    private const GIT_OPTIONS = ['--is-inside-work-tree', '--is-shallow-repository', '--abbrev-ref', '--get', '-1'];

    private const GIT_FORMAT = '--format=';

    private const CERTIFICATE_SUBJECT = '~\A/[^\x00-\x1f\x7f]*\z~';

    /** @return list<string> */
    public function listZipNames(string $archive): array
    {
        return $this->asRoot(__FUNCTION__, [Arguments::path($archive)]);
    }

    /** @return list<string> */
    public function listZipEntries(string $archive): array
    {
        return $this->asRoot(__FUNCTION__, [Arguments::path($archive)]);
    }

    /** @return list<string> */
    public function readZipMember(string $archive, string $name): array
    {
        return $this->asRoot(__FUNCTION__, [Arguments::path($archive), Arguments::operand($name)]);
    }

    /**
     * Refused when a directory on the way is a link or leads out of the home.
     *
     * @return list<string>
     */
    public function renameInHome(string $homesDir, string $staged, string $target): array
    {
        return $this->asRoot(__FUNCTION__, Arguments::paths([$homesDir, $staged, $target]));
    }

    /** @return list<string> */
    public function readInHome(string $home, string $path): array
    {
        return $this->asRoot(__FUNCTION__, Arguments::paths([$home, $path]));
    }

    /**
     * The caller appends the mode, the path and the confining root.
     *
     * @return list<string>
     */
    public function fileStream(int $uid, int $gid): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, []);
    }

    /**
     * The broker overrides the config settings a read could use to run a program.
     *
     * @param  array<int, string>  $arguments  the subcommand and its arguments
     * @return list<string>
     */
    public function gitOnAccountCheckout(int $uid, int $gid, string $checkout, array $arguments): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [Arguments::path($checkout), ...$this->gitRead(array_values($arguments))]);
    }

    /**
     * As the account in the host's namespaces, so git hooks run as the tenant.
     *
     * @param  list<string>  $command  `env` with git's environment, then `git` and its arguments
     * @return list<string>
     */
    public function gitOnHost(string $username, array $command): array
    {
        return Root::action('account', __FUNCTION__, [Arguments::username($username), ...$this->program($command)], true);
    }

    /**
     * Copies as the account, a link as a link.
     *
     * @return list<string>
     */
    public function copyReplacingAsAccount(int $uid, int $gid, string $from, string $to): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, Arguments::paths([$from, $to]));
    }

    /** @return list<string> */
    public function extractZip(int $uid, int $gid, string $staged, string $into): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, Arguments::paths([$staged, $into]));
    }

    /** @return list<string> */
    public function extractTar(int $uid, int $gid, string $staged, string $into): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, Arguments::paths([$staged, $into]));
    }

    /** @return list<string> */
    public function normaliseModes(int $uid, int $gid, string $dir): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [Arguments::path($dir)]);
    }

    /**
     * A FIFO or a device is refused.
     *
     * @return list<string>
     */
    public function readRegularFile(int $uid, int $gid, string $path): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [Arguments::path($path)]);
    }

    /**
     * A gzipped tar from stdin, restoring no owner.
     *
     * @return list<string>
     */
    public function extractTarFromStdin(int $uid, int $gid, string $dir): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [Arguments::path($dir)]);
    }

    /**
     * A directory already in $into keeps its owner and mode.
     *
     * @param  array<int, string>  $entries  names directly in $from
     * @return list<string>
     */
    public function mergeTree(int $uid, int $gid, string $from, string $into, array $entries): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [
            Arguments::path($from), Arguments::path($into), ...array_map(self::entryName(...), array_values($entries)),
        ]);
    }

    /**
     * Writes stdin to $home/$directory/$name mode 0644, refusing links and creating missing directories with $mode.
     *
     * @return list<string>
     */
    public function placeFile(int $uid, int $gid, string $home, string $directory, string $mode, string $name): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [Arguments::path($home), self::relative($directory), self::octalMode($mode), self::entryName($name)]);
    }

    /**
     * Follows no link. A missing directory is not an error.
     *
     * @return list<string>
     */
    public function removeFileBelow(int $uid, int $gid, string $home, string $directory, string $name): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [Arguments::path($home), self::relative($directory), self::entryName($name)]);
    }

    /** @return list<string> */
    public function ensureDirectoryBelow(int $uid, int $gid, string $home, string $directory, string $mode): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [Arguments::path($home), self::relative($directory), self::octalMode($mode)]);
    }

    /**
     * Only while $path is a regular file with one link.
     *
     * @return list<string>
     */
    public function lastLinesOfLog(int $uid, int $gid, string $path, int $lines): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [Arguments::path($path), HostValues::count($lines, 'line count')]);
    }

    /**
     * Unlinks a link and replaces a file someone else owns with an account-owned copy.
     * A non-regular file is left alone.
     *
     * @return list<string>
     */
    public function ensureLogFile(int $uid, int $gid, string $path, string $owner): array
    {
        return $this->onAccount(__FUNCTION__, $uid, $gid, [Arguments::path($path), Arguments::owner($owner)]);
    }

    /** @return list<string> */
    public function selfSignedCertificate(string $keyFile, string $certFile, string $subject, string $domain): array
    {
        return $this->asRoot(__FUNCTION__, [
            Arguments::path($keyFile),
            Arguments::path($certFile),
            $this->certificateSubject($subject),
            Arguments::hostname($domain, wildcard: true),
        ]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function onAccount(string $action, int $uid, int $gid, array $values): array
    {
        return Root::action('account', $action, [$this->id($uid), $this->id($gid), ...$values]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function asRoot(string $action, array $values): array
    {
        return Root::action('account', $action, $values);
    }

    private function id(int $id): string
    {
        if ($id < 0) {
            throw InvalidArgument::of('uid or gid', (string) $id);
        }

        return (string) $id;
    }

    /**
     * @param  array<int, string>  $command
     * @return list<string>
     */
    private function program(array $command): array
    {
        if ($command === []) {
            throw InvalidArgument::of('command', '');
        }
        Arguments::operand($command[0]);

        return array_values($command);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function gitRead(array $arguments): array
    {
        $subcommand = $arguments[0] ?? '';
        if (! in_array($subcommand, self::GIT_SUBCOMMANDS, true) || ($subcommand === 'config' && ($arguments[1] ?? null) !== '--get')) {
            throw InvalidArgument::of('git subcommand', implode(' ', $arguments));
        }
        foreach (array_slice($arguments, 1) as $argument) {
            $this->gitArgument($argument);
        }

        return $arguments;
    }

    private function gitArgument(string $argument): void
    {
        if (in_array($argument, self::GIT_OPTIONS, true) || $this->isGitFormat($argument)) {
            return;
        }
        Arguments::operand($argument);
    }

    /** `%G` verifies a signature, which runs the program the checkout's config names. */
    private function isGitFormat(string $argument): bool
    {
        return str_starts_with($argument, self::GIT_FORMAT) && ! str_contains($argument, '%G');
    }

    private function certificateSubject(string $subject): string
    {
        if (preg_match(self::CERTIFICATE_SUBJECT, $subject) !== 1) {
            throw InvalidArgument::of('certificate subject', $subject);
        }

        return $subject;
    }

    private static function relative(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('~[\x00\n\r]~', $path) === 1 || in_array('..', explode('/', $path), true)) {
            throw InvalidArgument::of('relative path', $path);
        }

        return $path;
    }

    private static function entryName(string $name): string
    {
        if (in_array($name, ['', '.', '..'], true) || str_contains($name, '/') || preg_match('~[\x00\n\r]~', $name) === 1) {
            throw InvalidArgument::of('name', $name);
        }

        return $name;
    }

    private static function octalMode(string $mode): string
    {
        if (preg_match('/\A[0-7]{3,4}\z/', $mode) !== 1) {
            throw InvalidArgument::of('mode', $mode);
        }

        return $mode;
    }
}
