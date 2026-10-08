<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\EngineAccount;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\TenantEgressGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Getting an image into an account through registries only.
 *
 * The commands are run for real against a fake `sudo` and `docker` on PATH,
 * so each rung of the ladder is exercised, not just spelled.
 */
class DindImageStoreTest extends TestCase
{
    private const IMAGE = 'panelalpha/php:8.3-pa1';

    /** Railpack's images: ghcr.io, which registry-proxy does not mirror. */
    private const RAILPACK_BUILDER = 'ghcr.io/railwayapp/railpack-builder:mise-2026.9.15';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dind-image-store-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0700, true);
        foreach (['host', 'account', 'cache', 'hollow'] as $store) {
            mkdir($this->dir . '/state/' . $store, 0700, true);
        }
        file_put_contents($this->dir . '/bin/sudo', "#!/bin/sh\nexec \"\$@\"\n");
        file_put_contents($this->dir . '/bin/docker', <<<'SH'
#!/bin/bash
# State lives in $STATE: registry-running, remote-ok, host-remote-ok, push-fails (a count) and
# host/, account/, cache/ holding one file per image. Every call is logged.
echo "$*" >> "$STATE/calls"
key() { printf '%s' "$1" | tr '/:' '__'; }
if [ "$1" = inspect ]; then
  # inspect -f FORMAT NAME; down-NAME stops just that container.
  [ -e "$STATE/registry-running" ] && [ ! -e "$STATE/down-$4" ] && echo true || echo false; exit 0
fi
if [ "$1" = compose ]; then
  shift 6   # compose -f FILE exec -T SERVICE, then the inner "docker"
  shift
  # A pull with the project's registry login names its client config first.
  if [ "$1" = --config ]; then [ -e "$2/config.json" ] || { echo "no config at $2" >&2; exit 1; }; shift 2; fi
  case "$1 $2" in
    "image inspect") [ -e "$STATE/account/$(key "$4")" ]; exit ;;
    # A hollow tag (no config blob) answers inspect but not history.
    "image history") [ -e "$STATE/account/$(key "$5")" ] && [ ! -e "$STATE/hollow/$(key "$5")" ]; exit ;;
    "image rm") exit 0 ;;
  esac
  if [ "$1" = pull ]; then
    ref="$3"
    case "$ref" in
      panelalpha-cache-registry:5000/*)
        [ -e "$STATE/registry-running" ] || { echo "connection refused" >&2; exit 1; }
        [ -e "$STATE/cache/$(key "${ref#panelalpha-cache-registry:5000/}")" ] || { echo "manifest unknown" >&2; exit 1; } ;;
      *) [ -e "$STATE/remote-ok" ] || { echo "pull access denied for $ref" >&2; exit 1; }
         touch "$STATE/account/$(key "$ref")"; rm -f "$STATE/hollow/$(key "$ref")" ;;
    esac
    exit 0
  fi
  if [ "$1" = tag ]; then touch "$STATE/account/$(key "$3")"; exit 0; fi
  exit 0
fi
case "$1 $2" in
  "image inspect") [ -e "$STATE/host/$(key "$4")" ]; exit ;;
esac
if [ "$1" = tag ]; then exit 0; fi
if [ "$1" = pull ]; then
  # The host's own pull: host-remote-ok says its registry answers.
  [ -e "$STATE/host-remote-ok" ] || { echo "pull access denied for $3" >&2; exit 1; }
  touch "$STATE/host/$(key "$3")"; exit 0
fi
if [ "$1" = push ]; then
  n=$(cat "$STATE/push-fails" 2>/dev/null || echo 0)
  if [ "$n" -gt 0 ]; then echo $((n - 1)) > "$STATE/push-fails"; echo "push refused" >&2; exit 1; fi
  ref="$3"; touch "$STATE/cache/$(key "${ref#127.0.0.1:5000/}")"; exit 0
fi
echo "unexpected docker call: $*" >&2; exit 99
SH);
        chmod($this->dir . '/bin/sudo', 0755);
        chmod($this->dir . '/bin/docker', 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function account(): EngineAccount
    {
        return new EngineAccount('demo', '/home/demo', '33:33', '/users/demo/docker-compose.yml');
    }

    private function state(string $name, string $content = ''): void
    {
        file_put_contents($this->dir . '/state/' . $name, $content);
    }

    private function has(string $store, string $image): void
    {
        touch($this->dir . "/state/{$store}/" . str_replace(['/', ':'], '_', $image));
    }

    private function accountHas(string $image): bool
    {
        return file_exists($this->dir . '/state/account/' . str_replace(['/', ':'], '_', $image));
    }

    private function calls(): string
    {
        return (string) @file_get_contents($this->dir . '/state/calls');
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function sh(string $script, string $shell = 'sh'): array
    {
        $proc = proc_open(
            [$shell, '-c', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin', 'STATE' => $this->dir . '/state']
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        return [proc_close($proc), trim((string) $out), trim((string) $err)];
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function seed(bool $ours = false, string $image = self::IMAGE): array
    {
        return $this->sh((new DindImageStore())->seedCommand($this->account(), $image, $ours));
    }

    public function test_a_private_image_is_pulled_with_the_login_and_never_from_the_cache(): void
    {
        $this->state('registry-running');
        $this->state('remote-ok');
        $this->has('cache', 'ghcr.io/acme/base:1');
        mkdir($this->dir . '/login');
        file_put_contents($this->dir . '/login/config.json', '{"auths":{}}');

        [$code, $out] = $this->sh((new DindImageStore())->seedCommand(
            $this->account(),
            'ghcr.io/acme/base:1',
            false,
            true,
            $this->dir . '/login'
        ));

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image ghcr.io/acme/base:1', $out);
        $this->assertStringContainsString('docker --config ' . $this->dir . '/login pull -q ghcr.io/acme/base:1', $this->calls());
        $this->assertStringNotContainsString('panelalpha-cache-registry', $this->calls());
        $this->assertStringNotContainsString('push', $this->calls());
    }

    public function test_a_private_image_without_a_login_is_still_kept_off_the_shared_registries(): void
    {
        $this->state('registry-running');
        $this->has('cache', 'ghcr.io/acme/base:1');

        [$code, , $err] = $this->sh((new DindImageStore())->seedCommand($this->account(), 'ghcr.io/acme/base:1', false, true));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Could not get', $err);
        $this->assertStringNotContainsString('panelalpha-cache-registry', $this->calls());
        $this->assertStringNotContainsString('--config', $this->calls());
    }

    public function test_an_image_the_account_has_is_left_alone(): void
    {
        $this->has('account', self::IMAGE);

        [$code, $out] = $this->seed();

        $this->assertSame(0, $code);
        $this->assertSame('', $out);
        $this->assertStringNotContainsString('pull', $this->calls());
    }

    public function test_a_hollow_tag_left_by_save_and_load_is_pulled_again(): void
    {
        // Present to `inspect`, missing its config: the account cannot run it.
        $this->state('remote-ok');
        $this->has('account', 'diygod/rsshub:latest');
        $this->has('hollow', 'diygod/rsshub:latest');

        [$code, $out] = $this->seed(false, 'diygod/rsshub:latest');

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image diygod/rsshub:latest', $out);
        $this->assertFileDoesNotExist($this->dir . '/state/hollow/diygod_rsshub_latest', 'the pull repairs it');
    }

    public function test_presence_is_asked_with_history_not_inspect(): void
    {
        $this->assertSame(['docker', 'image', 'history', '-q', '--', 'redis:alpine'], (new DindImageStore())->imageIdArgv('redis:alpine'));
    }

    /**
     * Nothing runs inside the account to patch its registry
     * settings any more. daemon.json is rendered and rewritten on the host
     * ({@see \App\System\Project\Dind\AccountTemplate::daemonJson()}); these
     * three are the host-side argv a live refresh needs instead.
     */
    public function test_the_account_mounts_are_asked_on_the_host(): void
    {
        $this->assertSame(
            ['sudo', 'docker', 'inspect', '--format', '{{json .Mounts}}', '--', 'demo'],
            (new DindImageStore())->hostAccountMountsArgv($this->account())
        );
    }

    public function test_the_account_processes_are_asked_on_the_host(): void
    {
        $this->assertSame(
            ['sudo', 'docker', 'top', 'demo', '-eo', 'pid,comm'],
            (new DindImageStore())->hostAccountProcessesArgv($this->account())
        );
    }

    /** No sudo: execOnHost() already enters the host namespace as root. */
    public function test_the_dockerd_signal_carries_no_sudo_and_no_interpreter(): void
    {
        $this->assertSame(['kill', '-HUP', '4321'], (new DindImageStore())->hostSignalDockerdArgv(4321));
    }

    public function test_the_cache_registry_comes_first(): void
    {
        $this->state('registry-running');
        $this->has('cache', self::IMAGE);
        $this->has('host', self::IMAGE);

        [$code, $out] = $this->seed();

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image ' . self::IMAGE . ' from the cache registry', $out);
        $this->assertStringNotContainsString('push', $this->calls());
        $this->assertTrue($this->accountHas(self::IMAGE), 'retagged to the plain name FROM uses');
    }

    public function test_our_host_built_image_goes_through_the_registry(): void
    {
        $this->state('registry-running');
        $this->has('host', self::IMAGE);

        [$code, $out] = $this->seed(true);

        $this->assertSame(0, $code);
        $this->assertSame('Loaded base image ' . self::IMAGE . ' from the host through the cache registry', $out);
        $this->assertStringContainsString('push -q 127.0.0.1:5000/' . self::IMAGE, $this->calls());
        $this->assertStringContainsString('pull -q panelalpha-cache-registry:5000/' . self::IMAGE, $this->calls());
        $this->assertTrue($this->accountHas(self::IMAGE));
    }

    public function test_one_refused_push_is_retried(): void
    {
        $this->state('registry-running');
        $this->state('push-fails', '1');
        $this->has('host', self::IMAGE);

        [$code, $out] = $this->seed(true);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith('Loaded base image', $out);
        $this->assertSame(2, substr_count($this->calls(), 'push -q'));
    }

    public function test_a_public_image_on_the_host_is_never_pushed(): void
    {
        // The host's copy of a public image is the one that can be incomplete;
        // the account gets it from the registry it came from instead.
        $this->state('registry-running');
        $this->state('remote-ok');
        $this->has('host', 'diygod/rsshub:latest');

        [$code, $out] = $this->seed(false, 'diygod/rsshub:latest');

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image diygod/rsshub:latest', $out);
        $this->assertStringNotContainsString('push', $this->calls());
    }

    public function test_a_stopped_registry_is_not_pushed_to(): void
    {
        $this->state('remote-ok');
        $this->has('host', 'redis:alpine');

        [$code, $out] = $this->seed(false, 'redis:alpine');

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image redis:alpine', $out);
        $this->assertStringNotContainsString('push', $this->calls());
    }

    public function test_our_image_is_never_pulled_from_a_public_registry(): void
    {
        $this->state('remote-ok');

        [$code, , $err] = $this->seed(true);

        $this->assertNotSame(0, $code);
        $this->assertSame(DindImageStore::NOT_BUILT_HERE, $err);
        $this->assertStringNotContainsString('pull -q ' . self::IMAGE, $this->calls());
    }

    /**
     * A shared image neither the cache registry nor the host has is not a
     * failure: the seeder stays quiet and the caller builds it.
     */
    public function test_our_image_the_host_never_built_says_only_that(): void
    {
        $this->state('registry-running');

        [$code, , $err] = $this->seed(true);

        $this->assertNotSame(0, $code);
        $this->assertSame(DindImageStore::NOT_BUILT_HERE, $err);
        $this->assertStringNotContainsString('push', $this->calls());
    }

    public function test_a_failed_pull_is_logged_with_what_the_pull_said(): void
    {
        [$code, , $err] = $this->seed(false, 'redis:alpine');

        $this->assertNotSame(0, $code);
        $this->assertSame(
            'Could not get redis:alpine into the account: pull access denied for redis:alpine',
            ImageSeeding::seedFailure('redis:alpine', $err)
        );
    }

    public function test_a_failed_transfer_from_the_host_is_logged_with_the_push_error(): void
    {
        $this->state('registry-running');
        $this->state('push-fails', '2');
        $this->has('host', self::IMAGE);

        [$code, , $err] = $this->seed(true);

        $this->assertNotSame(0, $code);
        $this->assertSame(
            'Could not get ' . self::IMAGE . ' into the account: push refused push refused',
            ImageSeeding::seedFailure(self::IMAGE, $err)
        );
    }

    public function test_a_failure_from_outside_the_script_is_still_named(): void
    {
        $this->assertSame(
            'Could not get redis:alpine into the account: The process exceeded the timeout of 600 seconds.',
            ImageSeeding::seedFailure('redis:alpine', "The process exceeded the timeout of 600 seconds.\n")
        );
        $this->assertSame('Could not get redis:alpine into the account', ImageSeeding::seedFailure('redis:alpine', ''));
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function seedThroughHost(string $image): array
    {
        return $this->sh((new DindImageStore())->seedThroughHostCommand($this->account(), $image));
    }

    public function test_a_railpack_image_is_fetched_by_the_host_and_handed_over_through_the_registry(): void
    {
        $this->state('registry-running');
        $this->state('host-remote-ok');
        $this->state('remote-ok');

        [$code, $out] = $this->seedThroughHost(self::RAILPACK_BUILDER);

        $this->assertSame(0, $code);
        $this->assertSame('Loaded base image ' . self::RAILPACK_BUILDER . ' from the host through the cache registry', $out);
        $this->assertMatchesRegularExpression('/^pull -q ' . preg_quote(self::RAILPACK_BUILDER, '/') . '$/m', $this->calls(), 'the host pulls it');
        $this->assertStringContainsString('push -q 127.0.0.1:5000/' . self::RAILPACK_BUILDER, $this->calls());
        $this->assertStringNotContainsString('docker pull -q ' . self::RAILPACK_BUILDER, $this->calls(), 'the account never asks ghcr.io');
        $this->assertTrue($this->accountHas(self::RAILPACK_BUILDER));
    }

    public function test_the_next_account_gets_a_railpack_image_from_the_registry_alone(): void
    {
        $this->state('registry-running');
        $this->state('host-remote-ok');
        $this->has('cache', self::RAILPACK_BUILDER);

        [$code, $out] = $this->seedThroughHost(self::RAILPACK_BUILDER);

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image ' . self::RAILPACK_BUILDER . ' from the cache registry', $out);
        $this->assertStringNotContainsString('push', $this->calls());
        $this->assertDoesNotMatchRegularExpression('/^pull /m', $this->calls(), 'nothing is downloaded on the host');
    }

    public function test_a_dated_tag_the_host_holds_is_not_pulled_again(): void
    {
        $this->state('registry-running');
        $this->state('host-remote-ok');
        $this->has('host', self::RAILPACK_BUILDER);

        [$code, $out] = $this->seedThroughHost(self::RAILPACK_BUILDER);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith('Loaded base image', $out);
        $this->assertDoesNotMatchRegularExpression('/^pull /m', $this->calls());
    }

    public function test_a_latest_tag_is_pulled_again_on_the_host_before_it_is_published(): void
    {
        $frontend = 'ghcr.io/railwayapp/railpack-frontend:latest';
        $this->state('registry-running');
        $this->state('host-remote-ok');
        $this->has('host', $frontend);

        [$code, $out] = $this->seedThroughHost($frontend);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith('Loaded base image', $out);
        $this->assertMatchesRegularExpression('/^pull -q ' . preg_quote($frontend, '/') . '$/m', $this->calls());
    }

    public function test_a_latest_tag_the_host_cannot_refresh_is_published_as_it_has_it(): void
    {
        $frontend = 'ghcr.io/railwayapp/railpack-frontend:latest';
        $this->state('registry-running');
        $this->has('host', $frontend);

        [$code, $out] = $this->seedThroughHost($frontend);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith('Loaded base image', $out);
    }

    public function test_the_account_pulls_a_railpack_image_itself_when_the_registry_is_down(): void
    {
        $this->state('host-remote-ok');
        $this->state('remote-ok');

        [$code, $out] = $this->seedThroughHost(self::RAILPACK_BUILDER);

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image ' . self::RAILPACK_BUILDER, $out);
        $this->assertStringNotContainsString('push -q', $this->calls());
    }

    public function test_load_from_host_says_why_when_the_registry_is_down(): void
    {
        $this->has('host', self::IMAGE);

        [$code, , $err] = $this->sh((new DindImageStore())->loadFromHostCommand($this->account(), self::IMAGE));

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString(DindImageStore::REGISTRY_DOWN, $err);
    }

    public function test_a_stopped_writer_fails_the_push_but_not_the_pull(): void
    {
        $this->state('registry-running');
        $this->state('down-' . DindImageStore::CACHE_REGISTRY_WRITER_CONTAINER);
        $this->has('host', self::IMAGE);

        [$code, , $err] = $this->sh((new DindImageStore())->loadFromHostCommand($this->account(), self::IMAGE));
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString(DindImageStore::REGISTRY_DOWN, $err);
        $this->assertStringNotContainsString('push -q', $this->calls());

        $this->has('cache', self::IMAGE);
        [$code, $out] = $this->seed(true);
        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image ' . self::IMAGE . ' from the cache registry', $out);
    }

    public function test_the_parallel_seed_runs_every_safe_image(): void
    {
        $this->state('remote-ok');

        [$code, $out] = $this->sh(
            (new DindImageStore())->parallelImportCommand($this->account(), ['redis:alpine', 'mariadb:11', '-x'], 2),
            'bash'
        );

        $this->assertSame(0, $code);
        $lines = explode("\n", $out);
        sort($lines);
        $this->assertSame(['Pulled base image mariadb:11', 'Pulled base image redis:alpine'], $lines);
    }

    public function test_a_recipe_pinned_image_is_seeded_by_its_digest(): void
    {
        $this->state('registry-running');
        $this->state('remote-ok');
        $digest = 'sha256:' . str_repeat('a', 64);

        [$code, $out] = $this->sh(
            (new DindImageStore())->parallelImportCommand($this->account(), ["traefik/whoami:v1.10.3@{$digest}"], 1),
            'bash'
        );

        $this->assertSame(0, $code);
        $this->assertSame("Pulled base image traefik/whoami@{$digest}", $out);
        $this->assertStringContainsString("pull -q panelalpha-cache-registry:5000/traefik/whoami@{$digest}", $this->calls());
        $this->assertStringContainsString("pull -q traefik/whoami@{$digest}", $this->calls());
        $this->assertStringNotContainsString('v1.10.3', $this->calls(), 'docker ignores a tag beside a digest');
    }

    /** A pinned reference through the private ladder: pulled by digest, with the login. */
    public function test_a_pinned_private_image_is_pulled_by_its_digest_with_the_login(): void
    {
        $this->state('remote-ok');
        mkdir($this->dir . '/login');
        file_put_contents($this->dir . '/login/config.json', '{"auths":{}}');
        $digest = 'sha256:' . str_repeat('b', 64);

        [$code, $out] = $this->sh((new DindImageStore())->seedCommand(
            $this->account(),
            "ghcr.io/acme/base:1@{$digest}",
            false,
            true,
            $this->dir . '/login'
        ));

        $this->assertSame(0, $code);
        $this->assertSame("Pulled base image ghcr.io/acme/base@{$digest}", $out);
        $this->assertStringContainsString("--config {$this->dir}/login pull -q ghcr.io/acme/base@{$digest}", $this->calls());
        $this->assertStringNotContainsString('base:1', $this->calls());
    }

    /** A pinned reference through the host: the host pulls and pushes it by digest. */
    public function test_a_pinned_image_through_the_host_drops_the_tag(): void
    {
        $this->state('registry-running');
        $this->state('host-remote-ok');
        $digest = 'sha256:' . str_repeat('c', 64);

        [$code, $out] = $this->seedThroughHost("ghcr.io/railwayapp/railpack-frontend:v1@{$digest}");

        $this->assertSame(0, $code);
        $this->assertSame("Loaded base image ghcr.io/railwayapp/railpack-frontend@{$digest} from the host through the cache registry", $out);
        $this->assertStringNotContainsString(':v1', $this->calls());
    }

    public function test_nothing_is_saved_or_loaded(): void
    {
        $store = new DindImageStore();
        $all = $store->seedCommand($this->account(), self::IMAGE, true)
            . $store->seedThroughHostCommand($this->account(), self::IMAGE)
            . $store->loadFromHostCommand($this->account(), self::IMAGE)
            . $store->parallelImportCommand($this->account(), ['redis:alpine', 'mariadb:11'], 2);

        $this->assertStringNotContainsString('docker save', $all);
        $this->assertStringNotContainsString('docker load', $all);
        $this->assertStringNotContainsString('curl', $all, 'the probe asks the daemon, not the network');
    }

    public function test_the_host_pushes_to_loopback_and_the_account_pulls_by_name(): void
    {
        $cmd = (new DindImageStore())->loadFromHostCommand($this->account(), self::IMAGE);

        $this->assertStringContainsString("docker push -q '" . DindImageStore::HOST_CACHE_REGISTRY . '/', $cmd);
        $this->assertStringContainsString("docker pull -q '" . DindImageStore::CACHE_REGISTRY . '/', $cmd);
        $this->assertStringNotContainsString("push -q '" . DindImageStore::CACHE_REGISTRY . '/', $cmd);
    }

    public function test_publish_pushes_under_the_images_own_name(): void
    {
        $this->has('host', 'node:22-bookworm-slim');

        [$code] = $this->sh((new DindImageStore())->hostPublishCommand('node:22-bookworm-slim'));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('push -q 127.0.0.1:5000/node:22-bookworm-slim', $this->calls());
        $this->assertFileExists($this->dir . '/state/cache/node_22-bookworm-slim');
    }

    public function test_a_push_waits_while_garbage_collect_holds_the_lock(): void
    {
        $this->state('registry-running');
        $this->has('host', self::IMAGE);
        $push = (new DindImageStore())->loadFromHostCommand($this->account(), self::IMAGE);

        @touch(DindImageStore::PUSH_LOCK);
        $gc = fopen(DindImageStore::PUSH_LOCK, 'r');
        $this->assertTrue(flock($gc, LOCK_EX));
        try {
            [$blocked] = $this->sh('timeout 1 sh -c ' . escapeshellarg($push));
            $this->assertNotSame(0, $blocked, 'the push must not run during a garbage-collect');
            $this->assertStringNotContainsString('push -q', $this->calls());
        } finally {
            flock($gc, LOCK_UN);
            fclose($gc);
        }

        [$code] = $this->sh($push);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('push -q', $this->calls());
    }

    public function test_a_refresh_rebuilds_even_when_the_tag_exists(): void
    {
        $store = new DindImageStore();

        $this->assertStringStartsWith('sudo docker image inspect', $store->hostBuildCommand('panelalpha/php:x', 'FROM php'));
        $this->assertStringStartsWith("printf '%s'", $store->hostBuildCommand('panelalpha/php:x', 'FROM php', true));
        $this->assertStringContainsString('--pull', $store->hostBuildCommand('panelalpha/php:x', 'FROM php', true));
        // FROM a base only this host has: --pull would ask Docker Hub for it.
        $this->assertStringNotContainsString('--pull', $store->hostBuildCommand('panelalpha/build-node:x', 'FROM panelalpha/php:y', false, false));
    }

    public function test_garbage_collect_drops_untagged_manifests(): void
    {
        $argv = DindImageStore::garbageCollectArgv();

        $this->assertContains('--delete-untagged', $argv);
        // The accounts' instance mounts the storage read-only; only the writer can collect.
        $this->assertContains(DindImageStore::CACHE_REGISTRY_WRITER_CONTAINER, $argv);
        $this->assertNotContains(DindImageStore::CACHE_REGISTRY_CONTAINER, $argv);
    }

    /** Accounts pull from this registry by tag, so none of them may write to it. */
    public function test_the_registry_accounts_reach_is_read_only_and_the_writer_is_not_theirs(): void
    {
        $services = [];
        foreach (Yaml::parseFile(dirname(__DIR__, 5) . '/docker-compose.yml')['services'] as $service) {
            if (is_string($service['container_name'] ?? null)) {
                $services[$service['container_name']] = $service;
            }
        }
        $reader = $services[DindImageStore::CACHE_REGISTRY_CONTAINER];
        $writer = $services[DindImageStore::CACHE_REGISTRY_WRITER_CONTAINER];

        $this->assertSame(['enabled' => true], json_decode($reader['environment']['REGISTRY_STORAGE_MAINTENANCE_READONLY'], true));
        $this->assertSame('false', $reader['environment']['REGISTRY_STORAGE_DELETE_ENABLED']);
        $this->assertSame(['cache-registry-data:/var/lib/registry:ro'], $reader['volumes']);
        $this->assertArrayNotHasKey('ports', $reader);

        // On no docker network an account is on, and listening on the host's loopback only.
        $this->assertSame('host', $writer['network_mode']);
        $this->assertArrayNotHasKey('networks', $writer);
        $this->assertArrayNotHasKey('ports', $writer);
        $this->assertSame(DindImageStore::HOST_CACHE_REGISTRY, $writer['environment']['REGISTRY_HTTP_ADDR']);
        $this->assertSame('', $writer['environment']['REGISTRY_HTTP_DEBUG_ADDR']);
        $this->assertSame(['cache-registry-data:/var/lib/registry'], $writer['volumes']);

        $this->assertContains(DindImageStore::CACHE_REGISTRY_CONTAINER, TenantEgressGuard::REGISTRY_NAMES);
        $this->assertNotContains(DindImageStore::CACHE_REGISTRY_WRITER_CONTAINER, TenantEgressGuard::REGISTRY_NAMES);
    }

    public function test_the_probe_is_the_registry_container(): void
    {
        // The name every account daemon trusts is this container's name on the engine network.
        $this->assertStringStartsWith(
            DindImageStore::CACHE_REGISTRY_CONTAINER . ':',
            DindImageStore::CACHE_REGISTRY
        );
    }
}
