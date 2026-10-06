<?php

namespace App\Lib\Deploy\DeployLog;

use App\Lib\Deploy\Dind\RegistryAuth;

/**
 * Turns a failed build's BuildKit output into one sentence naming the cause.
 * Rule slugs are identifiers telemetry reports, so renaming one splits that
 * failure's history on the receiving end. Null means nothing matched.
 */
class DeployFailureExplainer
{
    /** Leads the failure of a redeploy whose new version the switch refused for an empty page. */
    public const EMPTY_NEW_VERSION = 'The new version answers with an empty page';

    private const REGISTRY_ERROR = '/(?:manifest unknown|manifest for \S+ not found|pull access denied'
        . '|failed to resolve source metadata|failed to do request'
        . '|failed to resolve reference (?:"([^"\n]+)"|(\S+)))/i';

    private const REGISTRY_REFUSAL = '/unauthorized|authentication required|authorization failed'
        . '|no basic auth credentials|denied: requested access|\b40[13]\b/i';

    public static function explain(string $output): ?string
    {
        $match = self::match($output);

        return $match === null ? null : $match['message'];
    }

    /**
     * The matching rule and the sentence it produced.
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
                // The whole output is passed too, so a rule that needs context
                // beyond its own match (base-image-unavailable) can see it.
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

    /** A quoted line, cut to a length a failure message can carry. */
    private static function clip(string $line): string
    {
        return mb_strlen($line) > 320 ? rtrim(mb_substr($line, 0, 320)) . '…' : $line;
    }

    private static function duration(int $seconds): string
    {
        return $seconds >= 120 && $seconds % 60 === 0 ? intdiv($seconds, 60) . ' minutes' : "{$seconds} seconds";
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
     * Whether $output shows the compose building the image $ref locally. Docker
     * compose pulls an image a sibling service builds before building it (the
     * local-tag pattern that sidesteps engine#229), and that pull's `failed to
     * resolve reference ... not found` is benign, not a missing base image.
     * BuildKit tags what it builds with `naming to <ref>`, so that line for the
     * same repository is the signal the tag was produced here, not fetched.
     * A build that fails never gets that far, so Compose's own
     * `Image <ref> Building` counts too.
     */
    private static function tagBuiltLocally(string $output, string $ref): bool
    {
        $ref = trim($ref, "\"' ");
        // Bare repository name: drop any registry/namespace and the tag/digest.
        $repo = (string) preg_replace('#^(?:[^/\s]+/)*#', '', $ref);
        $repo = preg_split('/[:@\s]/', $repo)[0] ?? '';
        if ($repo === '') {
            return false;
        }

        $quoted = preg_quote($repo, '/');

        return preg_match('/naming to \S*' . $quoted . '\b/i', $output) === 1
            || preg_match('/^\s*(?:Image\s+)?(?:\S*\/)?' . $quoted . '(?::\S+)?\s+Building\b/im', $output) === 1;
    }

    /**
     * The image a registry refused to hand over without a login, from the
     * first line that is a registry's answer and not some other 401 (an npm
     * or git one during the build). Null when no such line is in $output.
     */
    private static function registryRefusal(string $output): ?string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match(self::REGISTRY_REFUSAL, $line) !== 1
                || preg_match('#error from registry|failed to authorize|failed to resolve (?:source metadata|reference)'
                    . '|failed to pull OCI resource|pull access denied|Error response from daemon|/v2/\S+/manifests/#i', $line) !== 1) {
                continue;
            }
            $image = self::refusedImage($line);
            if (preg_match('/pull access denied/i', $line) === 1
                && ($image === null || RegistryAuth::registryFor($image) === 'docker.io')) {
                continue;
            }
            $named = $image === null ? 'An image this project uses' : "The image {$image}";

            return "{$named} could not be downloaded: its registry refused access, so it is private or needs a "
                . "login this project does not have. Give the project one with the registry-auth setting, one "
                . "'host username token' line per registry; if one is set, its token is wrong or cannot read this image.";
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
            $host = in_array($m[1], ['registry-1.docker.io', 'index.docker.io'], true) ? '' : $m[1] . '/';
            $path = $host === '' ? (string) preg_replace('#^library/#', '', $m[2]) : $m[2];
            $ref = str_starts_with($m[3], 'sha256:') ? '@' . $m[3] : ':' . $m[3];

            return $host . $path . $ref;
        }

        return null;
    }

    /**
     * Which of the three a failed base-image pull was, from the daemon's own
     * words, naming the image. Null when every registry error in $output is
     * the benign pull of a tag this same log builds.
     */
    private static function baseImageFailure(string $output): ?string
    {
        $found = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match(self::REGISTRY_ERROR, $line) !== 1) {
                continue;
            }
            $ref = '';
            foreach ([
                '/failed to resolve source metadata for (\S+?):\s/i',
                '/failed to resolve reference "?([^"\s]+)"?/i',
                '/manifest for (\S+) not found/i',
                '/pull access denied for ([^\s,]+)/i',
                '/(\S+): failed to do request/i',
            ] as $pattern) {
                // `failed to solve:` and BuildKit's `#N ERROR:` put a word, not an image, before the colon.
                if (preg_match($pattern, $line, $r) === 1 && !in_array(strtolower($r[1]), ['solve', 'error'], true)) {
                    $ref = $r[1];
                    break;
                }
            }
            if ($ref !== '' && self::tagBuiltLocally($output, $ref)) {
                continue;
            }
            $cause = match (true) {
                preg_match('/:\s*not found\b|manifest unknown|manifest for \S+ not found|name unknown/i', $line) === 1 => 'missing',
                preg_match('/repository does not exist or may require/i', $line) === 1 => 'missing-or-private',
                preg_match('/unauthorized|denied|authentication required|\b40[13]\b/i', $line) === 1 => 'private',
                preg_match('/failed to do request|dial tcp|no such host|i\/o timeout|connection refused'
                    . '|TLS handshake|deadline exceeded|server misbehaving/i', $line) === 1 => 'unreachable',
                default => 'unknown',
            };
            // A later line may name the image an earlier one of the same cause did not.
            if (($found[$cause] ?? '') === '') {
                $found[$cause] = preg_replace('#^docker\.io/(?:library/)?#', '', $ref);
            }
        }
        if ($found === []) {
            return null;
        }

        foreach (['missing', 'missing-or-private', 'private', 'unreachable', 'unknown'] as $cause) {
            if (!array_key_exists($cause, $found)) {
                continue;
            }
            $image = $found[$cause] === '' ? 'A base image this project asks for' : "The base image {$found[$cause]}";

            return match ($cause) {
                'missing' => "{$image} could not be downloaded: its registry says it does not exist. That tag or "
                    . 'repository was never published or has been removed, so the project has to name one that exists.',
                'missing-or-private' => "{$image} could not be downloaded: Docker Hub refused it, which it does both for a "
                    . 'repository that does not exist and for a private one. Check the name the project uses; a private '
                    . 'image cannot be pulled from here.',
                'private' => "{$image} could not be downloaded: its registry refused access, so it is private or needs "
                    . 'credentials this server does not have.',
                'unreachable' => "{$image} could not be downloaded: its registry could not be reached from this server. "
                    . 'A registry that only exists on the project\'s own network or CI cannot be used here.',
                default => "{$image} could not be downloaded — it may not exist, may be private, or its registry may be "
                    . 'unreachable from here.',
            };
        }

        return null;
    }

    /**
     * @return array<string, array{0: string, 1: callable(array<int|string, string>, string): ?string}>
     */
    private static function rules(): array
    {
        return [
            // StepWatchdog stopped a step for disk. First, like build-stalled below.
            'disk-limit-reached' => [
                '/' . preg_quote(StepWatchdog::DISK_MARKER, '/') . ' \((.*)\)/',
                static fn (array $m): string =>
                    "The deploy was stopped because {$m[1]}, and the account's unused Docker storage was cleared. "
                        . (str_starts_with($m[1], 'the engine host')
                            ? 'Free disk on the host and deploy again; `pae system:image:prune` removes base images no project has used recently.'
                            : 'Raise the project\'s disk limit, or make what the deploy downloads and builds smaller.'),
            ],

            // StepWatchdog killed a silent step. First: it quotes a last output line in
            // its marker, which another rule could otherwise match.
            'build-stalled' => [
                '/' . preg_quote(StepWatchdog::MARKER, '/') . ': "(.*?)" printed nothing for (\d+)s\. Last output: ([^\n]*)/',
                static fn (array $m): string =>
                    "The build step \"{$m[1]}\" printed nothing for " . self::duration((int) $m[2])
                        . " and was stopped. Last output: {$m[3]}. It was most likely stuck on a download "
                        . 'or network call; deploy again, and if it stalls at the same point, check that step.',
            ],

            // Symfony's ProcessTimedOutException: the message is the quoted command line
            // and nothing else, so name the step from it. Early: that command line is
            // not output, and later rules could match words in it.
            'clone-timed-out' => [
                "/The process \"[^\n]*'clone'[^\n]*\" exceeded the timeout of (\\d+) seconds/",
                static fn (array $m): string =>
                    'The repository did not finish cloning within ' . self::duration((int) $m[1])
                        . ' and the clone was stopped. It is most likely very large, or the link to its git '
                        . 'host is slow. Deploy a smaller branch or an archive of the code, or ask the server '
                        . 'administrator to raise DEPLOY_CLONE_TIMEOUT.',
            ],

            'build-timed-out' => [
                "/The process \"[^\n]*'up'[^\n]*'--build'[^\n]*\" exceeded the timeout of (\\d+) seconds/",
                static fn (array $m): string =>
                    'Building and starting the application did not finish within ' . self::duration((int) $m[1])
                        . ' and was stopped. The build output is in the deploy log; a build that runs this long '
                        . 'is usually waiting on a package registry or download that does not answer.',
            ],

            'step-timed-out' => [
                '/The process "[^\n]*" exceeded the timeout of (\d+) seconds/',
                static fn (array $m): string =>
                    'A deploy step did not finish within ' . self::duration((int) $m[1])
                        . ' and was stopped. The full output is in the deploy log.',
            ],

            // A service the app depends on never came up, quoted with what it printed
            // ({@see DependencyFailure}). Early for the same reason: the quote is another
            // program's output, which rules below could otherwise match.
            'dependency-failed' => [
                '/^' . preg_quote(DependencyFailure::PREFIX, '/') . '(\S+) did not start \(([^)]*)\)(?:: ([^\n]*))?/m',
                static function (array $m): string {
                    $said = trim($m[3] ?? '');
                    $sentence = "The service {$m[1]} did not start ({$m[2]}), so the application was not started either.";

                    return $said === ''
                        ? $sentence . ' The full output is in the deploy log.'
                        : $sentence . ' It printed: ' . self::clip($said) . ' The full output is in the deploy log.';
                },
            ],

            // The zero-downtime switch refused a new version serving an empty page. The
            // sentence is already the gate's own; the rule gives the failure its name.
            'new-version-empty-page' => [
                '/' . preg_quote(self::EMPTY_NEW_VERSION, '/') . '[^\n]*/',
                static fn (array $m): string => $m[0],
            ],

            // Language toolchain too old for what the project declares.
            'go-toolchain-too-old' => [
                '/go\.mod requires go >= ([0-9.]+).*?running go ([0-9.]+)/is',
                static fn (array $m): string =>
                    "This project needs Go {$m[1]}, but it was built with Go {$m[2]}.",
            ],

            // The Java image carries one JDK. `javac`'s own line is matched, not the plugin
            // goal that reported it. Measured: thingsboard, tigase, graphhopper, druid and
            // openrouteservice all declare 25 against the image's 21.
            'java-release-too-new' => [
                '/error: release version ([0-9]+) not supported/i',
                static fn (array $m): string =>
                    "This project has to be compiled for Java {$m[1]}, and the build image ships an "
                        . 'older JDK, whose compiler refuses it. The full build output is in the deploy log.',
            ],

            // Maven could not put the reactor together: a module or parent POM it needs is
            // missing. Distinct from a compile error and from a dependency resolution failure.
            'java-project-build-failed' => [
                '/(?:\[ERROR\] Failed to execute goal[^\n]*(?:ProjectBuildingException|UnresolvableModelException)'
                    . '|Child module ([^\s]+) of [^\s]+ does not exist'
                    . '|Non-resolvable parent POM for ([^\s:]+))[^\n]*/i',
                static fn (array $m): string =>
                    'Maven could not assemble this project: a module or parent POM it declares is '
                        . 'absent' . (($m[1] ?? '') !== '' ? " ({$m[1]})" : (($m[2] ?? '') !== '' ? " ({$m[2]})" : '')) . '. '
                        . 'The full output is in the deploy log.',
            ],

            // A Rust build script or link step asked for a tool the build image lacks.
            // Named, because "does not compile: `quote`" sends the user to their own code.
            'rust-build-tool-missing' => [
                '/Could not find `protoc`|Missing dependency: cmake|is `cmake` not installed'
                    . "|Unable to find libclang|collect2: fatal error: cannot find 'ld'/",
                static function (array $m, string $output = ''): string {
                    $tool = match (true) {
                        str_contains($m[0], 'protoc') => '`protoc` (the Protocol Buffers compiler)',
                        str_contains($m[0], 'cmake') => '`cmake`',
                        str_contains($m[0], 'libclang') => '`libclang` (for bindgen)',
                        preg_match('/-fuse-ld=([a-z]+)/', $output, $l) === 1
                            => "the `{$l[1]}` linker, which the project selects with `-fuse-ld={$l[1]}` in .cargo/config.toml,",
                        default => 'the linker the project selects in .cargo/config.toml',
                    };

                    return "The Rust build needs {$tool} and the build image does not have it. "
                        . 'The full output is in the deploy log.';
                },
            ],

            // A crate's build script needs pkg-config or the headers it queries: `The
            // pkg-config command could not be found`.
            'native-library-headers-missing' => [
                '/(The pkg-config command could not be found'
                    . '|Package \\S+ was not found in the pkg-config search path'
                    . '|Could not find \\S+ using pkg-config'
                    // lxml's own sdist build (engine#120).
                    . '|make sure the \\S+ (?:and \\S+ )?development packages are installed)/i',
                static fn (): string =>
                    'A dependency has to be compiled and needs development headers that the build '
                        . 'image does not carry (pkg-config, or a library it queries). The full '
                        . 'output is in the deploy log, naming the dependency.',
            ],

            // Composer names the package (`- vendor/pkg v1.2 requires php ^7 -> your php
            // version ...`) or `Root composer.json`; a locked package is not the project.
            'php-version-mismatch' => [
                '/(?:-\s+(\S+)\s+(\S+)\s+)?requires php ([^\s,]+).*?your php version \(([^)]+)\)/is',
                static fn (array $m): string => ($m[1] ?? '') !== '' && $m[1] !== 'Root'
                    ? "The locked package {$m[1]} {$m[2]} needs PHP {$m[3]}, but the project was built with PHP {$m[4]}."
                    : "This project needs PHP {$m[3]}, but it was built with PHP {$m[4]}.",
            ],

            // Composer resolves against the runtime image, so the extension really is absent
            // from the image the app runs on; the fix is to bake it, not to retry.
            'php-extension-missing' => [
                // Composer 2 says "requires PHP extension ext-zstd", older versions say
                // "requires ext-zstd"; both are in the wild.
                '/requires (?:PHP extension )?ext-([a-z0-9_]+).*?it is missing from your system/is',
                static fn (array $m): string =>
                    "This project needs the PHP extension {$m[1]}, which is not in the PHP image it runs on.",
            ],

            // Above `composer-unresolvable`, which prints the same header: no package source
            // has these at all, which is not a conflict. farmOS is a Drupal profile whose
            // composer.json declares no packages.drupal.org, so all 28 drupal/* were unknown.
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
                        . (count($names) > 3 ? ' and ' . (count($names) - 3) . ' more' : '');

                    return 'Composer could not find ' . (count($names) === 1 ? 'a package' : count($names) . ' packages')
                        . " this project requires ({$shown}) in any repository it was given. Such packages "
                        . 'usually come from a repository the composer.json does not declare, or the '
                        . 'repository is a library meant to be required by another project rather than '
                        . 'an application. The full resolver output is in the deploy log.';
                },
            ],

            // The project's own constraints cannot be satisfied together; nothing the
            // platform can do about it.
            'composer-unresolvable' => [
                '/Your requirements could not be resolved to an installable set of packages/i',
                static fn (): string =>
                    'This project\'s Composer dependencies cannot all be installed together.'
                    . ' The full resolver output is in the deploy log.',
            ],

            // Only npm's *error* form, never `npm warn EBADENGINE` — a warning reports what
            // some transitive dependency asked for, not what this project needs (measured:
            // nextcloud/server declares ^24.0.0 and was reported as ^22.0.0 off a warning).
            // The tempered `(?!npm warn)` stops the scan at the next warning line.
            'node-engine-mismatch' => [
                '/npm (?:error|ERR!)[^\n]*Unsupported engine(?:(?!npm warn)[\s\S])*?'
                    . 'required:\s*\{?\s*node:\s*\'?([^\'",}]+)/i',
                static fn (array $m): string =>
                    'This project needs Node ' . trim($m[1]) . ', which does not match the version used to build it.',
            ],

            // Resources.
            'disk-full' => [
                '/(no space left on device|ENOSPC|errno=28)/i',
                static fn (): string =>
                    'The build ran out of disk space. Free some space in the account or move to a larger plan.',
            ],

            // An account's user namespace maps ids 0-65535 only, so restoring a
            // higher owner fails with EINVAL. Flutter's gradle-wrapper.tgz is
            // uid 397546 and Flutter blames the network for it.
            'owner-id-out-of-range' => [
                '/(?:Cannot change ownership to uid (\d+), gid (\d+)|lchown ([^\n:]+)): invalid argument/i',
                static fn (array $m): string => (($m[1] ?? '') !== ''
                    ? "A file in the build is owned by uid {$m[1]}, gid {$m[2]}"
                    : 'A file in the build (' . trim($m[3]) . ') is owned by a user or group id') . ' '
                        . 'that cannot exist in an account, which holds ids 0-65535 only. Extract archives '
                        . 'without restoring their owner (tar --no-same-owner, or TAR_OPTIONS=--no-same-owner '
                        . 'in the Dockerfile), or use an image whose files are owned by ids below 65536.',
            ],

            // Java refusing to allocate inside the heap it was given (the fix is how the
            // engine sizes the heap), not the kernel killing a cgroup (`out-of-memory`).
            // Ranked above `out-of-memory` because one Maven log can carry both, and a
            // Maven OOM names no container of its own. Above `build-step-failed` too:
            // Maven prints a successful reactor summary before the failure, so the last
            // line is unrelated noise (measured: an Alfresco deploy reported a `/root`
            // mkdir permission error where the truth was 512 MB of heap against a 2 GB
            // container).
            'java-heap-space' => [
                '/(OutOfMemoryError:\s*Java heap space|java\.lang\.OutOfMemoryError'
                    . '|Java heap space\s*->|There is insufficient memory for the Java Runtime'
                    . '|Could not reserve enough space for \d+KB object heap)/i',
                static fn (): string =>
                    'The Java build ran out of heap: the compiler was given less memory than this '
                        . 'project needs. The build container had more to give — the heap is sized '
                        . 'from it — so this is an engine limit, not the plan. The full build output '
                        . 'is in the deploy log.',
            ],

            // The same for V8, and the same reason it outranks `out-of-memory`: Node
            // refusing to allocate inside its heap, not the kernel killing the cgroup.
            // `Reached heap limit` is the capped form, `Ineffective mark-compacts` the
            // other.
            'node-heap-space' => [
                '/FATAL ERROR:\s*(?:Ineffective mark-compacts near heap limit'
                    . '|Reached heap limit|CALL_AND_RETRY_LAST Allocation failed)'
                    . '|JavaScript heap out of memory/',
                static fn (): string =>
                    'The Node build ran out of heap: the build was given less memory than this '
                        . 'project needs. The build container had more to give — the heap is sized '
                        . 'from it — so this is an engine limit, not the plan. The full build output '
                        . 'is in the deploy log.',
            ],

            // The kernel's own forms only. npm prints `killed: false,` and `signal: null` on
            // any plain failure, and a bare `\bKilled\b` matched the false value (measured:
            // espocrm reported out of memory after dying in phantomjs-prebuilt).
            //
            // A line that is *only* `Killed`, anchored with /m, is what a host build produces:
            // those run `docker run --entrypoint sh -e -c <script>` with no BuildKit, so no
            // `exit code: 137` is printed. Maven's frontend plugin relays it as `[ERROR] Killed`.
            // `out of memory` stays bare — the kernel writes it that way in `Memory cgroup out
            // of memory: Killed process ...`.
            //
            // A host build is not in the account's cgroup, so the plan is not what ran out.
            'out-of-memory' => [
                '/(exit code: 137|signal:\s*killed|OOMKilled'
                    // BuildKit's form when the step's cgroup refused an allocation,
                    // in the summary and in the step's own `#N ERROR:` line.
                    . '|(?:ResourceExhausted:|did not complete successfully:)[^\n]*cannot allocate memory'
                    . '|out of memory'
                    . '|(?:task|process)\s+"?[\w\/.-]+"?\s+killed'
                    . '|^[ \t]*(?:\[ERROR\][ \t]+)?Killed[ \t]*$'
                    . '|oom-kill)/im',
                static fn (array $m, string $output = ''): string => preg_match('/^\s*(?:\[ERROR\]\s+)?Killed\s*$/', $m[1]) === 1
                    || str_contains($output, 'host build container')
                    ? 'The build ran out of memory in the engine\'s build container, which is sized for '
                        . 'the server (DEPLOY_BUILD_MEMORY, 8 GB or half its RAM by default), not by the plan. '
                        . 'Raising the project\'s memory limit does not change it. The full build output is '
                        . 'in the deploy log.'
                    : 'The build ran out of memory. This project needs more RAM than the plan allows.',
            ],

            // Below `out-of-memory`: a Java build that spawned a Node frontend usually died
            // because the frontend ran the JVM out of memory. Reached only when Maven reports
            // a goal failure (keycloak, openmeetings do this).
            'java-plugin-goal-failed' => [
                '/\[ERROR\] Failed to execute goal (com\.github\.eirslett|com\.diffplug\.spotless):[^\n]*/i',
                static fn (array $m): string =>
                    'The Java build ran a frontend step that reported failures'
                        . ' (the ' . $m[1] . ' plugin). '
                        . 'The full output is in the deploy log.',
            ],
            'java-license-check-failed' => [
                '/\[ERROR\] Failed to execute goal org\.apache\.rat:[^\n]*/i',
                static fn (): string =>
                    'The Java build refused to continue over a missing license header (the Apache RAT '
                        . 'plugin). The full output is in the deploy log.',
            ],

            // A Rust `-sys` crate with no C++ compiler in the image says so about itself
            // (`CXX_... = None`), which reads as a crate fault when it is the image's.
            // `native-library-headers-missing` above covers the pkg-config case.
            'native-build-interrupted' => [
                '/^(?:CXX?_[A-Za-z0-9_-]+ = None|CC_FORCE_DISABLE = None)$/m',
                static fn (): string =>
                    'A dependency has to be compiled from source and the build container had no C or '
                        . 'C++ compiler to do it. The full output is in the deploy log.',
            ],

            // Cargo's own report, naming the crate. The two specific reasons above win where
            // they apply; this catches the long tail that says only "a build script failed",
            // which is not always a C library: scripts also panic on missing assets or inputs.
            'rust-build-script-failed' => [
                '/error: failed to run custom build command for `([^`]+)`/',
                static function (array $m, string $output = ''): string {
                    // A local path (`name v1 (/app/src/data)`) is the checkout's own crate.
                    $crate = preg_match('/\(\/[^)]*\)\s*$/', $m[1]) === 1
                        ? "The project's Rust crate `{$m[1]}`"
                        : "The Rust dependency `{$m[1]}`";
                    $after = (string) strstr($output, $m[0]);
                    $said = self::buildScriptPanic($after !== '' ? $after : $output);
                    $said = $said === null ? '' : ' It said: ' . rtrim(self::clip($said), '.') . '.';

                    return "{$crate} could not be built: its build script failed.{$said}"
                        . ' The full output is in the deploy log.';
                },
            ],

            'rust-compile-failed' => [
                '/error: could not compile `([^`]+)`/',
                static fn (array $m): string =>
                    "The project's Rust code does not compile: `{$m[1]}`. The full output is in the "
                        . 'deploy log.',
            ],

            // Registry.
            'registry-rate-limited' => [
                '/(toomanyrequests|429 Too Many Requests)/i',
                static fn (): string =>
                    'Docker Hub temporarily refused further downloads because of its rate limit. Try again in a few minutes.',
            ],

            // The image exists but a layer arrived corrupted (seen when the host's registry
            // mirror filled its disk and served a truncated blob). Above base-image-unavailable.
            'layer-digest-mismatch' => [
                '/unexpected commit digest/i',
                static fn (): string =>
                    'A downloaded image layer arrived corrupted — the host or its registry mirror '
                        . 'delivered a damaged copy. This is a problem on the host, not in the project; '
                        . 'retrying the deploy usually works.',
            ],

            // An uppercase image name. The daemon takes a first component it cannot read as a
            // repository for a registry host, so `HaschekSolutions/pictshare:3` failed as a DNS
            // lookup of `HaschekSolutions` and read as an unreachable registry (#125).
            // Above base-image-unavailable, which would otherwise claim it.
            'image-reference-invalid' => [
                '/(?:failed to resolve reference "([^"\n]+)"'
                    . '|invalid reference format: repository name \(([^)\n]+)\) must be lowercase)/i',
                static function (array $m): ?string {
                    if (($m[2] ?? '') !== '') {
                        return "The image name \"{$m[2]}\" is not valid: Docker image names must be lowercase.";
                    }
                    $host = self::uppercaseRegistryHost($m[1]);
                    if ($host === null) {
                        return null;
                    }

                    return "The image name \"{$m[1]}\" is not valid: Docker image names must be lowercase, "
                        . "so \"{$host}\" was read as the address of a registry, which does not exist. "
                        . 'The file that names it has to say "' . self::lowercaseRepository($m[1]) . '".';
                },
            ],

            // `docker compose up` failures, ranked above base-image-unavailable: when a
            // compose builds the app image locally and a sibling references that tag, compose
            // first tries to PULL it, prints a benign `failed to resolve reference ... not
            // found`, then builds it. A real failure that follows (an entrypoint that is not
            // there, a one-shot exiting non-zero) is the true cause and must win over that
            // noise. Confirmed on Bitpoll (init exit 1) and Limbas (missing entrypoint).
            // runc's $PATH search skips a file without the execute bit, so this
            // also means "there, but not executable".
            'container-entrypoint-missing' => [
                '/exec:\s*"?([^"\n:]+)"?:\s*executable file not found/i',
                static fn (array $m): string =>
                    "The application's container could not start: its entrypoint ({$m[1]}) was not "
                        . 'found in the image, or is not executable (chmod +x). The full output is in the deploy log.',
            ],
            // An absolute-path entrypoint without the execute bit.
            'container-entrypoint-not-executable' => [
                '/exec:?\s*"?([^"\s:]+)"?:\s*permission denied/i',
                static fn (array $m): string =>
                    "The application's container could not start: its entrypoint ({$m[1]}) is not "
                        . 'executable. Give it the execute bit (chmod +x, or RUN chmod +x in the Dockerfile). '
                        . 'The full output is in the deploy log.',
            ],
            'container-start-failed' => [
                '/(?:dependency failed to start:[^\n]*?exited \((\d+)\)'
                    . '|service "[^"]+" didn.?t complete successfully:? (?:exit|exit code) (\d+)'
                    . '|\bcontainer \S+ exited \((\d+)\))/i',
                static function (array $m): ?string {
                    $code = ($m[1] ?? '') ?: (($m[2] ?? '') ?: ($m[3] ?? ''));
                    // Exit 0 is a one-shot that finished; 137 is a kill the out-of-memory
                    // rule above already owns. Neither is this rule's failure.
                    if ($code === '' || $code === '0' || $code === '137') {
                        return null;
                    }

                    return "A container this project runs exited with code {$code} before the "
                        . 'application came up. The full output is in the deploy log.';
                },
            ],

            // runc could not reach the account daemon's libnetwork socket, so no RUN step
            // can start (engine#111). The trailing BuildKit EOF says nothing.
            'build-daemon-fault' => [
                '/error running prestart hook[^\n]*libnetwork\/\S+\.sock/',
                static fn (): string =>
                    "The account's Docker daemon could not start a build container: its network controller did "
                        . 'not answer. This is a fault on the server, not in the project; deploy again, and if it '
                        . "repeats, the account's Docker needs restarting.",
            ],

            // `FROM ${BASE_IMAGE}` with no default, the value only the repo's CI passes
            // (livebook, engine#143). BuildKit refuses before pulling anything.
            'build-arg-unset' => [
                '/base name \(\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?\) should not be blank/',
                static fn (array $m): string =>
                    "The project's Dockerfile starts FROM the build argument {$m[1]}, which has no default: it only "
                        . "builds when the project's own tooling passes --build-arg {$m[1]}=..., and nothing in the "
                        . 'repository supplies it. It needs a PanelAlpha recipe that sets build_args, or a default '
                        . 'in the Dockerfile.',
            ],

            // A registry that refused an anonymous or wrong login (ghcr.io's 401, a
            // private registry's `no basic auth credentials`). Docker Hub's own `pull
            // access denied` is left to base-image-unavailable: it says that for a
            // missing repository too.
            'base-image-unauthorized' => [
                self::REGISTRY_REFUSAL,
                static fn (array $m, string $output = ''): ?string => self::registryRefusal($output),
            ],

            // BuildKit's wording when it cannot reach the registry at all: a pruned patch tag
            // answers `not found` on its own, and a Dockerfile built for someone else's CI
            // names a registry that is not there. The `failed to resolve reference` branch is
            // the ambiguous one: it is also the benign compose pull of a locally-built tag, so
            // a ref this same log then builds (see tagBuiltLocally) is not a missing base image.
            // The sentence says which of not-found / private / unreachable the daemon
            // reported (engine#100); the slug stays one, for telemetry's history.
            'base-image-unavailable' => [
                self::REGISTRY_ERROR,
                static fn (array $m, string $output = ''): ?string => self::baseImageFailure($output),
            ],

            // A base image whose distribution release is end of life: bullseye-security
            // 404s on the +deb11uN packages its index names (engine#114), CentOS 7's
            // mirrorlist host is gone (engine#102). The generic exit code named neither.
            'package-archive-gone' => [
                '/(E: Failed to fetch \S+\s+404\s+Not Found|Could not resolve host: mirrorlist\.centos\.org)/i',
                static fn (): string =>
                    "A package install in the project's Dockerfile could not download its packages: the base "
                        . "image's distribution release no longer serves them, which is what happens once a release "
                        . 'reaches end of life (Debian 11, CentOS 7). The Dockerfile has to move to a supported base image.',
            ],

            // node-gyp found its toolchain but could not download the Node headers (wud: the
            // build could not reach unofficial-builds.nodejs.org). Before the toolchain rule,
            // whose `gyp ERR!` it also prints.
            'native-build-headers-download-failed' => [
                '/gyp ERR! stack .*?\b(ConnectTimeoutError|ETIMEDOUT|EAI_AGAIN|ECONNRESET|ECONNREFUSED|ENOTFOUND|ENETUNREACH|EHOSTUNREACH|socket hang up)\b/i',
                static function (array $m, string $output = ''): string {
                    $url = preg_match('/gyp http GET (https?:\/\/\S+)/i', $output, $get) === 1 ? " ({$get[1]})" : '';

                    return 'A dependency compiles a native addon during install, and node-gyp could not download '
                        . "the Node.js headers it needs{$url}: the request failed with {$m[1]}. The build image "
                        . 'has its toolchain; this is a network failure during the build. Deploy again; if it '
                        . 'keeps failing, the server cannot reach that address.';
                },
            ],

            // node-gyp needs a Python interpreter and a C toolchain the slim Node images do
            // not carry. pnpm 10+ runs install scripts by default, so the first dependency
            // with a native addon ends the build with gyp output and no diagnosis.
            // Not a bare `node-gyp rebuild`: pnpm echoes that script line for installs that succeed.
            // Webpack 4 hashes with MD4; OpenSSL 3 (Node 17+) refuses it.
            'webpack4-openssl-unsupported' => [
                '/ERR_OSSL_EVP_UNSUPPORTED|error:0308010C:digital envelope routines::unsupported/',
                static fn (): string =>
                    'The build uses webpack 4 (react-scripts 4 or older, Vue CLI 4, laravel-mix 5), which Node 17 '
                        . 'and newer refuse to run without the OpenSSL legacy provider. Upgrade the build toolchain '
                        . 'to webpack 5, or set NODE_OPTIONS=--openssl-legacy-provider for the build.',
            ],
            'native-build-toolchain-missing' => [
                // Toolchain evidence only: a bare `gyp ERR!` is any node-gyp failure.
                '/(Could not find any Python installation to use|node-gyp: (?:command )?not found'
                    . '|gyp ERR! stack Error: not found: (?:make|g\+\+|gcc|cc|c\+\+)\b'
                    . '|make(?:\[\d+\])?: (?:g\+\+|gcc|cc|c\+\+): (?:Command not found|No such file or directory))/i',
                static fn (): string =>
                    'A dependency has to be compiled during install, and this build image has no '
                        . 'Python or C toolchain for it. Name an image that does in a panelalpha.yaml, '
                        . 'or use a release of this dependency that ships a prebuilt binary.',
            ],

            // Assets a compiled binary embeds at build time (go:embed and the like). Above the
            // marker rule below: with the frontend missing the build never reaches it.
            'missing-embedded-assets' => [
                '/pattern [^\s:]*:?\S*: no matching files found/i',
                static fn (): string =>
                    'This project embeds files that have to be produced by an earlier build step, '
                        . 'and that step is not part of the automatic recipe. It needs a PanelAlpha page to describe its build.',
            ],

            // A dependency whose every file is behind a build tag -- in practice cgo, which the
            // build has off: no C compiler in golang:*-alpine, or CGO_ENABLED=0 set outright.
            'go-cgo-required' => [
                '/imports ([\w.\/@-]+): build constraints exclude all Go files/',
                static fn (array $m): string =>
                    "The Go package `{$m[1]}` has no files this build can compile. That is almost always "
                        . 'because it needs cgo (a C compiler and the C library it wraps), and this build '
                        . 'compiles without it. The full output is in the deploy log.',
            ],

            // The package the build was asked for, not a dependency: a constraint such as the
            // tools.go idiom's `//go:build tools` excludes it, which says nothing about cgo.
            'go-package-excluded' => [
                '/\bpackage ([\w.\/@-]+): build constraints exclude all Go files/',
                static fn (array $m): string =>
                    "The Go package `{$m[1]}` that this build compiles has no file the build includes: "
                        . 'a build constraint (a `//go:build` line, such as a tag the build does not set) '
                        . 'excludes every one of them. The program is most likely in another directory; '
                        . 'set the build command to build that package. The full output is in the deploy log.',
            ],

            // Anchored to a BuildKit *output* line (#<step> <seconds>): the same words appear
            // in the RUN instruction BuildKit echoes when a build fails.
            'go-entrypoint-not-found' => [
                '/^#\d+\s+[\d.]+\s+PANELALPHA: no runnable Go program found/m',
                static fn (): string =>
                    'No runnable program was found in this Go repository — the entrypoint could not be located automatically.',
            ],

            // The app started but rejected its own configuration — almost always a secret the
            // project expects the operator to fill in.
            'env-validation-failed' => [
                '/(environment variables? (has|have) failed the following validations|should not be one of the following values|must be longer than or equal to \d+ characters)/i',
                static fn (): string =>
                    'The application refused to start because one of its settings is missing or still has a placeholder value. '
                        . 'Set it under the application\'s environment variables and deploy again.',
            ],

            // A datastore created by an earlier deploy keeps the password it was initialised
            // with; the env var is only read at first start.
            'database-auth-failed' => [
                '/(password authentication failed for user|Access denied for user .{0,40}using password|authentication failed.{0,40}MongoServerError)/i',
                static fn (): string =>
                    'The application could not sign in to its database. The database was created by an earlier deploy '
                        . 'and still expects the old password — delete the application\'s database volume, or recreate the '
                        . 'application, and deploy again.',
            ],

            // Project setup.
            'missing-build-script' => [
                '/(Missing script: ["\']?build|npm ERR! missing script: build)/i',
                static fn (): string =>
                    'The project has no "build" script in package.json, so there is nothing to compile.',
            ],

            // `ng build` in a workspace with several projects and nothing to pick one by.
            'angular-project-ambiguous' => [
                '/(Cannot determine project(?: or target)? for command|This is a multi-project workspace)/i',
                static fn (): string =>
                    'The Angular workspace has several projects and the build did not name one, so '
                        . '`ng build` refused to guess. Add a "build" script to package.json that names '
                        . 'the project to deploy (`ng build <project>`).',
            ],

            // `npm ci` refuses a lockfile that no longer matches package.json and
            // installs nothing; ranked above dependency-conflict because the same log
            // usually carries `npm warn ERESOLVE overriding peer dependency` lines
            // (measured: Automad, `Missing: yaml@2.9.1 from lock file`).
            'npm-lockfile-out-of-sync' => [
                '/can only install packages when your package\.json and package-lock\.json'
                    . '(?: or npm-shrinkwrap\.json)? are in sync/i',
                static function (array $m, string $output = ''): string {
                    $detail = preg_match('/npm (?:error|ERR!) ((?:Missing|Invalid): [^\n]+)/i', $output, $d) === 1
                        ? ' (npm: ' . trim($d[1]) . ')'
                        : '';

                    return "The project's package-lock.json does not match its package.json{$detail}, "
                        . 'and `npm ci` installs only from a lockfile that does. Regenerate it with '
                        . '`npm install` and commit it.';
                },
            ],

            // npm's error form only: `npm warn ERESOLVE overriding peer dependency` is npm
            // saying it resolved the conflict, and a log full of them failed for another
            // reason (the tempered token keeps a warn line from matching). ERESOLVE is
            // npm's code, matched case-sensitively as a word: Vite's `--debug` config dump
            // prints `createResolver`, which read as a conflict (Sunshine).
            'dependency-conflict' => [
                '/^(?:(?!npm warn)[^\n])*?((?-i:\bERESOLVE\b)|unable to resolve dependency tree|conflicting peer dependency)/im',
                static fn (): string =>
                    'The project\'s dependencies conflict with each other and could not be installed.',
            ],

            // npm's own wording is the one that reads least like the truth:
            // `npm error syscall spawn git` names a dependency problem, not a missing
            // binary, and says nothing about the image the build runs in. The host-compile
            // path picks that image from what the project declares -- see
            // NodeRuntime::needsGitBinary() -- so when the declaration misses, this is the
            // only place the reader can be told what actually happened.
            'git-binary-missing' => [
                '/(Executable not found in \$PATH:\s*["\']git["\']|syscall spawn git'
                    . '|git dep preparation failed|git: (command )?not found)/i',
                static fn (): string =>
                    'This build needs the git binary and the image it ran in has none. '
                        . 'The build image is chosen from what the project declares — a git call written '
                        . 'as `git <subcommand>`, or a dependency that resolves to a git URL — so try '
                        . 'again after an engine update, or name an image that carries git in a '
                        . 'panelalpha.yaml.',
            ],

            'prepare-script-failed' => [
                '/prepare script from .+ exited with 1/i',
                static fn (): string =>
                    'A package.json "prepare" script failed during install. '
                        . 'Git hook installers (husky/lefthook) cannot run in the build image.',
            ],

            'git-exec-failed' => [
                '/Error: exec: ["\']git["\']/i',
                static fn (): string =>
                    'A build step needed the git binary (often lefthook/husky install). '
                        . 'Those hooks are skipped in hosting builds — try deploying again after an engine update.',
            ],

            'bun-lockfile-outdated' => [
                '/error parsing lockfile:\s*Outdated lockfile version/i',
                static fn (): string =>
                    'The Bun lockfile was written by a newer Bun than the one used to install dependencies. '
                        . 'The hosting image should use a current Bun — try deploying again after an engine update.',
            ],

            'bun-lockfile-frozen' => [
                '/lockfile had changes, but lockfile is frozen/i',
                static fn (): string =>
                    'Dependency install refused to change the lockfile (frozen install). '
                        . 'The lockfile may not match package.json, or was produced by a different Bun version.',
            ],

            // BuildKit often omits bun's note and shows only the RUN plus exit code.
            'bun-frozen-install-failed' => [
                '/RUN bun install --frozen-lockfile[\s\S]{0,400}?did not complete successfully: exit code: 1/i',
                static fn (): string =>
                    'Bun could not install dependencies with a frozen lockfile (usually a Bun version mismatch). '
                        . 'Try deploying again after an engine update that installs without --frozen-lockfile.',
            ],

            'missing-package-at-runtime' => [
                '/error: Cannot find package ["\']([^"\']+)["\']/i',
                static fn (array $m): string =>
                    "A required package ({$m[1]}) was missing when the app started. The install step may have been incomplete.",
            ],

            'dependency-not-found' => [
                '/could not find a version that satisfies the requirement/i',
                static fn (): string =>
                    'One of the project\'s dependencies could not be found in the package registry.',
            ],

            // A Poetry application, not a package: `pip install .` asks poetry-core to build
            // a wheel and its refusal is the only line saying so. The engine installs these
            // with `poetry install --no-root`, so this rule is telemetry.
            'python-non-package-poetry' => [
                '/Building a package is not possible in non-package mode/i',
                static fn (): string =>
                    'This project is a Poetry application, not a Python package, so its own code '
                        . 'cannot be installed as a library. It needs to be run from its source '
                        . 'with its dependencies installed — name a runnable entry point, or use a '
                        . 'recipe that installs with `poetry install --no-root`.',
            ],

            // Prefer the shell's own error line over BuildKit's generic exit code.
            'install-error-line' => [
                '/^#\d+\s+\d+\.\d+\s+(error:[^\n]+)/im',
                static fn (array $m): string =>
                    'Install or build failed: ' . trim($m[1]),
            ],

            // The remote listed its refs, then refused the next request: it is
            // readable, and a private repo is refused before that line (#188).
            'repo-read-interrupted' => [
                '/expected flush after ref listing/i',
                static fn (): string =>
                    'The repository started answering and then stopped partway through the clone. '
                        . 'A private or missing repository is refused before that point, so this is the '
                        . 'git host limiting or dropping requests, not the repository. Deploy again in a few minutes.',
            ],

            'repo-auth-failed' => [
                '/(fatal: could not read Username|Authentication failed|remote: Invalid username or password)/i',
                static fn (): string =>
                    'The repository could not be read. Check that it is public, or that the access token is valid.',
            ],

            'repo-not-found' => [
                '/(fatal: repository .* not found|ERROR: Repository not found)/i',
                static fn (): string =>
                    'The repository was not found. Check the address and whether it is private.',
            ],

            // A COPY/ADD of a path the checkout does not have: a packaging Dockerfile that
            // expects CI to have built `target/` or `dist/` into the context first.
            // Also damselfly's `dotnet publish` output (engine#133).
            'build-context-missing' => [
                '/failed to (?:compute cache key|calculate checksum of ref)[^\n]*?"\/?([^"\n]+)": not found/i',
                static fn (array $m): string =>
                    "The repository's Dockerfile copies `{$m[1]}`, which is not in the repository. It is "
                        . 'produced by a step that has to run before the image is built (usually CI), so the '
                        . 'image cannot be built from a clean checkout. If the repository does have it, '
                        . '.dockerignore excludes it.',
            ],

            // hitobito (engine#116): a rake task requires a gem from a group the
            // Dockerfile's own BUNDLE_WITHOUT leaves out.
            'ruby-gem-not-loaded' => [
                '/LoadError: cannot load such file -- (\S+)/',
                static fn (array $m): string =>
                    "A Ruby build step requires `{$m[1]}`, which is not in the installed bundle "
                        . "(often a gem in a group the Dockerfile's BUNDLE_WITHOUT leaves out). The project's "
                        . 'Dockerfile has to install it or stop requiring it.',
            ],

            // Generic build failure — last resort, still better than the dump.
            'build-step-failed' => [
                '/did not complete successfully: exit code: (\d+)/i',
                static fn (array $m): string =>
                    "A build step failed (exit code {$m[1]}). The full output is in the deploy log.",
            ],
        ];
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

        return strtolower($m[1]) . $m[2];
    }
}
