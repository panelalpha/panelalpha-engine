<?php

namespace Tests\Integration\System\Project\Dind;

use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountTemplate;
use App\System\Project\Dind\TenantEgressGuard;
use Tests\TestCase;

/** The account's useradd.sh init script, byte for byte, with the cgroup part only when privileged. */
class AccountTemplateInitScriptTest extends TestCase
{
    private const USERADD = <<<'BASH'
# /run is a tmpfs (see the account's compose), so it starts empty on every
# boot. Debian expects the directories its packages declare to be there —
# /run/php owned by www-data, /run/lock, /run/dbus — and nothing else in this
# container recreates them, because there is no systemd to run tmpfiles at
# boot. Cheap and idempotent, so it runs unconditionally.
systemd-tmpfiles --create >/dev/null 2>&1 || true
echo "[init] repopulated /run from tmpfiles.d"
if getent passwd 1234 >/dev/null 2>&1 || getent passwd acme >/dev/null 2>&1; then
  echo "[init] passwd entry already exists for UID=1234 or user=acme, skipping"
else
  echo "[init] adding /etc/passwd entry for UID=1234"
  useradd --uid 1234 --home-dir /home/acme --shell /bin/bash acme
fi
mkdir -p "/home/$(hostname)/docker"
# daemon.json itself is rendered on the host and bind-mounted read-only now;
# nothing in this container writes it any more.
mkdir -p "/home/acme/.docker"
chown 1234:1234 "/home/acme/.docker"
echo "[init] created /home/acme/.docker"
mkdir -p -m 700 "/home/acme/.panelalpha-docker"
BASH;

    private const CGROUP = <<<'BASH'

# cgroup v2 nesting — privileged accounts only.
if [ -f /sys/fs/cgroup/cgroup.controllers ] && [ -w /sys/fs/cgroup ]; then
  if mkdir -p /sys/fs/cgroup/init 2>/dev/null; then
    xargs -rn1 </sys/fs/cgroup/cgroup.procs >/sys/fs/cgroup/init/cgroup.procs 2>/dev/null || true
    sed -e 's/ / +/g' -e 's/^/+/' </sys/fs/cgroup/cgroup.controllers \
      >/sys/fs/cgroup/cgroup.subtree_control 2>/dev/null || true
    echo "[init] delegated cgroup2 controllers to children"
  fi
fi
BASH;

    public function test_a_sysbox_account_gets_the_useradd_script_alone(): void
    {
        config(['env.DIND_RUNTIME' => null, 'env.DIND_EGRESS_GUARD' => false]);

        $this->assertSame(['useradd.sh' => self::USERADD], $this->template()->entrypointInitScripts());
    }

    public function test_a_privileged_account_delegates_its_cgroup_controllers_too(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged', 'env.DIND_EGRESS_GUARD' => false]);

        $this->assertSame(['useradd.sh' => self::USERADD.self::CGROUP], $this->template()->entrypointInitScripts(true));
    }

    public function test_the_egress_guard_follows_the_useradd_script(): void
    {
        config(['env.DIND_RUNTIME' => null, 'env.DIND_EGRESS_GUARD' => true]);

        $scripts = $this->template()->entrypointInitScripts();

        $this->assertSame(['useradd.sh', TenantEgressGuard::FILE], array_keys($scripts));
        $this->assertSame(self::USERADD, $scripts['useradd.sh']);
    }

    private function template(): AccountTemplate
    {
        $model = new User;
        $model->username = 'acme';
        $model->details = ['UID' => 1234, 'GID' => 1234];
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);

        return new AccountTemplate($dind);
    }
}
