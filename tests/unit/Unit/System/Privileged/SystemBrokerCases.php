<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\RootFirewall;
use Tests\Support\TestNetwork;

/** Broker requests for the cases in system-cases.json, which the broker's own tests plan. */
final class SystemBrokerCases
{
    public const FILE = '/dockerfiles/root-broker/tests/system-cases.json';

    private const ENGINE_FILE = '/opt/panelalpha/shared-hosting/docker-compose.yml';

    private const STORE = '/opt/panelalpha/backups';

    /**
     * @return list<array{name: string, host: bool, broker: list<string>}>
     */
    public static function all(): array
    {
        $cases = [];
        foreach (self::calls() as $name => [$call, $host]) {
            $cases[] = ['name' => $name, 'host' => $host, 'broker' => (array) $call()];
        }

        return $cases;
    }

    /** @return iterable<string, array{\Closure(): ?list<string>, bool}> */
    private static function calls(): iterable
    {
        yield from self::users();
        yield from self::network();
        yield from self::firewall();
        yield from self::probes();
        yield from self::backupStore();
        yield from self::webserver();
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function users(): iterable
    {
        $u = static fn () => Privileged::users();

        yield 'users.create' => [fn () => $u()->create('alice', '/home/alice'), true];
        yield 'users.delete' => [fn () => $u()->delete('alice'), true];
        yield 'users.uid' => [fn () => $u()->uid('alice'), true];
        yield 'users.gid' => [fn () => $u()->gid('alice'), true];
        yield 'users.setQuota' => [fn () => $u()->setQuota('alice', 1048576, 50000, '/'), true];
        yield 'users.setQuota, no limit' => [fn () => $u()->setQuota('alice', 0, 0, '/home'), true];
        yield 'users.hostingAccounts' => [fn () => $u()->hostingAccounts(), true];
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function network(): iterable
    {
        $n = static fn () => Privileged::network();

        yield 'network.defaultRoute' => [fn () => $n()->defaultRoute(4), true];
        yield 'network.defaultRoute, IPv6' => [fn () => $n()->defaultRoute(6), true];
        yield 'network.addresses' => [fn () => $n()->addresses(), true];
        yield 'network.addressDetails' => [fn () => $n()->addressDetails(), true];
        yield 'network.interfaceAddresses' => [fn () => $n()->interfaceAddresses('eth0'), true];
        yield 'network.route' => [fn () => $n()->route(TestNetwork::public(0), 24, 4), true];
        yield 'network.route, IPv6' => [fn () => $n()->route('2001:db8::', 64, 6), true];
        yield 'network.addRoute' => [fn () => $n()->addRoute(TestNetwork::public(0), 24, TestNetwork::public2(1), 'eth0'), true];
        yield 'network.deleteRoute' => [fn () => $n()->deleteRoute('2001:db8:1::', 48, 'fe80::1', 'vmbr0.100'), true];
        yield 'network.addAddress' => [fn () => $n()->addAddress(TestNetwork::public(7), 32, 'ens3'), true];
        yield 'network.deleteAddress' => [fn () => $n()->deleteAddress('2001:db8::7', 128, 'ens3'), true];
        yield 'network.restartNetworking' => [fn () => $n()->restartNetworking(), true];
        yield 'network.publicAddressLookup' => [fn () => $n()->publicAddressLookup(TestNetwork::private(5), 'https://icanhazip.com?local_ip='.TestNetwork::private(5), 10), true];
        yield 'network.listeningSockets' => [fn () => $n()->listeningSockets('tcp'), true];
        yield 'network.listeningSockets, udp' => [fn () => $n()->listeningSockets('udp'), true];
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function firewall(): iterable
    {
        $f = static fn () => Privileged::firewall();
        [$trusted, $apiTrusted] = RootFirewall::TRUSTED_LISTS;

        yield 'firewall.version' => [fn () => $f()->version(), true];
        yield 'firewall.status' => [fn () => $f()->status(), true];
        yield 'firewall.ufw, enable' => [fn () => $f()->ufw(['--force', 'enable']), true];
        yield 'firewall.ufw, reload' => [fn () => $f()->ufw(['reload']), true];
        yield 'firewall.ufw, disable' => [fn () => $f()->ufw(['disable']), true];
        yield 'firewall.ufw, a rule' => [
            fn () => $f()->ufw(['allow', 'in', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '8080,8443:8450', 'comment', 'panelalpha: web "x"']),
            true,
        ];
        yield 'firewall.ufw, a ban first' => [fn () => $f()->ufw(['route', 'prepend', 'deny', 'from', TestNetwork::net('PUBLIC'), 'to', 'any']), true];
        yield 'firewall.ufw, a delete' => [fn () => $f()->ufw(['delete', 'allow', 'out', 'from', 'any', 'to', '2001:db8::1']), true];
        yield 'firewall.refusedRules' => [fn () => $f()->refusedRules(), true];
        yield 'firewall.applyTrustedLists' => [fn () => $f()->applyTrustedLists(), true];
        yield 'firewall.rules' => [fn () => $f()->rules(), true];
        yield 'firewall.rules6' => [fn () => $f()->rules6(), true];
        yield 'firewall.readTrustedList' => [fn () => $f()->readTrustedList($trusted), true];
        yield 'firewall.writeTrustedList' => [fn () => $f()->writeTrustedList($trusted, TestNetwork::public(7)." # office 'a'\n".TestNetwork::PRIVATE_10_CIDR."\n"), true];
        yield 'firewall.writeTrustedList, empty' => [fn () => $f()->writeTrustedList($apiTrusted, ''), true];
        yield 'firewall.unban' => [fn () => $f()->unban(TestNetwork::public(9)), true];
        yield 'firewall.ensureLockFile' => [fn () => $f()->ensureLockFile(RootFirewall::LOCKS[1]), true];
        yield 'firewall.snapshotRules' => [fn () => $f()->snapshotRules(), true];
        yield 'firewall.restoreRules' => [fn () => $f()->restoreRules(), true];
        yield 'firewall.timezoneOffset' => [fn () => $f()->timezoneOffset(), true];
        yield 'firewall.fail2banLog' => [fn () => $f()->fail2banLog(5000), true];
        yield 'firewall.kernelBlockLog' => [fn () => $f()->kernelBlockLog(5000), true];
        yield 'firewall.ufwLog' => [fn () => $f()->ufwLog(5000), true];
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function probes(): iterable
    {
        $p = static fn () => Privileged::probes();

        yield 'probes.blockDeviceParent' => [fn () => $p()->blockDeviceParent('/dev/sda1'), true];
        yield 'probes.blockDeviceType' => [fn () => $p()->blockDeviceType('/dev/mapper/vg-root'), true];
        yield 'probes.diskFree' => [fn () => $p()->diskFree('/var/tmp'), true];
        yield 'probes.diskFreeInKilobytes' => [fn () => $p()->diskFreeInKilobytes('/var/lib/docker'), true];
        yield 'probes.diskUsageInKilobytes' => [fn () => $p()->diskUsageInKilobytes('/home/alice'), true];
        yield 'probes.sysboxStates' => [fn () => $p()->sysboxStates(), true];
        yield 'probes.gitVersion' => [fn () => $p()->gitVersion(), true];
        yield 'probes.hostImage' => [fn () => $p()->hostImage('panelalpha/php:8.3-cli-bookworm-pab1ff14ca'), true];
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function backupStore(): iterable
    {
        $b = static fn () => Privileged::backupStore();

        yield 'backup-store.removeTree' => [fn () => $b()->removeTree(self::STORE.'/c1/alice/7'), false];
        yield 'backup-store.removeEmptyDirectories' => [fn () => $b()->removeEmptyDirectories(self::STORE), false];
        yield 'backup-store.removeEmptyParents' => [fn () => $b()->removeEmptyParents(self::STORE.'/c1/alice', self::STORE.'/c1'), false];
        yield 'backup-store.makeDirectories' => [fn () => $b()->makeDirectories('/var/www/html/storage/backups/c1/alice'), false];
        yield 'backup-store.giveToWebUser' => [fn () => $b()->giveToWebUser('/home/backups/c1'), false];
        yield 'backup-store.openToWebUser' => [fn () => $b()->openToWebUser('/home/backups/c1'), false];
    }

    /** @return iterable<string, array{\Closure(): ?list<string>, bool}> */
    private static function webserver(): iterable
    {
        $w = static fn () => Privileged::webserver();

        yield 'webserver.queueReload, litespeed' => [fn () => $w()->queueReload(self::ENGINE_FILE, 'openlitespeed', true), true];
        yield 'webserver.queueReload, nginx' => [fn () => $w()->queueReload(self::ENGINE_FILE, 'nginx-proxy', false), true];
        yield 'webserver.queueReload, nginx rebinding' => [fn () => $w()->queueReload(self::ENGINE_FILE, 'nginx', true), true];
        yield 'webserver.queueReload, apache' => [fn () => $w()->queueReload(self::ENGINE_FILE, 'apache', false), true];
    }
}
