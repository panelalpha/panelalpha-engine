<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

final class SystemBrokerTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    private const ENGINE_FILE = '/opt/panelalpha/shared-hosting/docker-compose.yml';

    private const STORE = '/opt/panelalpha/backups';

    private const TRUSTED = '/etc/fail2ban/panelalpha-ignoreip';

    private const API_TRUSTED = '/etc/fail2ban/panelalpha-api-ignoreip';

    private const TRUSTED_LOCK = '/opt/panelalpha/shared-hosting/data/ufw-trusted.lock';

    /** @return iterable<string, array{\Closure(): ?list<string>, list<string>}> */
    public static function requests(): iterable
    {
        yield 'users.create' => [fn () => Privileged::users()->create('alice', '/home/alice'), self::onHost('users.create', 'alice', '/home/alice')];
        yield 'users.delete' => [fn () => Privileged::users()->delete('alice'), self::onHost('users.delete', 'alice')];
        yield 'users.uid' => [fn () => Privileged::users()->uid('alice'), self::onHost('users.uid', 'alice')];
        yield 'users.gid' => [fn () => Privileged::users()->gid('alice'), self::onHost('users.gid', 'alice')];
        yield 'users.setQuota' => [fn () => Privileged::users()->setQuota('alice', 1048576, 50000, '/'), self::onHost('users.setQuota', 'alice', '1048576', '50000', '/')];
        yield 'users.setQuota, no limit' => [fn () => Privileged::users()->setQuota('alice', 0, 0, '/home'), self::onHost('users.setQuota', 'alice', '0', '0', '/home')];
        yield 'users.hostingAccounts' => [fn () => Privileged::users()->hostingAccounts(), self::onHost('users.hostingAccounts')];
        yield 'network.defaultRoute' => [fn () => Privileged::network()->defaultRoute(4), self::onHost('network.defaultRoute', '4')];
        yield 'network.defaultRoute, IPv6' => [fn () => Privileged::network()->defaultRoute(6), self::onHost('network.defaultRoute', '6')];
        yield 'network.addresses' => [fn () => Privileged::network()->addresses(), self::onHost('network.addresses')];
        yield 'network.addressDetails' => [fn () => Privileged::network()->addressDetails(), self::onHost('network.addressDetails')];
        yield 'network.interfaceAddresses' => [fn () => Privileged::network()->interfaceAddresses('eth0'), self::onHost('network.interfaceAddresses', 'eth0')];
        yield 'network.route' => [fn () => Privileged::network()->route(TestNetwork::public(0), 24, 4), self::onHost('network.route', '4', TestNetwork::net('PUBLIC'))];
        yield 'network.route, IPv6' => [fn () => Privileged::network()->route('2001:db8::', 64, 6), self::onHost('network.route', '6', '2001:db8::/64')];
        yield 'network.addRoute' => [fn () => Privileged::network()->addRoute(TestNetwork::public(0), 24, TestNetwork::public2(1), 'eth0'), self::onHost('network.addRoute', TestNetwork::net('PUBLIC'), TestNetwork::public2(1), 'eth0')];
        yield 'network.deleteRoute' => [fn () => Privileged::network()->deleteRoute('2001:db8:1::', 48, 'fe80::1', 'vmbr0.100'), self::onHost('network.deleteRoute', '2001:db8:1::/48', 'fe80::1', 'vmbr0.100')];
        yield 'network.addAddress' => [fn () => Privileged::network()->addAddress(TestNetwork::public(7), 32, 'ens3'), self::onHost('network.addAddress', TestNetwork::public(7).'/32', 'ens3')];
        yield 'network.deleteAddress' => [fn () => Privileged::network()->deleteAddress('2001:db8::7', 128, 'ens3'), self::onHost('network.deleteAddress', '2001:db8::7/128', 'ens3')];
        yield 'network.restartNetworking' => [fn () => Privileged::network()->restartNetworking(), self::onHost('network.restartNetworking')];
        yield 'network.publicAddressLookup' => [fn () => Privileged::network()->publicAddressLookup(TestNetwork::private(5), 'https://icanhazip.com?local_ip='.TestNetwork::private(5), 10), self::onHost('network.publicAddressLookup', TestNetwork::private(5), 'https://icanhazip.com?local_ip='.TestNetwork::private(5), '10')];
        yield 'network.listeningSockets' => [fn () => Privileged::network()->listeningSockets('tcp'), self::onHost('network.listeningSockets', 'tcp')];
        yield 'network.listeningSockets, udp' => [fn () => Privileged::network()->listeningSockets('udp'), self::onHost('network.listeningSockets', 'udp')];
        yield 'firewall.version' => [fn () => Privileged::firewall()->version(), self::onHost('firewall.version')];
        yield 'firewall.status' => [fn () => Privileged::firewall()->status(), self::onHost('firewall.status')];
        yield 'firewall.ufw, enable' => [fn () => Privileged::firewall()->ufw(['--force', 'enable']), self::onHost('firewall.ufw', '--force', 'enable')];
        yield 'firewall.ufw, reload' => [fn () => Privileged::firewall()->ufw(['reload']), self::onHost('firewall.ufw', 'reload')];
        yield 'firewall.ufw, disable' => [fn () => Privileged::firewall()->ufw(['disable']), self::onHost('firewall.ufw', 'disable')];
        yield 'firewall.ufw, a rule' => [fn () => Privileged::firewall()->ufw(['allow', 'in', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '8080,8443:8450', 'comment', 'panelalpha: web "x"']), self::onHost('firewall.ufw', 'allow', 'in', 'proto', 'tcp', 'from', 'any', 'to', 'any', 'port', '8080,8443:8450', 'comment', 'panelalpha: web "x"')];
        yield 'firewall.ufw, a ban first' => [fn () => Privileged::firewall()->ufw(['route', 'prepend', 'deny', 'from', TestNetwork::net('PUBLIC'), 'to', 'any']), self::onHost('firewall.ufw', 'route', 'prepend', 'deny', 'from', TestNetwork::net('PUBLIC'), 'to', 'any')];
        yield 'firewall.ufw, a delete' => [fn () => Privileged::firewall()->ufw(['delete', 'allow', 'out', 'from', 'any', 'to', '2001:db8::1']), self::onHost('firewall.ufw', 'delete', 'allow', 'out', 'from', 'any', 'to', '2001:db8::1')];
        yield 'firewall.refusedRules' => [fn () => Privileged::firewall()->refusedRules(), self::onHost('firewall.refusedRules')];
        yield 'firewall.applyTrustedLists' => [fn () => Privileged::firewall()->applyTrustedLists(), self::onHost('firewall.applyTrustedLists')];
        yield 'firewall.rules' => [fn () => Privileged::firewall()->rules(), self::onHost('firewall.rules')];
        yield 'firewall.rules6' => [fn () => Privileged::firewall()->rules6(), self::onHost('firewall.rules6')];
        yield 'firewall.readTrustedList' => [fn () => Privileged::firewall()->readTrustedList(self::TRUSTED), self::onHost('firewall.readTrustedList', '/etc/fail2ban/panelalpha-ignoreip')];
        yield 'firewall.writeTrustedList' => [fn () => Privileged::firewall()->writeTrustedList(self::TRUSTED, TestNetwork::public(7)." # office 'a'\n".TestNetwork::PRIVATE_10_CIDR."\n"), self::onHost('firewall.writeTrustedList', '/etc/fail2ban/panelalpha-ignoreip', TestNetwork::public(7)." # office 'a'\n".TestNetwork::PRIVATE_10_CIDR."\n")];
        yield 'firewall.writeTrustedList, empty' => [fn () => Privileged::firewall()->writeTrustedList(self::API_TRUSTED, ''), self::onHost('firewall.writeTrustedList', '/etc/fail2ban/panelalpha-api-ignoreip', '')];
        yield 'firewall.unban' => [fn () => Privileged::firewall()->unban(TestNetwork::public(9)), self::onHost('firewall.unban', TestNetwork::public(9))];
        yield 'firewall.ensureLockFile' => [fn () => Privileged::firewall()->ensureLockFile(self::TRUSTED_LOCK), self::onHost('firewall.ensureLockFile', '/opt/panelalpha/shared-hosting/data/ufw-trusted.lock')];
        yield 'firewall.snapshotRules' => [fn () => Privileged::firewall()->snapshotRules(), self::onHost('firewall.snapshotRules')];
        yield 'firewall.restoreRules' => [fn () => Privileged::firewall()->restoreRules(), self::onHost('firewall.restoreRules')];
        yield 'firewall.timezoneOffset' => [fn () => Privileged::firewall()->timezoneOffset(), self::onHost('firewall.timezoneOffset')];
        yield 'firewall.fail2banLog' => [fn () => Privileged::firewall()->fail2banLog(5000), self::onHost('firewall.fail2banLog', '5000')];
        yield 'firewall.kernelBlockLog' => [fn () => Privileged::firewall()->kernelBlockLog(5000), self::onHost('firewall.kernelBlockLog', '5000')];
        yield 'firewall.ufwLog' => [fn () => Privileged::firewall()->ufwLog(5000), self::onHost('firewall.ufwLog', '5000')];
        yield 'probes.blockDeviceParent' => [fn () => Privileged::probes()->blockDeviceParent('/dev/sda1'), self::onHost('probes.blockDeviceParent', '/dev/sda1')];
        yield 'probes.blockDeviceType' => [fn () => Privileged::probes()->blockDeviceType('/dev/mapper/vg-root'), self::onHost('probes.blockDeviceType', '/dev/mapper/vg-root')];
        yield 'probes.diskFree' => [fn () => Privileged::probes()->diskFree('/var/tmp'), self::onHost('probes.diskFree', '/var/tmp')];
        yield 'probes.diskFreeInKilobytes' => [fn () => Privileged::probes()->diskFreeInKilobytes('/var/lib/docker'), self::onHost('probes.diskFreeInKilobytes', '/var/lib/docker')];
        yield 'probes.diskUsageInKilobytes' => [fn () => Privileged::probes()->diskUsageInKilobytes('/home/alice'), self::onHost('probes.diskUsageInKilobytes', '/home/alice')];
        yield 'probes.sysboxStates' => [fn () => Privileged::probes()->sysboxStates(), self::onHost('probes.sysboxStates')];
        yield 'probes.gitVersion' => [fn () => Privileged::probes()->gitVersion(), self::onHost('probes.gitVersion')];
        yield 'probes.hostImage' => [fn () => Privileged::probes()->hostImage('panelalpha/php:8.3-cli-bookworm-pab1ff14ca'), self::onHost('probes.hostImage', 'panelalpha/php:8.3-cli-bookworm-pab1ff14ca')];
        yield 'backup-store.removeTree' => [fn () => Privileged::backupStore()->removeTree(self::STORE.'/c1/alice/7'), self::inCore('backup-store.removeTree', '/opt/panelalpha/backups/c1/alice/7')];
        yield 'backup-store.removeEmptyDirectories' => [fn () => Privileged::backupStore()->removeEmptyDirectories(self::STORE), self::inCore('backup-store.removeEmptyDirectories', '/opt/panelalpha/backups')];
        yield 'backup-store.removeEmptyParents' => [fn () => Privileged::backupStore()->removeEmptyParents(self::STORE.'/c1/alice', self::STORE.'/c1'), self::inCore('backup-store.removeEmptyParents', '/opt/panelalpha/backups/c1/alice', '/opt/panelalpha/backups/c1')];
        yield 'backup-store.makeDirectories' => [fn () => Privileged::backupStore()->makeDirectories('/var/www/html/storage/backups/c1/alice'), self::inCore('backup-store.makeDirectories', '/var/www/html/storage/backups/c1/alice')];
        yield 'backup-store.giveToWebUser' => [fn () => Privileged::backupStore()->giveToWebUser('/home/backups/c1'), self::inCore('backup-store.giveToWebUser', '/home/backups/c1')];
        yield 'backup-store.openToWebUser' => [fn () => Privileged::backupStore()->openToWebUser('/home/backups/c1'), self::inCore('backup-store.openToWebUser', '/home/backups/c1')];
        yield 'webserver.queueReload, litespeed' => [fn () => Privileged::webserver()->queueReload(self::ENGINE_FILE, 'openlitespeed', true), self::onHost('webserver.queueReload', '/opt/panelalpha/shared-hosting/docker-compose.yml', 'litespeed')];
        yield 'webserver.queueReload, nginx' => [fn () => Privileged::webserver()->queueReload(self::ENGINE_FILE, 'nginx-proxy', false), self::onHost('webserver.queueReload', '/opt/panelalpha/shared-hosting/docker-compose.yml', 'nginx')];
        yield 'webserver.queueReload, nginx rebinding' => [fn () => Privileged::webserver()->queueReload(self::ENGINE_FILE, 'nginx', true), self::onHost('webserver.queueReload', '/opt/panelalpha/shared-hosting/docker-compose.yml', 'nginx-rebind')];
        yield 'webserver.queueReload, apache' => [fn () => Privileged::webserver()->queueReload(self::ENGINE_FILE, 'apache', false), self::onHost('webserver.queueReload', '/opt/panelalpha/shared-hosting/docker-compose.yml', 'apache')];
    }

    /**
     * @param  \Closure(): ?list<string>  $command
     * @param  list<string>  $expected
     */
    #[DataProvider('requests')]
    public function test_each_command_asks_the_broker_for_its_action_with_its_values(\Closure $command, array $expected): void
    {
        $this->assertSame($expected, $command());
    }

    public function test_the_cases_the_broker_is_checked_against_are_the_gateways_own(): void
    {
        $file = (string) file_get_contents(dirname(__DIR__, 5).SystemBrokerCases::FILE);

        $cases = json_decode($file, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_map(static fn (array $case): array => array_diff_key($case, ['sudo' => true]), $cases), SystemBrokerCases::all());
        $this->assertCount(iterator_count(self::requests()), SystemBrokerCases::all());
    }

    public function test_a_host_area_runs_in_the_hosts_namespaces_and_the_backup_store_in_cores(): void
    {
        foreach (SystemBrokerCases::all() as $case) {
            $this->assertSame(! str_starts_with($case['name'], 'backup-store.'), $case['host'], $case['name']);
            $this->assertSame([self::CLIENT, ...($case['host'] ? ['--host'] : [])], array_slice($case['broker'], 0, $case['host'] ? 2 : 1), $case['name']);
            $this->assertNotContains('sudo', array_slice($case['broker'], 0, 3), $case['name']);
        }
    }

    public function test_a_webserver_with_no_reload_queues_nothing(): void
    {
        $this->assertNull(Privileged::webserver()->queueReload(self::ENGINE_FILE, 'caddy', false));
    }

    public function test_a_gateway_command_is_moved_into_the_hosts_namespaces_and_a_host_one_is_left_as_it_is(): void
    {
        $restart = Privileged::compose(self::ENGINE_FILE)->restart('sites-http');
        $this->assertSame([self::CLIENT, '--host', ...array_slice($restart, 1)], Privileged::onHost($restart));
        $this->assertSame(Privileged::users()->uid('alice'), Privileged::onHost(Privileged::users()->uid('alice')));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function notTheGateways(): iterable
    {
        yield 'a plain argv' => [['id', '-u', 'alice']];
        yield 'nsenter of its own' => [['nsenter', '--target', '1', '--all', 'sh']];
        yield 'nothing' => [[]];
        yield 'a broker client without an action' => [[self::CLIENT, 'sh']];
    }

    /** @param  list<string>  $command */
    #[DataProvider('notTheGateways')]
    public function test_a_command_the_gateway_did_not_build_never_reaches_the_host(array $command): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::onHost($command);
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function refused(): iterable
    {
        yield 'username that is an option' => [fn () => Privileged::users()->uid('-a')];
        yield 'home that climbs out' => [fn () => Privileged::users()->create('alice', '/home/../etc')];
        yield 'negative quota' => [fn () => Privileged::users()->setQuota('alice', -1, 0, '/')];
        yield 'quota on a relative mount point' => [fn () => Privileged::users()->setQuota('alice', 1, 1, 'home')];
        yield 'IP family 5' => [fn () => Privileged::network()->defaultRoute(5)];
        yield 'interface that is an option' => [fn () => Privileged::network()->interfaceAddresses('-x')];
        yield 'interface with a second command' => [fn () => Privileged::network()->addAddress(TestNetwork::private(5), 32, 'eth0;id')];
        yield 'mask past the family' => [fn () => Privileged::network()->route(TestNetwork::private(0), 33, 4)];
        yield 'IPv6 mask past the family' => [fn () => Privileged::network()->addRoute('2001:db8::', 129, 'fe80::1', 'eth0')];
        yield 'gateway that is a name' => [fn () => Privileged::network()->addRoute(TestNetwork::private(0), 8, 'gw.example', 'eth0')];
        yield 'lookup from IPv6' => [fn () => Privileged::network()->publicAddressLookup('::1', 'https://icanhazip.com', 10)];
        yield 'lookup of a file' => [fn () => Privileged::network()->publicAddressLookup(TestNetwork::private(5), 'file:///etc/shadow', 10)];
        yield 'lookup with a space' => [fn () => Privileged::network()->publicAddressLookup(TestNetwork::private(5), 'https://a.example/ -o /x', 10)];
        yield 'lookup without time' => [fn () => Privileged::network()->publicAddressLookup(TestNetwork::private(5), 'https://a.example', 0)];
        yield 'raw sockets' => [fn () => Privileged::network()->listeningSockets('raw')];
        yield 'ufw reset' => [fn () => Privileged::firewall()->ufw(['--force', 'reset'])];
        yield 'ufw status through ufw()' => [fn () => Privileged::firewall()->ufw(['status'])];
        yield 'ufw rule with a second command' => [fn () => Privileged::firewall()->ufw(['allow', 'from', 'any', 'to', 'any', 'port', '22;id'])];
        yield 'ufw rule from a name' => [fn () => Privileged::firewall()->ufw(['allow', 'from', 'evil.example', 'to', 'any'])];
        yield 'ufw rule with a quote in its comment' => [fn () => Privileged::firewall()->ufw(['allow', 'from', 'any', 'to', 'any', 'comment', "a'b"])];
        yield 'ufw rule with a word left over' => [fn () => Privileged::firewall()->ufw(['allow', 'from', 'any', 'to', 'any', 'extra'])];
        yield 'ufw rule on an interface' => [fn () => Privileged::firewall()->ufw(['allow', 'in', 'on', 'eth0', 'from', 'any', 'to', 'any'])];
        yield 'trusted list elsewhere' => [fn () => Privileged::firewall()->readTrustedList('/etc/shadow')];
        yield 'trusted list with a directive' => [fn () => Privileged::firewall()->writeTrustedList(self::TRUSTED, TestNetwork::public(4)."\nactionban = id\n")];
        yield 'trusted list without its last line break' => [fn () => Privileged::firewall()->writeTrustedList(self::TRUSTED, TestNetwork::public(4))];
        yield 'lock elsewhere' => [fn () => Privileged::firewall()->ensureLockFile('/etc/cron.d/x')];
        yield 'unban of a second command' => [fn () => Privileged::firewall()->unban(TestNetwork::public(4).'; reboot')];
        yield 'no log lines' => [fn () => Privileged::firewall()->fail2banLog(0)];
        yield 'device that climbs out' => [fn () => Privileged::probes()->blockDeviceParent('/dev/../etc/passwd')];
        yield 'device by name' => [fn () => Privileged::probes()->blockDeviceType('sda')];
        yield 'free space of another filesystem' => [fn () => Privileged::probes()->diskFree('/etc')];
        yield 'usage of a relative home' => [fn () => Privileged::probes()->diskUsageInKilobytes('home/alice')];
        yield 'image that is an option' => [fn () => Privileged::probes()->hostImage('--help')];
        yield 'store at /home' => [fn () => Privileged::backupStore()->giveToWebUser('/home/')];
        yield 'store root removed' => [fn () => Privileged::backupStore()->removeTree('/opt/panelalpha')];
        yield 'store root emptied' => [fn () => Privileged::backupStore()->removeEmptyDirectories('/opt/panelalpha/')];
        yield 'store root among the parents' => [fn () => Privileged::backupStore()->removeEmptyParents('/home/b/c', '/var/www/html/storage')];
        yield 'store path that climbs out' => [fn () => Privileged::backupStore()->makeDirectories('/home/b/../../etc')];
        yield 'reload of another compose file' => [fn () => Privileged::webserver()->queueReload('/tmp/x.yml', 'nginx', false)];
        yield 'reload of a compose file with a space' => [fn () => Privileged::webserver()->queueReload('/opt/a b/docker-compose.yml', 'nginx', false)];
    }

    /** @param  \Closure(): mixed  $command */
    #[DataProvider('refused')]
    public function test_a_value_the_command_could_misread_is_refused_before_the_broker_is_asked(\Closure $command): void
    {
        $this->expectException(InvalidArgument::class);

        $command();
    }

    /** @return list<string> */
    private static function onHost(string $action, string ...$values): array
    {
        return [self::CLIENT, '--host', $action, '--', ...$values];
    }

    /** @return list<string> */
    private static function inCore(string $action, string ...$values): array
    {
        return [self::CLIENT, $action, '--', ...$values];
    }
}
