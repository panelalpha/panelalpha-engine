<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\EngineAccount;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\TenantEgressGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\HostImageLines;
use Tests\Support\LocalRoot;

/**
 * Getting an image into an account through registries only, run for real against a fake
 * `docker`, pa-root-local and a fake `pa-root`, so each rung of the ladder is exercised.
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
        foreach (['host', 'account', 'cache', 'hollow', 'ghcr'] as $store) {
            mkdir($this->dir . '/state/' . $store, 0700, true);
        }
        file_put_contents($this->dir . '/bin/docker', sprintf(<<<'SH'
#!/bin/bash
# State lives in $STATE: registry-running, remote-ok, host-remote-ok, push-fails (a count) and
# host/, account/, cache/ holding one file per image. Every call is logged.
echo "$*" >> "$STATE/calls"
key() { printf '%%s' "$1" | tr '/:' '__'; }
if [ "$1" = inspect ]; then
  # inspect --format FORMAT -- NAME; down-NAME stops just that container.
  [ -e "$STATE/registry-running" ] && [ ! -e "$STATE/down-$5" ] && echo true || echo false; exit 0
fi
case "$1 $2" in
  "image inspect") for ref; do :; done; [ -e "$STATE/host/$(key "$ref")" ]; exit ;;
esac
if [ "$1" = tag ]; then exit 0; fi
if [ "$1" = pull ]; then
  # The host's own pull, pull -q -- REF: host-remote-ok says its registry answers.
  [ -e "$STATE/host-remote-ok" ] || { echo "pull access denied for $4" >&2; exit 1; }
  touch "$STATE/host/$(key "$4")"; exit 0
fi
if [ "$1" = build ]; then
  while [ $# -gt 0 ]; do [ "$1" = -t ] && t="$2"; shift; done
  cat >/dev/null; touch "$STATE/host/$(key "$t")"; exit 0
fi
if [ "$1" = push ]; then
  n=$(cat "$STATE/push-fails" 2>/dev/null || echo 0)
  if [ "$n" -gt 0 ]; then echo $((n - 1)) > "$STATE/push-fails"; echo "push refused" >&2; exit 1; fi
  ref="$4"; touch "$STATE/cache/$(key "${ref#%s/}")"; exit 0
fi
echo "unexpected docker call: $*" >&2; exit 99
SH, DindImageStore::HOST_CACHE_REGISTRY));
        chmod($this->dir . '/bin/docker', 0755);
        file_put_contents($this->dir . '/bin/pa-root', <<<'SH'
#!/bin/bash
# The account's own daemon: account-docker.ACTION -- USER VALUE..., against $STATE/account/.
# remote-id is what a public pull leaves in an image's file, which `images` prints as its Id.
echo "$*" >> "$STATE/calls"
key() { printf '%s' "$1" | tr '/:' '__'; }
action=${1#account-docker.}
shift 3
case "$action" in
  hasImage)
    # A hollow tag (no config blob) has no history.
    [ -e "$STATE/account/$(key "$1")" ] && [ ! -e "$STATE/hollow/$(key "$1")" ] && exit 0
    echo "Error response from daemon: No such image: $1" >&2; exit 1 ;;
  images)
    if [ -e "$STATE/account/$(key "$1")" ]; then printf '[{"Id":"sha256:%s"}]\n' "$(cat "$STATE/account/$(key "$1")")"; else echo '[null]'; fi
    exit 0 ;;
  tag) touch "$STATE/account/$(key "$2")"; exit 0 ;;
  removeImages) exit 0 ;;
  pull)
    ref="$1"
    # A login directory, when given, has to hold the client config the broker reads it from.
    [ -z "$2" ] || [ -e "$2/config.json" ] || { echo "no config at $2" >&2; exit 1; }
    case "$ref" in
      panelalpha-cache-registry:5000/*)
        [ -e "$STATE/registry-running" ] || { echo "connection refused" >&2; exit 1; }
        [ -e "$STATE/cache/$(key "${ref#panelalpha-cache-registry:5000/}")" ] || { echo "manifest unknown" >&2; exit 1; } ;;
      panelalpha-ghcr-proxy:5000/*)
        [ -e "$STATE/ghcr/$(key "${ref#panelalpha-ghcr-proxy:5000/}")" ] || { echo "manifest unknown" >&2; exit 1; } ;;
      *) [ -e "$STATE/remote-ok" ] || { echo "pull access denied for $ref" >&2; exit 1; }
         cat "$STATE/remote-id" > "$STATE/account/$(key "$ref")" 2>/dev/null || touch "$STATE/account/$(key "$ref")"
         rm -f "$STATE/hollow/$(key "$ref")" ;;
    esac
    exit 0 ;;
esac
echo "unexpected account-docker request: $action $*" >&2; exit 99
SH);
        chmod($this->dir . '/bin/pa-root', 0755);
        HostImageLines::install($this->dir . '/bin', 'pa-root-lines');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function account(): EngineAccount
    {
        return new EngineAccount('demo', '/home/demo', '33:33');
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

    private function hostHas(string $image): bool
    {
        return file_exists($this->dir . '/state/host/' . str_replace(['/', ':'], '_', $image));
    }

    /** @return resource $script running in the background, for proc_close() to wait on */
    private function start(string $script)
    {
        $proc = proc_open(
            ['sh', '-c', $this->routed($script)],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PATH' => $this->dir . '/bin:/usr/local/bin:/usr/bin:/bin', 'STATE' => $this->dir . '/state']
        );
        $this->assertIsResource($proc);

        return $proc;
    }

    /** @param  callable(): bool  $condition */
    private function waitFor(callable $condition): bool
    {
        for ($i = 0; $i < 50 && ! $condition(); $i++) {
            usleep(100000);
        }

        return $condition();
    }

    /** @return resource */
    private function lockExclusively(string $path)
    {
        @touch($path);
        $lock = fopen($path, 'r');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX));

        return $lock;
    }

    /** @param  resource  $lock */
    private function unlock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** Broker requests to pa-root-local, and those to the account's daemon, which run no command, to its fake. */
    private function routed(string $script): string
    {
        return HostImageLines::line(str_replace(LocalRoot::CLIENT . ' account-docker.', 'pa-root account-docker.', $script));
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function sh(string $script, string $shell = 'sh'): array
    {
        $proc = proc_open(
            [$shell, '-c', $this->routed($script)],
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
        $this->assertStringContainsString('account-docker.pull -- demo ghcr.io/acme/base:1 ' . $this->dir . '/login', $this->calls());
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
        $this->assertMatchesRegularExpression('~^account-docker\.pull -- demo ghcr\.io/acme/base:1 $~m', $this->calls(), 'pulled with no login');
    }

    public function test_an_operator_image_comes_from_the_cache_registry_even_when_the_account_has_one(): void
    {
        $this->state('registry-running');
        $this->has('account', 'whmcs-dev-base:php8.3');
        $this->has('cache', 'whmcs-dev-base:php8.3');

        [$code, $out] = $this->sh((new DindImageStore())->operatorSeedCommand($this->account(), 'whmcs-dev-base:php8.3'));

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image whmcs-dev-base:php8.3 from the cache registry', $out);
        $this->assertStringContainsString('account-docker.pull -- demo panelalpha-cache-registry:5000/whmcs-dev-base:php8.3', $this->calls());
    }

    public function test_an_operator_image_the_registry_lacks_keeps_the_account_copy(): void
    {
        $this->state('registry-running');
        $this->has('account', 'whmcs-dev-base:php8.3');

        [$code, $out] = $this->sh((new DindImageStore())->operatorSeedCommand($this->account(), 'whmcs-dev-base:php8.3'));

        $this->assertSame(0, $code);
        $this->assertSame('Kept base image whmcs-dev-base:php8.3 already in the account: the cache registry did not have it', $out);
    }

    public function test_an_operator_image_nowhere_fails_and_never_goes_to_docker_hub(): void
    {
        $this->state('registry-running');
        $this->state('remote-ok');

        [$code, , $err] = $this->sh((new DindImageStore())->operatorSeedCommand($this->account(), 'whmcs-dev-base:php8.3'));

        $this->assertNotSame(0, $code);
        $this->assertSame('Could not get whmcs-dev-base:php8.3 into the account', $err);
        $this->assertFalse($this->accountHas('whmcs-dev-base:php8.3'));
    }

    public function test_a_ghcr_image_comes_through_the_pull_through_cache(): void
    {
        $this->state('registry-running');
        $this->state('remote-ok');
        $this->has('ghcr', 'home-assistant/home-assistant:2026.9.4');

        [$code, $out] = $this->seed(false, 'ghcr.io/home-assistant/home-assistant:2026.9.4');

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image ghcr.io/home-assistant/home-assistant:2026.9.4 through the pull-through cache', $out);
        $this->assertStringContainsString('account-docker.pull -- demo panelalpha-ghcr-proxy:5000/home-assistant/home-assistant:2026.9.4', $this->calls());
        $this->assertDoesNotMatchRegularExpression('#pull (-q -- |-- demo )ghcr\.io/#', $this->calls());
        $this->assertTrue($this->accountHas('ghcr.io/home-assistant/home-assistant:2026.9.4'), 'retagged to the name compose runs');
    }

    public function test_the_cache_registry_still_comes_before_the_pull_through_cache(): void
    {
        $this->state('registry-running');
        $this->has('cache', 'ghcr.io/home-assistant/home-assistant:2026.9.4');
        $this->has('ghcr', 'home-assistant/home-assistant:2026.9.4');

        [$code, $out] = $this->seed(false, 'ghcr.io/home-assistant/home-assistant:2026.9.4');

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image ghcr.io/home-assistant/home-assistant:2026.9.4 from the cache registry', $out);
        $this->assertStringNotContainsString('panelalpha-ghcr-proxy', $this->calls());
    }

    public function test_a_stopped_pull_through_cache_falls_back_to_the_accounts_own_pull(): void
    {
        $this->state('registry-running');
        $this->state('down-' . DindImageStore::GHCR_PROXY_CONTAINER);
        $this->state('remote-ok');
        $this->has('ghcr', 'home-assistant/home-assistant:2026.9.4');

        [$code, $out] = $this->seed(false, 'ghcr.io/home-assistant/home-assistant:2026.9.4');

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image ghcr.io/home-assistant/home-assistant:2026.9.4', $out);
        $this->assertStringNotContainsString('pull -- demo panelalpha-ghcr-proxy', $this->calls());
    }

    public function test_a_pull_through_cache_miss_falls_back_to_the_accounts_own_pull(): void
    {
        $this->state('registry-running');
        $this->state('remote-ok');

        [$code, $out] = $this->seed(false, 'ghcr.io/kanbn/kan:1');

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image ghcr.io/kanbn/kan:1', $out);
    }

    public function test_a_docker_hub_image_never_asks_the_pull_through_cache(): void
    {
        $this->state('registry-running');
        $this->state('remote-ok');

        [$code, $out] = $this->seed(false, 'redis:alpine');

        $this->assertSame(0, $code);
        $this->assertSame('Pulled base image redis:alpine', $out);
        $this->assertStringNotContainsString('panelalpha-ghcr-proxy', $this->calls());
    }

    public function test_a_private_ghcr_image_never_goes_through_the_pull_through_cache(): void
    {
        $this->state('registry-running');
        $this->state('remote-ok');
        $this->has('ghcr', 'acme/base:1');
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
        $this->assertStringNotContainsString('panelalpha-ghcr-proxy', $this->calls());
    }

    public function test_only_a_registry_the_engine_caches_has_a_pull_through_name(): void
    {
        $this->assertSame(
            'panelalpha-ghcr-proxy:5000/home-assistant/home-assistant:2026.9.4',
            DindImageStore::pullThroughRef('ghcr.io/home-assistant/home-assistant:2026.9.4')
        );
        $this->assertNull(DindImageStore::pullThroughRef('quay.io/keycloak/keycloak:26'));
        $this->assertNull(DindImageStore::pullThroughRef('redis:alpine'));
        $this->assertNull(DindImageStore::pullThroughRef('ghcr.io'));
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

    public function test_presence_is_asked_of_the_accounts_daemon_as_history_not_inspect(): void
    {
        $this->assertStringStartsWith(
            'if ' . LocalRoot::CLIENT . ' account-docker.hasImage -- demo redis:alpine >/dev/null 2>&1; then :;',
            (new DindImageStore())->seedCommand($this->account(), 'redis:alpine', false)
        );
    }

    public function test_a_refresh_that_pulled_a_new_image_says_so(): void
    {
        $this->state('remote-ok');
        $this->state('remote-id', str_repeat('b', 64));
        $this->has('account', 'node:22-bookworm-slim');

        [$code, $out] = $this->sh((new DindImageStore())->refreshCommand($this->account(), 'node:22-bookworm-slim'));

        $this->assertSame(0, $code);
        $this->assertSame('Updated base image node:22-bookworm-slim', $out);
        $this->assertStringContainsString('account-docker.pull -- demo node:22-bookworm-slim', $this->calls());
    }

    public function test_a_refresh_that_pulled_the_same_image_says_it_is_current(): void
    {
        $this->state('remote-ok');
        $this->has('account', 'node:22-bookworm-slim');

        [$code, $out] = $this->sh((new DindImageStore())->refreshCommand($this->account(), 'node:22-bookworm-slim'));

        $this->assertSame(0, $code);
        $this->assertSame('Base image node:22-bookworm-slim is current', $out);
    }

    public function test_a_refresh_whose_pull_fails_fails_with_the_pulls_error(): void
    {
        $this->has('account', 'node:22-bookworm-slim');

        [$code, $out, $err] = $this->sh((new DindImageStore())->refreshCommand($this->account(), 'node:22-bookworm-slim'));

        $this->assertNotSame(0, $code);
        $this->assertSame('', $out);
        $this->assertSame('pull access denied for node:22-bookworm-slim', $err);
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
        $this->assertStringContainsString('push -q -- ' . DindImageStore::HOST_CACHE_REGISTRY . '/' . self::IMAGE, $this->calls());
        $this->assertStringContainsString('account-docker.pull -- demo panelalpha-cache-registry:5000/' . self::IMAGE, $this->calls());
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
        $this->assertDoesNotMatchRegularExpression('#pull (-q -- |-- demo )' . preg_quote(self::IMAGE, '#') . '#', $this->calls());
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
        $this->assertMatchesRegularExpression('/^pull -q -- ' . preg_quote(self::RAILPACK_BUILDER, '/') . '$/m', $this->calls(), 'the host pulls it');
        $this->assertStringContainsString('push -q -- ' . DindImageStore::HOST_CACHE_REGISTRY . '/' . self::RAILPACK_BUILDER, $this->calls());
        $this->assertStringNotContainsString('account-docker.pull -- demo ' . self::RAILPACK_BUILDER, $this->calls(), 'the account never asks ghcr.io');
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
        $this->assertMatchesRegularExpression('/^pull -q -- ' . preg_quote($frontend, '/') . '$/m', $this->calls());
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
        $this->assertStringContainsString("account-docker.pull -- demo panelalpha-cache-registry:5000/traefik/whoami@{$digest}", $this->calls());
        $this->assertStringContainsString("account-docker.pull -- demo traefik/whoami@{$digest}", $this->calls());
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
        $this->assertStringContainsString("account-docker.pull -- demo ghcr.io/acme/base@{$digest} {$this->dir}/login", $this->calls());
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
        $this->state('registry-running');
        $this->has('host', self::IMAGE);

        [$code] = $this->sh((new DindImageStore())->loadFromHostCommand($this->account(), self::IMAGE));

        $this->assertSame(0, $code);
        $this->assertStringContainsString("\npush -q -- " . DindImageStore::HOST_CACHE_REGISTRY . '/', "\n" . $this->calls());
        $this->assertStringContainsString('account-docker.pull -- demo ' . DindImageStore::CACHE_REGISTRY . '/', $this->calls());
        $this->assertStringNotContainsString('push -q -- ' . DindImageStore::CACHE_REGISTRY . '/', $this->calls());
    }

    public function test_publish_pushes_under_the_images_own_name(): void
    {
        $this->has('host', 'node:22-bookworm-slim');

        [$code] = $this->sh((new DindImageStore())->hostPublishCommand('node:22-bookworm-slim'));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('push -q -- ' . DindImageStore::HOST_CACHE_REGISTRY . '/node:22-bookworm-slim', $this->calls());
        $this->assertFileExists($this->dir . '/state/cache/node_22-bookworm-slim');
    }

    public function test_a_push_waits_while_garbage_collect_holds_the_lock(): void
    {
        $this->state('registry-running');
        $this->has('host', self::IMAGE);
        $push = (new DindImageStore())->loadFromHostCommand($this->account(), self::IMAGE);

        @touch(DindImageStore::pushLock());
        $gc = fopen(DindImageStore::pushLock(), 'r');
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

    /** A garbage-collect holding the push lock holds up a rebuild's push, never its build. */
    public function test_a_rebuild_builds_while_the_push_lock_is_held_and_only_its_push_waits(): void
    {
        $rebuild = (new DindImageStore())->hostRebuildCommand(self::IMAGE, 'FROM php:8.3');

        $gc = $this->lockExclusively(DindImageStore::pushLock());
        try {
            $proc = $this->start($rebuild);
            $this->assertTrue($this->waitFor(fn (): bool => $this->hostHas(self::IMAGE)), 'the build must not wait for the push lock');
            usleep(300000);
            $this->assertStringNotContainsString('push -q', $this->calls(), 'the push must wait for the garbage-collect');
        } finally {
            $this->unlock($gc);
        }

        $this->assertSame(0, proc_close($proc));
        $this->assertStringContainsString('push -q -- ' . DindImageStore::HOST_CACHE_REGISTRY . '/' . self::IMAGE, $this->calls());
    }

    /** Two deploys missing one base: the second waits, finds the first one's image and builds nothing; other images go on. */
    public function test_a_second_rebuild_of_one_image_waits_for_the_first_and_builds_nothing(): void
    {
        $other = 'panelalpha/ruby:3.3-pa2';
        $store = new DindImageStore();

        $first = $this->lockExclusively(DindImageStore::buildLockPath(self::IMAGE));
        try {
            $proc = $this->start($store->hostRebuildCommand(self::IMAGE, 'FROM php:8.3'));
            [$code] = $this->sh($store->hostRebuildCommand($other, 'FROM ruby:3.3'));
            $this->assertSame(0, $code);
            $this->assertTrue($this->hostHas($other), 'another image is not held up');
            usleep(300000);
            $this->assertStringNotContainsString(self::IMAGE, $this->calls(), 'the second rebuild must wait for the first');
            $this->has('host', self::IMAGE);
        } finally {
            $this->unlock($first);
        }

        $this->assertSame(0, proc_close($proc));
        $this->assertStringNotContainsString('-t ' . self::IMAGE, $this->calls());
        $this->assertStringNotContainsString('push -q -- ' . DindImageStore::HOST_CACHE_REGISTRY . '/' . self::IMAGE, $this->calls());
    }

    public function test_a_refresh_rebuilds_even_when_the_tag_exists(): void
    {
        $store = new DindImageStore();

        $this->assertStringStartsWith(LocalRoot::CLIENT . ' lines.inspectImage -- panelalpha/php:x ', $store->hostBuildCommand('panelalpha/php:x', 'FROM php'));
        $this->assertStringStartsWith("printf '%s'", $store->hostBuildCommand('panelalpha/php:x', 'FROM php', true));
        $this->assertSame(0, $this->sh($store->hostBuildCommand('panelalpha/php:x', 'FROM php', true))[0]);
        $this->assertStringContainsString('--pull', $this->calls());
        // FROM a base only this host has: --pull would ask Docker Hub for it.
        unlink($this->dir . '/state/calls');
        $this->assertSame(0, $this->sh($store->hostBuildCommand('panelalpha/build-node:x', 'FROM panelalpha/php:y', false, false))[0]);
        $this->assertStringContainsString('build ', $this->calls());
        $this->assertStringNotContainsString('--pull', $this->calls());
    }

    public function test_garbage_collect_drops_untagged_manifests(): void
    {
        $request = DindImageStore::garbageCollectArgv();

        $this->assertTrue(LocalRoot::isRequest($request));
        $this->assertSame('docker.registryGarbageCollect', LocalRoot::action($request));
        // The accounts' instance mounts the storage read-only; only the writer can collect.
        $this->assertSame([DindImageStore::CACHE_REGISTRY_WRITER_CONTAINER, '/etc/distribution/config.yml'],
            LocalRoot::values($request));
        $this->assertNotContains(DindImageStore::CACHE_REGISTRY_CONTAINER, $request);
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

    /** Every account reads it, so it serves only what anyone may pull from ghcr.io. */
    public function test_the_ghcr_pull_through_cache_is_anonymous_and_on_the_tenant_network(): void
    {
        $services = [];
        foreach (Yaml::parseFile(dirname(__DIR__, 5) . '/docker-compose.yml')['services'] as $service) {
            if (is_string($service['container_name'] ?? null)) {
                $services[$service['container_name']] = $service;
            }
        }
        $proxy = $services[DindImageStore::GHCR_PROXY_CONTAINER];

        $this->assertSame('https://ghcr.io', $proxy['environment']['REGISTRY_PROXY_REMOTEURL']);
        $this->assertArrayNotHasKey('REGISTRY_PROXY_USERNAME', $proxy['environment']);
        $this->assertArrayNotHasKey('REGISTRY_PROXY_PASSWORD', $proxy['environment']);
        $this->assertSame('true', $proxy['environment']['REGISTRY_STORAGE_DELETE_ENABLED']);
        $this->assertSame('', $proxy['environment']['REGISTRY_HTTP_DEBUG_ADDR']);
        $this->assertArrayNotHasKey('ports', $proxy);
        $this->assertSame('${TENANT_NETWORK_PREFIX:-10.200}.0.6', $proxy['networks']['tenants']['ipv4_address']);
        $this->assertContains(DindImageStore::GHCR_PROXY_CONTAINER, TenantEgressGuard::REGISTRY_NAMES);
        $this->assertStringContainsString(
            'GHCR_PROXY="$prefix.0.6"',
            (string) file_get_contents(dirname(__DIR__, 5) . '/scripts/tenant-network-firewall.sh')
        );
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
