<?php

namespace App\Lib\Deploy\DeployLog;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\Dind\RegistryAuth;
use App\Lib\Findings\Finding;
use App\Lib\Findings\FindingException;
use App\System\Privileged;
use App\System\Project\Dind\SysboxDaemons;

/**
 * Turns a failed build's BuildKit output into one sentence naming the cause, or null when nothing matched. Rule slugs are
 * identifiers telemetry reports, so renaming one splits that failure's history on the receiving end.
 */
class DeployFailureExplainer
{
    /** Leads the failure of a redeploy whose new version the switch refused for an empty page. */
    public const EMPTY_NEW_VERSION = 'The new version answers with an empty page';

    /** Leads the failure of a deploy whose submodules could not be fetched. */
    public const SUBMODULE_FAILURE = "The repository's submodules could not be fetched";

    /** The id of the last-resort rule below, which can only say a build step failed, not why. */
    public const BUILD_STEP_FAILED = 'build-step-failed';

    /** What a failure is called when no rule explains it. */
    public const DEPLOY_FAILED = 'deploy_failed';

    public const APP_DID_NOT_START = 'app_did_not_start';

    public const REBUILD_FAILED = 'rebuild_failed';

    public const ARCHIVE_REFUSED = 'archive_refused';

    private const REGISTRY_ERROR = '/(?:manifest unknown|manifest for \S+ not found|pull access denied'
        .'|failed to resolve source metadata|failed to do request|failed to fetch anonymous token'
        .'|failed to resolve reference (?:"([^"\n]+)"|(\S+)))/i';

    private const REGISTRY_REFUSAL = '/unauthorized|authentication required|authorization failed'
        .'|no basic auth credentials|denied: requested access|\b40[13]\b/i';

    /** Go's resolver failing a lookup; `no such host` is an answer, a name that does not exist, and is left out. */
    private const DNS_LOOKUP_FAILURE = '/\blookup ([A-Za-z0-9][A-Za-z0-9.-]*) on (\[[0-9A-Fa-f:]+\]|\d{1,3}(?:\.\d{1,3}){3}):53: (?!no such host)([^\n]+)/';

    private const DATABASE_AUTH_FAILURE = '/(password authentication failed for user|Access denied for user .{0,40}using password'
        .'|authentication failed.{0,40}MongoServerError)/i';

    private const DATABASE_KEEPS_OLD_PASSWORD = 'The database was created by an earlier deploy and still expects the old password — '
        .'delete the application\'s database volume, or recreate the application, and deploy again.';

    public static function explain(string $output): ?string
    {
        $match = self::match($output);

        return $match === null ? null : $match['message'];
    }

    /**
     * The matching rule and the sentence it produced.
     * A rule's builder gets the whole output too, for context beyond its own match (base-image-unavailable).
     *
     * @return ?array{rule: string, message: string}
     */
    public static function match(string $output): ?array
    {
        if (trim($output) === '') {
            return null;
        }

        foreach (self::rules() as $rule => [$pattern, $build]) {
            if (preg_match($pattern, $output, $m) === 1) {
                $sentence = $build($m, $output);
                if ($sentence !== null) {
                    return ['rule' => $rule, 'message' => $sentence];
                }
            }
        }

        return null;
    }

    /**
     * Every rule slug, in match order.
     *
     * @return list<string>
     */
    public static function ruleIds(): array
    {
        return array_keys(self::rules());
    }

    /**
     * Every code a failed deploy can carry that is not a refusal's own.
     *
     * @return list<string>
     */
    public static function failureCodes(): array
    {
        return [...self::ruleIds(), self::DEPLOY_FAILED, self::APP_DID_NOT_START, self::REBUILD_FAILED, self::ARCHIVE_REFUSED, DeployLogger::INTERRUPTED];
    }

    /**
     * A failure as a finding: an exception that knows its finding keeps it, anything else is named by the rule that
     * explained it, else by $fallback.
     *
     * @param  ?array{rule: string, message: string}  $match
     */
    public static function failure(\Throwable|string $cause, ?array $match, string $fallback, string $sentence): Finding
    {
        if ($cause instanceof FindingException) {
            return $cause->finding();
        }

        return new Finding($match['rule'] ?? $fallback, $sentence);
    }

    /** The way out when what a dependency answered is a refused database login. */
    private static function databaseAuthAdvice(string $quoted): string
    {
        return preg_match(self::DATABASE_AUTH_FAILURE, $quoted) === 1 ? ' '.self::DATABASE_KEEPS_OLD_PASSWORD : '';
    }

    /** A quoted line, cut to a length a failure message can carry. */
    private static function clip(string $line): string
    {
        return mb_strlen($line) > 320 ? rtrim(mb_substr($line, 0, 320)).'…' : $line;
    }

    /** A quote ends with a full stop unless it already ends a sentence or was clipped. */
    private static function sentence(string $quote): string
    {
        return preg_match('/[.!?…]$/u', $quote) === 1 ? $quote : $quote.'.';
    }

    /** The line git explained itself in, or the first one when it did not. */
    private static function gitFailureLine(string $output, string $first): string
    {
        return preg_match('/^\s*(?:fatal|error): ([^\n]+)/m', $output, $m) === 1 ? trim($m[1]) : trim($first);
    }

    private static function duration(int $seconds): string
    {
        return $seconds >= 120 && $seconds % 60 === 0 ? intdiv($seconds, 60).' minutes' : "{$seconds} seconds";
    }

    /**
     * The message a panicking Cargo build script gave, in the current
     * (`panicked at file:l:c:` then the message) or pre-1.73 form.
     */
    private static function buildScriptPanic(string $output): ?string
    {
        if (preg_match('/panicked at [^\n]*?:\d+:\d+:[ \t]*\R(?:#\d+[ \t]+)?(?:\d+\.\d+[ \t]+)?([^\n]*\S)/', $output, $p) === 1
            || preg_match("/panicked at '([^'\n]+)', \\S+:\\d+:\\d+/", $output, $p) === 1) {
            return trim($p[1]);
        }

        return null;
    }

    /**
     * Whether $output shows compose building $ref itself: it pulls a tag a sibling builds first, and that pull's not-found is benign.
     * BuildKit's `naming to <ref>` for the same repository marks a tag made here; Compose's `Image <ref> Building`, one whose build failed.
     */
    private static function tagBuiltLocally(string $output, string $ref): bool
    {
        $ref = trim($ref, "\"' ");
        $repo = self::bareRepositoryName($ref);
        if ($repo === '') {
            return false;
        }

        $quoted = preg_quote($repo, '/');

        return preg_match('/naming to \S*'.$quoted.'\b/i', $output) === 1
            || preg_match('/^\s*(?:Image\s+)?(?:\S*\/)?'.$quoted.'(?::\S+)?\s+Building\b/im', $output) === 1;
    }

    /** $ref without its registry, namespace, tag or digest. */
    private static function bareRepositoryName(string $ref): string
    {
        $repo = (string) preg_replace('#^(?:[^/\s]+/)*#', '', $ref);

        return preg_split('/[:@\s]/', $repo)[0] ?? '';
    }

    /**
     * The sentence for an image a registry refused without a login, from the first line that is a registry's answer and not
     * another 401 (an npm or git one during the build); null when there is none.
     */
    private static function registryRefusal(string $output): ?string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match(self::REGISTRY_REFUSAL, $line) !== 1
                || preg_match('#error from registry|failed to authorize|failed to resolve (?:source metadata|reference)'
                    .'|failed to pull OCI resource|pull access denied|Error response from daemon|/v2/\S+/manifests/#i', $line) !== 1) {
                continue;
            }
            $image = self::refusedImage($line);
            if (preg_match('/pull access denied/i', $line) === 1
                && ($image === null || RegistryAuth::registryFor($image) === 'docker.io')) {
                continue;
            }
            $named = $image === null ? 'An image this project uses' : "The image {$image}";

            return "{$named} could not be downloaded: its registry refused access, so it is private or needs a "
                .'login this project does not have. Give the project one with the registry-auth setting, one '
                ."'host username token' line per registry; if one is set, its token is wrong or cannot read this image.";
        }

        return null;
    }

    private static function refusedImage(string $line): ?string
    {
        foreach ([
            '/failed to pull OCI resource "([^"]+)"/i',
            '/failed to resolve source metadata for (\S+?):\s/i',
            '/failed to resolve reference "([^"]+)"/i',
            '/\bImage (\S+) Error\b/',
        ] as $pattern) {
            if (preg_match($pattern, $line, $m) === 1) {
                return (string) preg_replace('#^docker\.io/(?:library/)?#', '', $m[1]);
            }
        }
        if (preg_match('#https?://([^/\s"]+)/v2/(\S+?)/manifests/([^\s"]+)#', $line, $m) === 1) {
            $host = in_array($m[1], ['registry-1.docker.io', 'index.docker.io'], true) ? '' : $m[1].'/';
            $path = $host === '' ? (string) preg_replace('#^library/#', '', $m[2]) : $m[2];
            $ref = str_starts_with($m[3], 'sha256:') ? '@'.$m[3] : ':'.$m[3];

            return $host.$path.$ref;
        }

        return null;
    }

    /**
     * Which of the three a failed base-image pull was, from the daemon's own words, naming the image. Null when every
     * registry error in $output is the benign pull of a tag this same log builds; a later line may name the image an earlier one did not.
     */
    private static function baseImageFailure(string $output): ?string
    {
        $found = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match(self::REGISTRY_ERROR, $line) !== 1) {
                continue;
            }
            $ref = self::registryErrorRef($line) ?: self::tokenScopeImage($line, $output);
            if ($ref !== '' && self::tagBuiltLocally($output, $ref)) {
                continue;
            }
            $cause = self::registryErrorCause($line);
            if (($found[$cause] ?? '') === '') {
                $found[$cause] = preg_replace('#^docker\.io/(?:library/)?#', '', $ref);
            }
        }
        if ($found === []) {
            return null;
        }

        foreach (['missing', 'missing-or-private', 'private', 'unreachable', 'unknown'] as $cause) {
            if (! array_key_exists($cause, $found)) {
                continue;
            }

            return self::baseImageMessage($cause, $found[$cause]);
        }

        return null;
    }

    /** The image a registry error line names, or ''; `failed to solve:` and BuildKit's `#N ERROR:` put a word there instead. */
    private static function registryErrorRef(string $line): string
    {
        $ref = '';
        foreach ([
            '/failed to resolve source metadata for (\S+?):\s/i',
            '/failed to resolve reference "?([^"\s]+)"?/i',
            '/manifest for (\S+) not found/i',
            '/pull access denied for ([^\s,]+)/i',
            '/(\S+): failed to do request/i',
        ] as $pattern) {
            if (preg_match($pattern, $line, $r) === 1 && ! in_array(strtolower($r[1]), ['solve', 'error'], true)) {
                $ref = $r[1];
                break;
            }
        }

        return $ref;
    }

    /**
     * The image whose registry token BuildKit was fetching, from the token URL's scope, with the tag its
     * `load metadata for` line names when there is one; '' when the line has no token URL.
     */
    private static function tokenScopeImage(string $line, string $output): string
    {
        if (preg_match('/token\/?\?scope=repository(?:%3A|:)([^&"\s]+?)(?:%3A|:)pull&service=([^&"\s]+)/i', $line, $m) !== 1) {
            return '';
        }
        $host = strtolower(rawurldecode($m[2]));
        $image = ($host === 'registry.docker.io' ? 'docker.io' : $host).'/'.rawurldecode($m[1]);
        $tagged = '/load metadata for ('.preg_quote($image, '/').'[:@][^\s:]+(?::[^\s:]+)?)/';

        return preg_match($tagged, $output, $t) === 1 ? $t[1] : $image;
    }

    /** Which kind of failure a registry error line reports, in the daemon's own words. */
    private static function registryErrorCause(string $line): string
    {
        return match (true) {
            preg_match('/:\s*not found\b|manifest unknown|manifest for \S+ not found|name unknown/i', $line) === 1 => 'missing',
            preg_match('/repository does not exist or may require/i', $line) === 1 => 'missing-or-private',
            preg_match('/unauthorized|denied|authentication required|\b40[13]\b/i', $line) === 1 => 'private',
            preg_match('/failed to do request|dial tcp|no such host|i\/o timeout|connection refused'
                .'|TLS handshake|deadline exceeded|server misbehaving/i', $line) === 1 => 'unreachable',
            default => 'unknown',
        };
    }

    /** The sentence for one kind of base-image failure, naming $ref when the daemon did. */
    private static function baseImageMessage(string $cause, ?string $ref): string
    {
        $image = $ref === '' ? 'A base image this project asks for' : "The base image {$ref}";

        return match ($cause) {
            'missing' => $ref !== '' && BuiltImage::isOurs($ref)
                ? self::prunedBaseImage($ref)
                : "{$image} could not be downloaded: its registry says it does not exist. That tag or "
                    .'repository was never published or has been removed, so the project has to name one that exists.',
            'missing-or-private' => "{$image} could not be downloaded: Docker Hub refused it, which it does both for a "
                .'repository that does not exist and for a private one. Check the name the project uses; a private '
                .'image cannot be pulled from here.',
            'private' => "{$image} could not be downloaded: its registry refused access, so it is private or needs "
                .'credentials this server does not have.',
            'unreachable' => "{$image} could not be downloaded: its registry could not be reached from this server. "
                .'A registry that only exists on the project\'s own network or CI cannot be used here.',
            default => "{$image} could not be downloaded — it may not exist, may be private, or its registry may be "
                .'unreachable from here.',
        };
    }

    /**
     * A base image we build that is missing from the host, with no rebuild behind it. docker.io never had that tag, so
     * "never published" would send the reader to the wrong name.
     */
    private static function prunedBaseImage(string $ref): string
    {
        return "The base image {$ref} is one this engine builds and is no longer on this server, so the host build "
            .'had nothing to run. Have the engine build it again ahead of deploys (prewarm it), then deploy again.';
    }

    /** A base image we build that the host no longer held, and that this deploy could not rebuild. */
    private static function rebuiltBaseImage(string $ref, ?string $reason = null): string
    {
        $sentence = "The base image {$ref} is not on this server and is one this engine builds; rebuilding it did "
            .'not work, so the host build had nothing to run.';
        $reason = trim((string) $reason);

        return $reason === ''
            ? $sentence.' Deploy again to rebuild it, or prewarm it so it is built ahead of a deploy.'
            : $sentence.' The build said: '.self::clip($reason);
    }

    /**
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function rules(): array
    {
        return [
            ...self::engineMarkerRules(),
            ...self::sysboxRules(),
            ...self::toolchainRules(),
            ...self::composerRules(),
            ...self::nodeEngineRules(),
            ...self::resourceRules(),
            ...self::heapRules(),
            ...self::outOfMemoryRules(),
            ...self::javaPluginRules(),
            ...self::rustRules(),
            ...self::registryRules(),
            ...self::containerStartRules(),
            ...self::buildDaemonRules(),
            ...self::baseImageRules(),
            ...self::packageDownloadRules(),
            ...self::nodeBuildRules(),
            ...self::goRules(),
            ...self::configurationRules(),
            ...self::projectSetupRules(),
            ...self::gitBinaryRules(),
            ...self::installRules(),
            ...self::repositoryRules(),
            ...self::buildArgGuardRules(),
            ...self::lastResortRules(),
        ];
    }

    /**
     * What the engine words itself (watchdog stops, timeouts, submodules, dependencies, the switch gate, whose sentence passes
     * as is). First, because each quotes a command line or another program's output that later rules would otherwise claim.
     *
     * @see StepWatchdog
     * @see DependencyFailure
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function engineMarkerRules(): array
    {
        return [
            'host-namespaces-unavailable' => [
                Privileged::HOST_NAMESPACES_UNAVAILABLE,
                static fn (): string => "The engine could not enter the server's namespaces, so no command reached the host. "
                        .'This is a problem on the server, not in the project: the engine\'s root-broker container has to '
                        .'run privileged with `pid: host`, on a host that permits it (no rootless Docker, no sysbox, '
                        .'no unprivileged LXC). Redeploying will not help until that is fixed.',
            ],

            'disk-limit-reached' => [
                '/'.preg_quote(StepWatchdog::DISK_MARKER, '/').' \((.*)\)/',
                static fn (array $m): string => "The deploy was stopped because {$m[1]}, and the account's unused Docker storage was cleared. "
                        .(str_starts_with($m[1], 'the engine host')
                            ? 'Free disk on the host and deploy again; pruning the base images no project has used recently frees the most.'
                            : 'Raise the project\'s disk limit, or make what the deploy downloads and builds smaller.'),
            ],

            'build-stalled' => [
                '/'.preg_quote(StepWatchdog::MARKER, '/').': "(.*?)" printed nothing for (\d+)s\. Last output: ([^\n]*)/',
                static fn (array $m): string => "The build step \"{$m[1]}\" printed nothing for ".self::duration((int) $m[2])
                        ." and was stopped. Last output: {$m[3]}. It was most likely stuck on a download "
                        .'or network call; deploy again, and if it stalls at the same point, check that step.',
            ],

            'clone-timed-out' => [
                "/The process \"[^\n]*'clone'[^\n]*\" exceeded the timeout of (\\d+) seconds/",
                static fn (array $m): string => 'The repository did not finish cloning within '.self::duration((int) $m[1])
                        .' and the clone was stopped. It is most likely very large, or the link to its git '
                        .'host is slow. Deploy a smaller branch or an archive of the code, or ask the server '
                        .'administrator to raise DEPLOY_CLONE_TIMEOUT.',
            ],

            'submodule-fetch-failed' => [
                '/'.preg_quote(self::SUBMODULE_FAILURE, '/').': ([^\n]*)/',
                static fn (array $m, string $output): string => "The repository's submodules could not be fetched: ".self::clip(self::gitFailureLine($output, $m[1]))
                        .'. A private submodule needs a git token that can read it, and the commit the checkout '
                        .'records must still exist in its repository. A submodule addressed over SSH (git@host:...) '
                        .'is read over HTTPS unless the project has a deploy key, so a private one needs a token for '
                        .'that host or a deploy key that can read it. When the application needs only some of '
                        .'them, name those under `git: {submodules: [...]}` in the app config.',
            ],

            'build-timed-out' => [
                "/The process \"[^\n]*'up'[^\n]*'--build'[^\n]*\" exceeded the timeout of (\\d+) seconds/",
                static fn (array $m): string => 'Building and starting the application did not finish within '.self::duration((int) $m[1])
                        .' and was stopped. The build output is in the deploy log; a build that runs this long '
                        .'is usually waiting on a package registry or download that does not answer.',
            ],

            'step-timed-out' => [
                '/The process "[^\n]*" exceeded the timeout of (\d+) seconds/',
                static fn (array $m): string => 'A deploy step did not finish within '.self::duration((int) $m[1])
                        .' and was stopped. The full output is in the deploy log.',
            ],

            'dependency-failed' => [
                '/^'.preg_quote(DependencyFailure::PREFIX, '/').'(\S+) did not start \(([^)]*)\)(?: \[health check: ([^\]\n]*)\])?(?:: ([^\n]*))?/m',
                static function (array $m): string {
                    $probe = trim($m[3] ?? '');
                    $said = trim($m[4] ?? '');
                    $sentence = "The service {$m[1]} did not start ({$m[2]}), so the application was not started either.";
                    if ($probe !== '') {
                        $sentence .= ' Its health check said: '.self::sentence(self::clip($probe));
                    }
                    if ($said !== '') {
                        $sentence .= ' It printed: '.self::sentence(self::clip($said));
                    }

                    return $sentence.self::databaseAuthAdvice($probe.' '.$said).' The full output is in the deploy log.';
                },
            ],

            'new-version-empty-page' => [
                '/'.preg_quote(self::EMPTY_NEW_VERSION, '/').'[^\n]*/',
                static fn (array $m): string => $m[0],
            ],
        ];
    }

    /**
     * The container runtime under every account, ahead of every project rule, since a runc start failure is all its step
     * printed. ENOSYS on a mount is a seccomp trap sysbox-fs did not serve; only runtime and BuildKit mount lines count.
     *
     * @see SysboxDaemons
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function sysboxRules(): array
    {
        return [
            'sysbox-not-running' => [
                '/'.preg_quote(SysboxDaemons::NOT_RUNNING, '/').' \(([^)\n]+)\)'
                    .'|failed to get (?:config from sysbox-fs|sysbox-fs mountpoint)'
                    .'|dial unix \/run\/sysbox\/sysfs\.sock: connect: no such file or directory/',
                static fn (array $m): string => 'The sysbox runtime is not running on the engine host ('
                        .(($m[1] ?? '') !== '' ? $m[1] : 'sysbox-fs does not answer').'), so no project container can '
                        .'start or build. This is a problem on the server, not in the project: restart sysbox on the '
                        .'host (`systemctl restart sysbox`), then deploy again.',
            ],

            'sysbox-fs-connection-lost' => [
                '/(?:error mounting|can\'t mask|failed to mount)\b[^\n]*?\bfunction not implemented/',
                static fn (): string => 'The project\'s container lost its connection to sysbox-fs: a mount inside it failed '
                        .'with "function not implemented", which is what it gets once sysbox-fs crashed or was restarted '
                        .'while the container ran. This is a problem on the server, not in the project, and a redeploy '
                        .'alone does not fix it: restart sysbox on the engine host (`systemctl restart sysbox`), then '
                        .'recreate the project\'s container and deploy it again.',
            ],
        ];
    }

    /**
     * Toolchains older than the project declares, or missing a tool: `javac`'s own line is matched, not the plugin goal relaying
     * it, and a missing tool is named, as "does not compile: `quote`" would send the reader to their own code.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function toolchainRules(): array
    {
        return [
            'go-toolchain-too-old' => [
                '/go\.mod requires go >= ([0-9.]+).*?running go ([0-9.]+)/is',
                static fn (array $m): string => "This project needs Go {$m[1]}, but it was built with Go {$m[2]}.",
            ],

            'java-release-too-new' => [
                '/error: release version ([0-9]+) not supported/i',
                static fn (array $m): string => "This project has to be compiled for Java {$m[1]}, and the build image ships an "
                        .'older JDK, whose compiler refuses it. The full build output is in the deploy log.',
            ],

            'java-project-build-failed' => [
                '/(?:\[ERROR\] Failed to execute goal[^\n]*(?:ProjectBuildingException|UnresolvableModelException)'
                    .'|Child module ([^\s]+) of [^\s]+ does not exist'
                    .'|Non-resolvable parent POM for ([^\s:]+))[^\n]*/i',
                static fn (array $m): string => 'Maven could not assemble this project: a module or parent POM it declares is '
                        .'absent'.(($m[1] ?? '') !== '' ? " ({$m[1]})" : (($m[2] ?? '') !== '' ? " ({$m[2]})" : '')).'. '
                        .'The full output is in the deploy log.',
            ],

            'dotnet-windows-only' => [
                '/error NETSDK1100:[^\n]*?\[(?:\/app\/)?([^\]\s]+\.(?:cs|fs|vb)proj)\]/',
                static fn (array $m): string => "{$m[1]} targets Windows (a net*-windows framework, as WinForms and WPF "
                        .'apps do), which the .NET SDK on Linux cannot build. A desktop app has no web server to run here; '
                        .'deploy a project that serves HTTP, such as an ASP.NET Core one.',
            ],

            'rust-build-tool-missing' => [
                '/Could not find `protoc`|Missing dependency: cmake|is `cmake` not installed'
                    ."|Unable to find libclang|collect2: fatal error: cannot find 'ld'/",
                static function (array $m, string $output = ''): string {
                    $tool = match (true) {
                        str_contains($m[0], 'protoc') => '`protoc` (the Protocol Buffers compiler)',
                        str_contains($m[0], 'cmake') => '`cmake`',
                        str_contains($m[0], 'libclang') => '`libclang` (for bindgen)',
                        preg_match('/-fuse-ld=([a-z]+)/', $output, $l) === 1 => "the `{$l[1]}` linker, which the project selects with `-fuse-ld={$l[1]}` in .cargo/config.toml,",
                        default => 'the linker the project selects in .cargo/config.toml',
                    };

                    return "The Rust build needs {$tool} and the build image does not have it. "
                        .'The full output is in the deploy log.';
                },
            ],

            'native-library-headers-missing' => [
                '/(The pkg-config command could not be found'
                    .'|Package \\S+ was not found in the pkg-config search path'
                    .'|Could not find \\S+ using pkg-config'
                    .'|make sure the \\S+ (?:and \\S+ )?development packages are installed)/i',
                static fn (): string => 'A dependency has to be compiled and needs development headers that the build '
                        .'image does not carry (pkg-config, or a library it queries). The full '
                        .'output is in the deploy log, naming the dependency.',
            ],
        ];
    }

    /**
     * Composer resolves against the runtime image, so a missing extension (`ext-x` to older Composers, `PHP extension ext-x` to 2) is
     * absent from it; a locked package's PHP need is not the project's. A package no source has outranks the conflict sharing its header.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function composerRules(): array
    {
        return [
            'php-version-mismatch' => [
                '/(?:-\s+(\S+)\s+(\S+)\s+)?requires php ([^\s,]+).*?your php version \(([^)]+)\)/is',
                static fn (array $m): string => ($m[1] ?? '') !== '' && $m[1] !== 'Root'
                    ? "The locked package {$m[1]} {$m[2]} needs PHP {$m[3]}, but the project was built with PHP {$m[4]}."
                    : "This project needs PHP {$m[3]}, but it was built with PHP {$m[4]}.",
            ],

            'php-extension-missing' => [
                '/requires (?:PHP extension )?ext-([a-z0-9_]+).*?it is missing from your system/is',
                static fn (array $m): string => "This project needs the PHP extension {$m[1]}, which is not in the PHP image it runs on.",
            ],

            'composer-package-not-found' => [
                '/requires ([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)[^,\n]*, it could not be found in any version/',
                static function (array $m, string $output = ''): string {
                    preg_match_all(
                        '/requires ([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)[^,\n]*, it could not be found in any version/',
                        $output !== '' ? $output : $m[0],
                        $all
                    );
                    $names = array_values(array_unique($all[1] ?: [$m[1]]));
                    $shown = implode(', ', array_slice($names, 0, 3))
                        .(count($names) > 3 ? ' and '.(count($names) - 3).' more' : '');

                    return 'Composer could not find '.(count($names) === 1 ? 'a package' : count($names).' packages')
                        ." this project requires ({$shown}) in any repository it was given. Such packages "
                        .'usually come from a repository the composer.json does not declare, or the '
                        .'repository is a library meant to be required by another project rather than '
                        .'an application. The full resolver output is in the deploy log.';
                },
            ],

            'composer-unresolvable' => [
                '/Your requirements could not be resolved to an installable set of packages/i',
                static fn (): string => 'This project\'s Composer dependencies cannot all be installed together.'
                    .' The full resolver output is in the deploy log.',
            ],
        ];
    }

    /**
     * Only npm's error form: `npm warn EBADENGINE` reports what some transitive dependency asked for, and the tempered
     * `(?!npm warn)` stops the scan at the next warning line.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function nodeEngineRules(): array
    {
        return [
            'node-engine-mismatch' => [
                '/npm (?:error|ERR!)[^\n]*Unsupported engine(?:(?!npm warn)[\s\S])*?'
                    .'required:\s*\{?\s*node:\s*\'?([^\'",}]+)/i',
                static fn (array $m): string => 'This project needs Node '.trim($m[1]).', which does not match the version used to build it.',
            ],
        ];
    }

    /**
     * Disk, and owners an account cannot hold: its user namespace maps ids 0-65535 only, so restoring a higher one fails with
     * EINVAL, which Flutter blames on the network.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function resourceRules(): array
    {
        return [
            'disk-full' => [
                '/(no space left on device|ENOSPC|errno=28)/i',
                static fn (): string => 'The build ran out of disk space. Free some space in the account or move to a larger plan.',
            ],

            'owner-id-out-of-range' => [
                '/(?:Cannot change ownership to uid (\d+), gid (\d+)|lchown ([^\n:]+)): invalid argument/i',
                static fn (array $m): string => (($m[1] ?? '') !== ''
                    ? "A file in the build is owned by uid {$m[1]}, gid {$m[2]}"
                    : 'A file in the build ('.trim($m[3]).') is owned by a user or group id').' '
                        .'that cannot exist in an account, which holds ids 0-65535 only. Extract archives '
                        .'without restoring their owner (tar --no-same-owner, or TAR_OPTIONS=--no-same-owner '
                        .'in the Dockerfile), or use an image whose files are owned by ids below 65536.',
            ],
        ];
    }

    /**
     * A JVM or V8 refusing to allocate inside the heap the engine sized, not the kernel killing a cgroup. Above out-of-memory, as one
     * Maven log can carry both, and above build-step-failed, as Maven ends on an unrelated successful reactor summary.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function heapRules(): array
    {
        return [
            'java-heap-space' => [
                '/(OutOfMemoryError:\s*Java heap space|java\.lang\.OutOfMemoryError'
                    .'|Java heap space\s*->|There is insufficient memory for the Java Runtime'
                    .'|Could not reserve enough space for \d+KB object heap)/i',
                static fn (): string => 'The Java build ran out of heap: the compiler was given less memory than this '
                        .'project needs. The build container had more to give — the heap is sized '
                        .'from it — so this is an engine limit, not the plan. The full build output '
                        .'is in the deploy log.',
            ],

            'node-heap-space' => [
                '/FATAL ERROR:\s*(?:Ineffective mark-compacts near heap limit'
                    .'|Reached heap limit|CALL_AND_RETRY_LAST Allocation failed)'
                    .'|JavaScript heap out of memory/',
                static fn (): string => 'The Node build ran out of heap: the build was given less memory than this '
                        .'project needs. The build container had more to give — the heap is sized '
                        .'from it — so this is an engine limit, not the plan. The full build output '
                        .'is in the deploy log.',
            ],
        ];
    }

    /**
     * The kernel's own forms only: npm prints `killed: false` on any failure, the cgroup writes `out of memory` bare. A lone `Killed`
     * is a host build, which prints no exit code and runs outside the account's cgroup (one inside it is held by InnerBuildGuard).
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function outOfMemoryRules(): array
    {
        return [
            'out-of-memory' => [
                '/(exit code: 137|signal:\s*killed|OOMKilled'
                    .'|(?:ResourceExhausted:|did not complete successfully:)[^\n]*cannot allocate memory'
                    .'|out of memory'
                    .'|(?:task|process)\s+"?[\w\/.-]+"?\s+killed'
                    .'|^[ \t]*(?:\[ERROR\][ \t]+)?Killed[ \t]*$'
                    .'|oom-kill)/im',
                static fn (array $m, string $output = ''): string => preg_match('/^\s*(?:\[ERROR\]\s+)?Killed\s*$/', $m[1]) === 1
                    || str_contains($output, 'host build container')
                    ? 'The build ran out of memory in the engine\'s build container, which is sized for '
                        .'the server (DEPLOY_BUILD_MEMORY, 8 GB or half its RAM by default), not by the plan. '
                        .'Raising the project\'s memory limit does not change it. The full build output is '
                        .'in the deploy log.'
                    : 'The build ran out of memory. It may use the project\'s memory limit or the server\'s build '
                        .'memory (DEPLOY_BUILD_MEMORY, 8 GB or half its RAM by default), whichever is lower.',
            ],
        ];
    }

    /**
     * Below out-of-memory: a Java build's frontend step usually failed by running the JVM out of memory, and only Maven's goal
     * failure reaches these.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function javaPluginRules(): array
    {
        return [
            'java-plugin-goal-failed' => [
                '/\[ERROR\] Failed to execute goal (com\.github\.eirslett|com\.diffplug\.spotless):[^\n]*/i',
                static fn (array $m): string => 'The Java build ran a frontend step that reported failures'
                        .' (the '.$m[1].' plugin). '
                        .'The full output is in the deploy log.',
            ],
            'java-license-check-failed' => [
                '/\[ERROR\] Failed to execute goal org\.apache\.rat:[^\n]*/i',
                static fn (): string => 'The Java build refused to continue over a missing license header (the Apache RAT '
                        .'plugin). The full output is in the deploy log.',
            ],
        ];
    }

    /**
     * A `-sys` crate's `CXX_... = None` is the image lacking a compiler, though it reads as the crate's fault. The generic
     * build-script rule takes what the specific ones leave: scripts also panic on missing assets or inputs.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function rustRules(): array
    {
        return [
            'native-build-interrupted' => [
                '/^(?:CXX?_[A-Za-z0-9_-]+ = None|CC_FORCE_DISABLE = None)$/m',
                static fn (): string => 'A dependency has to be compiled from source and the build container had no C or '
                        .'C++ compiler to do it. The full output is in the deploy log.',
            ],

            'rust-build-script-failed' => [
                '/error: failed to run custom build command for `([^`]+)`/',
                static function (array $m, string $output = ''): string {
                    $crate = preg_match('/\(\/[^)]*\)\s*$/', $m[1]) === 1
                        ? "The project's Rust crate `{$m[1]}`"
                        : "The Rust dependency `{$m[1]}`";
                    $after = (string) strstr($output, $m[0]);
                    $said = self::buildScriptPanic($after !== '' ? $after : $output);
                    $said = $said === null ? '' : ' It said: '.rtrim(self::clip($said), '.').'.';

                    return "{$crate} could not be built: its build script failed.{$said}"
                        .' The full output is in the deploy log.';
                },
            ],

            'rust-compile-failed' => [
                '/error: could not compile `([^`]+)`/',
                static fn (array $m): string => "The project's Rust code does not compile: `{$m[1]}`. The full output is in the "
                        .'deploy log.',
            ],
        ];
    }

    /**
     * Above base-image-unavailable, which would claim them: a truncated layer from a registry mirror that filled its disk, and an
     * uppercase first component, which the daemon reads as a registry host and fails to look up; and a registry lookup DNS
     * did not answer, above the entrypoint rule that would quote compose's "git" warning printed beside it.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function registryRules(): array
    {
        return [
            'registry-rate-limited' => [
                '/(toomanyrequests|429 Too Many Requests)/i',
                static fn (): string => 'Docker Hub temporarily refused further downloads because of its rate limit. Try again in a few minutes.',
            ],

            'layer-digest-mismatch' => [
                '/unexpected commit digest/i',
                static fn (): string => 'A downloaded image layer arrived corrupted — the host or its registry mirror '
                        .'delivered a damaged copy. This is a problem on the host, not in the project; '
                        .'retrying the deploy usually works.',
            ],

            'image-reference-invalid' => [
                '/(?:failed to resolve reference "([^"\n]+)"'
                    .'|invalid reference format: repository name \(([^)\n]+)\) must be lowercase)/i',
                static function (array $m): ?string {
                    if (($m[2] ?? '') !== '') {
                        return "The image name \"{$m[2]}\" is not valid: Docker image names must be lowercase.";
                    }
                    $host = self::uppercaseRegistryHost($m[1]);
                    if ($host === null) {
                        return null;
                    }

                    return "The image name \"{$m[1]}\" is not valid: Docker image names must be lowercase, "
                        ."so \"{$host}\" was read as the address of a registry, which does not exist. "
                        .'The file that names it has to say "'.self::lowercaseRepository($m[1]).'".';
                },
            ],

            'build-dns-unavailable' => [
                self::DNS_LOOKUP_FAILURE,
                static fn (array $m, string $output = ''): string => self::dnsFailure($m[1], $m[2], $m[3], $output),
            ],
        ];
    }

    /**
     * A lookup Go's resolver made and DNS did not answer. With no nameserver in resolv.conf Go asks 127.0.0.1:53 and
     * [::1]:53, so a refusal there means the process that looked the name up had no DNS server at all.
     */
    private static function dnsFailure(string $host, string $server, string $said, string $output): string
    {
        $subject = self::lookedUpFor($output);
        $lead = $subject === null ? "The build could not look up {$host}" : "{$subject} could not be downloaded: the build could not look up {$host}";

        if (self::isResolverFallback($server, $said)) {
            return "{$lead}, because no DNS server was configured where it was looked up: Go fell back to {$server}:53, "
                .'where none listens. This is a fault on the server, not in the project, and deploying again will not '
                .'help until it is fixed. Images from Docker Hub are not affected, as they come through the server\'s '
                .'registry mirror.';
        }

        return "{$lead}: the DNS server at {$server} answered \"".self::clip(trim($said)).'". This is a DNS problem on '
            .'the server, not in the project; deploy again, and if it repeats, the server\'s DNS needs fixing.';
    }

    private static function isResolverFallback(string $server, string $said): bool
    {
        return in_array($server, ['127.0.0.1', '[::1]'], true)
            && preg_match('/cannot assign requested address|connection refused|network is unreachable/i', $said) === 1;
    }

    /**
     * The base image whose metadata BuildKit was loading when the lookup failed, as its error block names it, else the
     * repository of the registry token it was fetching; compose prints only the latter on stderr.
     */
    private static function lookedUpFor(string $output): ?string
    {
        foreach (['/>\s*\[[^\]\n]*\]\s*load metadata for (\S+?):?\s*$/m', '/failed to resolve source metadata for (\S+?):\s/i'] as $pattern) {
            if (preg_match($pattern, $output, $m) === 1) {
                return 'The base image '.preg_replace('#^docker\.io/(?:library/)?#', '', $m[1]);
            }
        }
        if (preg_match('/token\/?\?scope=repository(?:%3A|:)([^&"\s]+?)(?:%3A|:)pull&service=([^&"\s]+)/i', $output, $m) === 1) {
            return 'The image '.rawurldecode($m[2]).'/'.rawurldecode($m[1]);
        }

        return null;
    }

    /**
     * Above base-image-unavailable: compose first pulls a tag it builds locally and prints a benign not-found, so a real failure after
     * it must win. runc's PATH search skips a non-executable file; exit 0 is a finished one-shot and 137 is out-of-memory's.
     * BuildKit's warning that git is missing from its own PATH says the same words on any build of a context without git.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function containerStartRules(): array
    {
        return [
            'bind-file-missing' => [
                '#mount src=/home/[^/\s]+/project/([^,\s]+), dst=([^,\s]+),[^\n]*?not a directory: Are you trying to mount a directory onto a file#i',
                static fn (array $m): string => "The compose file binds `{$m[1]}` onto `{$m[2]}`, and the repository has no `{$m[1]}`: "
                        .'Docker created a directory in its place, which cannot be mounted over a file. The project expects '
                        ."that file to be created before it starts; add `{$m[1]}` to the project (often by copying an example "
                        .'the repository ships), then deploy again.',
            ],
            'container-entrypoint-missing' => [
                '/^(?![^\n]*commit information was not captured)[^\n]*?exec:\s*"?([^"\n:]+)"?:\s*executable file not found/im',
                static fn (array $m, string $output): ?string => self::imageBuildFailed($output) ? null
                    : "The application's container could not start: its entrypoint ({$m[1]}) was not "
                        .'found in the image, or is not executable (chmod +x). The full output is in the deploy log.',
            ],
            'container-entrypoint-not-executable' => [
                '/exec:?\s*"?([^"\s:]+)"?:\s*permission denied/i',
                static fn (array $m): string => "The application's container could not start: its entrypoint ({$m[1]}) is not "
                        .'executable. Give it the execute bit (chmod +x, or RUN chmod +x in the Dockerfile). '
                        .'The full output is in the deploy log.',
            ],
            'container-start-failed' => [
                '/(?:dependency failed to start:[^\n]*?exited \((\d+)\)'
                    .'|service "[^"]+" didn.?t complete successfully:? (?:exit|exit code) (\d+)'
                    .'|\bcontainer \S+ exited \((\d+)\))/i',
                static function (array $m): ?string {
                    $code = ($m[1] ?? '') ?: (($m[2] ?? '') ?: ($m[3] ?? ''));
                    if ($code === '' || $code === '0' || $code === '137') {
                        return null;
                    }

                    return "A container this project runs exited with code {$code} before the "
                        .'application came up. The full output is in the deploy log.';
                },
            ],
        ];
    }

    /**
     * The account daemon's libnetwork socket not answering stops every RUN step (BuildKit's trailing EOF says nothing). A FROM
     * build argument with no default is one only the repository's CI passes, refused before anything is pulled. A stage image
     * with more than one layer cannot be mounted as a directory (see stage-mount-refused): the account's Docker mounts it with
     * `userxattr` over its own overlay-backed store, which the kernel refuses with `invalid argument`.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function buildDaemonRules(): array
    {
        return [
            'stage-mount-refused' => [
                '/error mounting "overlay" to rootfs at "\/([^"]+)": mount src=overlay[^\n]*containerd\.snapshotter\.v1\.overlayfs/',
                static fn (array $m): string => "A build step asked to mount a build stage as a directory (/{$m[1]}) and the account's "
                        .'Docker refused it: a stage image with more than one layer cannot be mounted that way inside the '
                        .'account, though the kernel also refuses the mount itself. `RUN --mount=from=<stage>` needs the '
                        .'mount; `COPY --from=<stage> <source> <destination>` copies the files instead and does not, so '
                        .'use it for a multi-layer stage. This is a limit of the account environment, not of the project.',
            ],

            'build-daemon-fault' => [
                '/error running prestart hook[^\n]*libnetwork\/\S+\.sock/',
                static fn (): string => "The account's Docker daemon could not start a build container: its network controller did "
                        .'not answer. This is a fault on the server, not in the project; deploy again, and if it '
                        ."repeats, the account's Docker needs restarting.",
            ],

            'build-arg-unset' => [
                '/base name \(\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?\) should not be blank/',
                static fn (array $m): string => "The project's Dockerfile starts FROM the build argument {$m[1]}, which has no default: it only "
                        ."builds when the project's own tooling passes --build-arg {$m[1]}=..., and nothing in the "
                        .'repository supplies it. It needs a PanelAlpha recipe that sets build_args, or a default '
                        .'in the Dockerfile.',
            ],
        ];
    }

    /**
     * A `test -n "$ARG"` guard heading a RUN that printed nothing: the argument only the project's own build script passes, or the
     * `curl -s` it guards failing silently. Last before the last resort, since the same guard heads build steps whose own failure
     * a specific rule above already names.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function buildArgGuardRules(): array
    {
        return [
            'build-arg-guard-unset' => [
                '/process "\/bin\/sh -c (?:test -n|\[ -n) \\\\?"?\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?\\\\?"?[^\n]*?" did not complete successfully: exit code: 1/',
                static fn (array $m): string => "A build step headed by `test -n \"{$m[1]}\"` failed at once, with no output of its own: either the "
                        ."build argument {$m[1]} had no value (this Dockerfile only builds when the project's own "
                        ."tooling passes --build-arg {$m[1]}=..., and nothing here supplies it) or the command it "
                        .'guards failed silently, as `curl -s` does. Give the argument a default or set it under '
                        ."`build_args` in a panelalpha.yaml, and drop curl's `-s` so a download failure prints its reason.",
            ],
        ];
    }

    /**
     * Docker Hub's `pull access denied` is left to base-image-unavailable, as it says that for a missing repository too. That rule
     * skips the benign pull of a tag the same log builds ({@see tagBuiltLocally()}) and keeps one slug for telemetry's history.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function baseImageRules(): array
    {
        return [
            'base-image-unauthorized' => [
                self::REGISTRY_REFUSAL,
                static fn (array $m, string $output = ''): ?string => self::registryRefusal($output),
            ],

            'base-image-rebuild-failed' => [
                '/The base image (\S+) is not on this server; rebuilding it failed\.\s*([\s\S]+)/',
                static fn (array $m): string => self::rebuiltBaseImage($m[1], $m[2]),
            ],

            'base-image-unavailable' => [
                self::REGISTRY_ERROR,
                static fn (array $m, string $output = ''): ?string => self::baseImageFailure($output),
            ],
        ];
    }

    /**
     * An end-of-life release whose archive dropped its packages, which the generic exit code never named, and node-gyp failing
     * to fetch the Node headers, ahead of the toolchain rule whose `gyp ERR!` it also prints.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function packageDownloadRules(): array
    {
        return [
            'package-archive-gone' => [
                '/(E: Failed to fetch \S+\s+404\s+Not Found|Could not resolve host: mirrorlist\.centos\.org)/i',
                static fn (): string => "A package install in the project's Dockerfile could not download its packages: the base "
                        ."image's distribution release no longer serves them, which is what happens once a release "
                        .'reaches end of life (Debian 11, CentOS 7). The Dockerfile has to move to a supported base image.',
            ],

            'native-build-headers-download-failed' => [
                '/gyp ERR! stack .*?\b(ConnectTimeoutError|ETIMEDOUT|EAI_AGAIN|ECONNRESET|ECONNREFUSED|ENOTFOUND|ENETUNREACH|EHOSTUNREACH|socket hang up)\b/i',
                static function (array $m, string $output = ''): string {
                    $url = preg_match('/gyp http GET (https?:\/\/\S+)/i', $output, $get) === 1 ? " ({$get[1]})" : '';

                    return 'A dependency compiles a native addon during install, and node-gyp could not download '
                        ."the Node.js headers it needs{$url}: the request failed with {$m[1]}. The build image "
                        .'has its toolchain; this is a network failure during the build. Deploy again; if it '
                        .'keeps failing, the server cannot reach that address.';
                },
            ],
        ];
    }

    /**
     * Webpack 4 hashes with MD4, which OpenSSL 3 refuses. A native addon needs toolchain evidence, not a bare `gyp ERR!` or the
     * `node-gyp rebuild` pnpm (running install scripts by default since 10) echoes for installs that succeed.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function nodeBuildRules(): array
    {
        return [
            'webpack4-openssl-unsupported' => [
                '/ERR_OSSL_EVP_UNSUPPORTED|error:0308010C:digital envelope routines::unsupported/',
                static fn (): string => 'The build uses webpack 4 (react-scripts 4 or older, Vue CLI 4, laravel-mix 5), which Node 17 '
                        .'and newer refuse to run without the OpenSSL legacy provider. Upgrade the build toolchain '
                        .'to webpack 5, or set NODE_OPTIONS=--openssl-legacy-provider for the build.',
            ],
            'bun-native-addon-failed' => [
                '/error: install script from "([^"]+)" exited with \d+/',
                static function (array $m, string $output = ''): ?string {
                    if (! str_contains($output, 'gyp ')) {
                        return null;
                    }
                    $node = preg_match('/gyp info using node@(\d+)\./', $output, $v) === 1 ? "Node {$v[1]}" : 'its own Node version';

                    return "Bun could not build the native dependency {$m[1]}: Bun builds it for {$node}, for which "
                        ."{$m[1]} has no prebuilt binary. If the project uses npm, delete bun.lockb (or bun.lock) from "
                        ."the repository; if it uses Bun, upgrade {$m[1]} to a release that supports {$node}.";
                },
            ],
            'native-build-toolchain-missing' => [
                '/(Could not find any Python installation to use|node-gyp: (?:command )?not found'
                    .'|gyp ERR! stack Error: not found: (?:make|g\+\+|gcc|cc|c\+\+)\b'
                    .'|make(?:\[\d+\])?: (?:g\+\+|gcc|cc|c\+\+): (?:Command not found|No such file or directory))/i',
                static fn (): string => 'A dependency has to be compiled during install, and this build image has no '
                        .'Python or C toolchain for it. Name an image that does in a panelalpha.yaml, '
                        .'or use a release of this dependency that ships a prebuilt binary.',
            ],
        ];
    }

    /**
     * Embedded assets precede the marker rule, which a build missing its frontend never reaches; the marker counts only on a BuildKit
     * output line, as the echoed RUN holds the same words. A constrained dependency means cgo; the constrained package itself does not.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function goRules(): array
    {
        return [
            'missing-embedded-assets' => [
                '/pattern [^\s:]*:?\S*: no matching files found/i',
                static fn (): string => 'This project embeds files that have to be produced by an earlier build step, '
                        .'and that step is not part of the automatic recipe. It needs a PanelAlpha page to describe its build.',
            ],

            'go-cgo-required' => [
                '/imports ([\w.\/@-]+): build constraints exclude all Go files/',
                static fn (array $m): string => "The Go package `{$m[1]}` has no files this build can compile. That is almost always "
                        .'because it needs cgo (a C compiler and the C library it wraps), and this build '
                        .'compiles without it. The full output is in the deploy log.',
            ],

            'go-package-excluded' => [
                '/\bpackage ([\w.\/@-]+): build constraints exclude all Go files/',
                static fn (array $m): string => "The Go package `{$m[1]}` that this build compiles has no file the build includes: "
                        .'a build constraint (a `//go:build` line, such as a tag the build does not set) '
                        .'excludes every one of them. The program is most likely in another directory; '
                        .'set the build command to build that package. The full output is in the deploy log.',
            ],

            'go-entrypoint-not-found' => [
                '/^#\d+\s+[\d.]+\s+PANELALPHA: no runnable Go program found/m',
                static fn (): string => 'No runnable program was found in this Go repository — the entrypoint could not be located automatically.',
            ],
        ];
    }

    /**
     * The app rejecting its own settings, almost always a secret left for the operator, and a datastore an earlier deploy created,
     * which keeps its first password: the env var is read only at first start.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function configurationRules(): array
    {
        return [
            'bind-source-missing' => [
                '#bind source path does not exist: /home/[^/\s]+/project/(\S+)#i',
                static fn (array $m): string => "The compose file binds `{$m[1]}`, which is not in the repository. The project expects "
                        .'it to exist before it starts, often copied from an example file next to it: commit it, or create it '
                        .'in a deploy hook.',
            ],

            'env-validation-failed' => [
                '/(environment variables? (has|have) failed the following validations|should not be one of the following values|must be longer than or equal to \d+ characters)/i',
                static fn (): string => 'The application refused to start because one of its settings is missing or still has a placeholder value. '
                        .'Set it under the application\'s environment variables and deploy again.',
            ],

            'database-auth-failed' => [
                self::DATABASE_AUTH_FAILURE,
                static fn (): string => 'The application could not sign in to its database. '.self::DATABASE_KEEPS_OLD_PASSWORD,
            ],
        ];
    }

    /**
     * The out-of-sync lockfile precedes dependency-conflict, as its log carries `npm warn ERESOLVE` lines; that rule takes npm's error
     * form only, and ERESOLVE as a case-sensitive word (Vite's `--debug` dump prints `createResolver`). npm crashing on its own tree
     * comes before both, as it prints no reason the other two would read.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function projectSetupRules(): array
    {
        return [
            'missing-build-script' => [
                '/(Missing script: ["\']?build|npm ERR! missing script: build)/i',
                static fn (): string => 'The project has no "build" script in package.json, so there is nothing to compile.',
            ],

            'angular-project-ambiguous' => [
                '/(Cannot determine project(?: or target)? for command|This is a multi-project workspace)/i',
                static fn (): string => 'The Angular workspace has several projects and the build did not name one, so '
                        .'`ng build` refused to guess. Add a "build" script to package.json that names '
                        .'the project to deploy (`ng build <project>`).',
            ],

            'npm-lockfile-out-of-sync' => [
                '/can only install packages when your package\.json and package-lock\.json'
                    .'(?: or npm-shrinkwrap\.json)? are in sync/i',
                static function (array $m, string $output = ''): string {
                    $detail = preg_match('/npm (?:error|ERR!) ((?:Missing|Invalid): [^\n]+)/i', $output, $d) === 1
                        ? ' (npm: '.trim($d[1]).')'
                        : '';

                    return "The project's package-lock.json does not match its package.json{$detail}, "
                        .'and `npm ci` installs only from a lockfile that does. Regenerate it with '
                        .'`npm install` and commit it.';
                },
            ],

            'npm-dependency-tree-crash' => [
                '/npm (?:error|ERR!) Cannot read properties of null \(reading \'edgesOut\'\)/',
                static fn (): string => 'npm itself crashed while it built the dependency tree (`Cannot read properties of null '
                        .'(reading \'edgesOut\')`) — a bug inside npm\'s resolver, not a statement about this project\'s '
                        .'dependencies. Installing from a committed package-lock.json with `npm ci`, or running a newer '
                        .'npm in the Dockerfile (`RUN npm install -g npm@latest`) before the install step, usually avoids it.',
            ],

            'dependency-conflict' => [
                '/^(?:(?!npm warn)[^\n])*?((?-i:\bERESOLVE\b)|unable to resolve dependency tree|conflicting peer dependency)/im',
                static fn (): string => 'The project\'s dependencies conflict with each other and could not be installed.',
            ],

            // A workspace whose root build compiles every package leaves one page per app; HostCompile refuses to
            // guess which one is served (relocateStaticOutput), so the failure has to say what the build did.
            'static-build-several-sites' => [
                '/Static build finished but (\S+)\/index\.html is missing; the build wrote index\.html in several directories: ([^\n]+)/',
                static fn (array $m): string => 'The build succeeded but wrote a page into several directories ('
                        .self::clip($m[2]).'), so there is no single site to serve: the repository builds several '
                        ."applications at once. Build only the one that is the site and have it write {$m[1]}/index.html, "
                        .'or deploy the project with a strategy that runs its own server.',
            ],
        ];
    }

    /**
     * npm's `syscall spawn git` reads as a dependency problem; the host compile picks its image from what the project declares
     * (NodeRuntime::needsGitBinary()), so when that misses, this is the only place to say what happened.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function gitBinaryRules(): array
    {
        return [
            'git-binary-missing' => [
                '/(Executable not found in \$PATH:\s*["\']git["\']|syscall spawn git'
                    .'|git dep preparation failed|git: (command )?not found)/i',
                static fn (): string => 'This build needs the git binary and the image it ran in has none. '
                        .'The build image is chosen from what the project declares — a git call written '
                        .'as `git <subcommand>`, or a dependency that resolves to a git URL — so try '
                        .'again after an engine update, or name an image that carries git in a '
                        .'panelalpha.yaml.',
            ],
        ];
    }

    /**
     * BuildKit often omits bun's own note and shows only the RUN and its exit code. The Poetry rule is telemetry (the engine installs
     * those with `poetry install --no-root`); the shell's own error line is preferred to BuildKit's generic exit code.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function installRules(): array
    {
        return [
            'prepare-script-failed' => [
                '/prepare script from .+ exited with 1/i',
                static fn (): string => 'A package.json "prepare" script failed during install. '
                        .'Git hook installers (husky/lefthook) cannot run in the build image.',
            ],

            'git-exec-failed' => [
                '/Error: exec: ["\']git["\']/i',
                static fn (): string => 'A build step needed the git binary (often lefthook/husky install). '
                        .'Those hooks are skipped in hosting builds — try deploying again after an engine update.',
            ],

            'bun-lockfile-outdated' => [
                '/error parsing lockfile:\s*Outdated lockfile version/i',
                static fn (): string => 'The Bun lockfile was written by a newer Bun than the one used to install dependencies. '
                        .'The hosting image should use a current Bun — try deploying again after an engine update.',
            ],

            'bun-lockfile-frozen' => [
                '/lockfile had changes, but lockfile is frozen/i',
                static fn (): string => 'Dependency install refused to change the lockfile (frozen install). '
                        .'The lockfile may not match package.json, or was produced by a different Bun version.',
            ],

            'bun-frozen-install-failed' => [
                '/RUN bun install --frozen-lockfile[\s\S]{0,400}?did not complete successfully: exit code: 1/i',
                static fn (): string => 'Bun could not install dependencies with a frozen lockfile (usually a Bun version mismatch). '
                        .'Try deploying again after an engine update that installs without --frozen-lockfile.',
            ],

            'missing-package-at-runtime' => [
                '/error: Cannot find package ["\']([^"\']+)["\']/i',
                static fn (array $m): string => "A required package ({$m[1]}) was missing when the app started. The install step may have been incomplete.",
            ],

            'dependency-not-found' => [
                '/could not find a version that satisfies the requirement/i',
                static fn (): string => 'One of the project\'s dependencies could not be found in the package registry.',
            ],

            'python-non-package-poetry' => [
                '/Building a package is not possible in non-package mode/i',
                static fn (): string => 'This project is a Poetry application, not a Python package, so its own code '
                        .'cannot be installed as a library. It needs to be run from its source '
                        .'with its dependencies installed — name a runnable entry point, or use a '
                        .'recipe that installs with `poetry install --no-root`.',
            ],

            'dockerfile-no-from' => [
                '/no build stage|the Dockerfile cannot be empty|file with no instructions'
                    .'|dockerfile contains no stages to build/i',
                static fn (): string => 'The Dockerfile has no FROM line, so there is nothing to build. '
                        .'Start it with `FROM <base image>`, or remove it so the engine detects the project itself.',
            ],

            'install-error-line' => [
                '/^#\d+\s+\d+\.\d+\s+(error:[^\n]+)/im',
                static fn (array $m): string => 'Install or build failed: '.trim($m[1]),
            ],
        ];
    }

    /**
     * A remote that listed its refs is readable, since a private or missing repository is refused before that line, so a stop after
     * it is the git host dropping requests.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function repositoryRules(): array
    {
        return [
            'repo-read-interrupted' => [
                '/expected flush after ref listing/i',
                static fn (): string => 'The repository started answering and then stopped partway through the clone. '
                        .'A private or missing repository is refused before that point, so this is the '
                        .'git host limiting or dropping requests, not the repository. Deploy again in a few minutes.',
            ],

            'repo-auth-failed' => [
                '/(fatal: could not read Username|Authentication failed|remote: Invalid username or password)/i',
                static fn (): string => 'The repository could not be read. Check that it is public, or that the access token is valid.',
            ],

            'repo-not-found' => [
                '/(fatal: repository .* not found|ERROR: Repository not found)/i',
                static fn (): string => 'The repository was not found. Check the address and whether it is private.',
            ],
        ];
    }

    /**
     * A COPY of a path the checkout lacks expects CI to have built it first. build-step-failed is the last resort, still better
     * than the dump.
     *
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function lastResortRules(): array
    {
        return [
            'build-context-missing' => [
                '/failed to (?:compute cache key|calculate checksum of ref)[^\n]*?"\/?([^"\n]+)": not found/i',
                static fn (array $m): string => "The repository's Dockerfile copies `{$m[1]}`, which is not in the repository. It is "
                        .'produced by a step that has to run before the image is built (usually CI), so the '
                        .'image cannot be built from a clean checkout. If the repository does have it, '
                        .'.dockerignore excludes it.',
            ],

            'ruby-gem-not-loaded' => [
                '/LoadError: cannot load such file -- (\S+)/',
                static fn (array $m): string => "A Ruby build step requires `{$m[1]}`, which is not in the installed bundle "
                        ."(often a gem in a group the Dockerfile's BUNDLE_WITHOUT leaves out). The project's "
                        .'Dockerfile has to install it or stop requiring it.',
            ],

            'build-step-failed' => [
                '/did not complete successfully: exit code: (\d+)/i',
                static fn (array $m): string => "A build step failed (exit code {$m[1]}). The full output is in the deploy log.",
            ],
        ];
    }

    /** A failed image build never got as far as starting a container. */
    private static function imageBuildFailed(string $output): bool
    {
        return preg_match('/^failed to solve:|did not complete successfully: exit code/im', $output) === 1;
    }

    /**
     * The first component of $ref when it has capitals and nothing that makes
     * it a real registry address (a dot, a port, `localhost`), else null.
     */
    private static function uppercaseRegistryHost(string $ref): ?string
    {
        $parts = explode('/', $ref);
        if (count($parts) < 2) {
            return null;
        }
        $first = $parts[0];
        if ($first === 'localhost' || strpbrk($first, '.:') !== false || strtolower($first) === $first) {
            return null;
        }

        return $first;
    }

    /** $ref with its repository lowercased; a tag may legitimately carry capitals. */
    private static function lowercaseRepository(string $ref): string
    {
        if (preg_match('/^(.*?)((?::[\w][\w.-]*)?(?:@\S+)?)$/', $ref, $m) !== 1) {
            return strtolower($ref);
        }

        return strtolower($m[1]).$m[2];
    }
}
