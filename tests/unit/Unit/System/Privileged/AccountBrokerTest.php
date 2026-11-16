<?php

namespace Tests\Unit\System\Privileged;

use App\Lib\Helpers\FileStreamWrapper;
use App\System\Privileged;
use App\System\Privileged\AccountCommands;
use App\System\Privileged\AccountFiles;
use App\System\Privileged\InvalidArgument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

final class AccountBrokerTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    private const CASES = '/dockerfiles/root-broker/tests/account-cases.json';

    protected function tearDown(): void
    {
        FileStreamWrapper::unconfine();
        parent::tearDown();
    }

    /** @return iterable<string, array{\Closure(): list<string>, list<string>}> */
    public static function actions(): iterable
    {
        yield 'write' => [
            fn () => Privileged::accountFiles(1004, 1005)->write('/home/u/my site/.user.ini', '0644'),
            self::asked('write', '1004', '1005', '/home/u/my site/.user.ini', '0644'),
        ];
        yield 'makeDirectories' => [
            fn () => Privileged::accountFiles(1004, 1005)->makeDirectories('/home/u/my dir/a/b'),
            self::asked('makeDirectories', '1004', '1005', '/home/u/my dir/a/b'),
        ];
        yield 'removeIfExists' => [
            fn () => Privileged::accountFiles(1004, 1005)->removeIfExists('/home/u/.panelalpha/cloudflared.env', '/home/u/my site/.user.ini'),
            self::asked('removeIfExists', '1004', '1005', '/home/u/.panelalpha/cloudflared.env', '/home/u/my site/.user.ini'),
        ];
        yield 'changeMode' => [
            fn () => Privileged::accountFiles(1004, 1005)->changeMode('700', '/home/u/.panelalpha'),
            self::asked('changeMode', '1004', '1005', '700', '/home/u/.panelalpha'),
        ];
        yield 'changeModeRecursively' => [
            fn () => Privileged::accountFiles(1004, 1005)->changeModeRecursively('u+rwX', '/home/u/my dir'),
            self::asked('changeModeRecursively', '1004', '1005', 'u+rwX', '/home/u/my dir'),
        ];
        yield 'moveOverwriting' => [
            fn () => Privileged::accountFiles(1004, 1005)->moveOverwriting('/home/u/project/.panelalpha-run', '/home/u/.panelalpha/run file'),
            self::asked('moveOverwriting', '1004', '1005', '/home/u/project/.panelalpha-run', '/home/u/.panelalpha/run file'),
        ];
        yield 'replaceWithPreservedCopy' => [
            fn () => Privileged::accountFiles(1004, 1005)->replaceWithPreservedCopy('/home/u/.panelalpha/stash/compose.yml', '/home/u/project/compose.yml'),
            self::asked('replaceWithPreservedCopy', '1004', '1005', '/home/u/.panelalpha/stash/compose.yml', '/home/u/project/compose.yml'),
        ];
        yield 'touch' => [
            fn () => Privileged::accountFiles(1004, 1005)->touch('/home/u/my project/.output/.relocated'),
            self::asked('touch', '1004', '1005', '/home/u/my project/.output/.relocated'),
        ];
        yield 'read' => [
            fn () => Privileged::accountFiles(1004, 1005)->read('/home/u/my dir/.env'),
            self::asked('read', '1004', '1005', '/home/u/my dir/.env'),
        ];
        yield 'listAllNames' => [
            fn () => Privileged::accountFiles(1004, 1005)->listAllNames('/home/u/.restore 9'),
            self::asked('listAllNames', '1004', '1005', '/home/u/.restore 9'),
        ];
        yield 'isSymlink' => [
            fn () => Privileged::accountFiles(1004, 1005)->isSymlink('/home/u/my site/x'),
            self::asked('isSymlink', '1004', '1005', '/home/u/my site/x'),
        ];
        yield 'isRealDirectory' => [
            fn () => Privileged::accountFiles(1004, 1005)->isRealDirectory('/home/u/public html'),
            self::asked('isRealDirectory', '1004', '1005', '/home/u/public html'),
        ];
        yield 'diskUsageInMegabytes' => [
            fn () => Privileged::accountFiles(1004, 1005)->diskUsageInMegabytes('/home/u/', '/home/u/docker'),
            self::asked('diskUsageInMegabytes', '1004', '1005', '/home/u/', '/home/u/docker'),
        ];
        yield 'diskUsageInMegabytes, 2' => [
            fn () => Privileged::accountFiles(1004, 1005)->diskUsageInMegabytes('/home/u/my ftp'),
            self::asked('diskUsageInMegabytes', '1004', '1005', '/home/u/my ftp'),
        ];
        yield 'listEntryTypes' => [
            fn () => Privileged::accountFiles(1004, 1005)->listEntryTypes('/home/u/.panelalpha/archive-extract-1'),
            self::asked('listEntryTypes', '1004', '1005', '/home/u/.panelalpha/archive-extract-1'),
        ];
        yield 'nitroServerEntries' => [
            fn () => Privileged::accountFiles(1004, 1005)->nitroServerEntries('/home/u/my project/', 5),
            self::asked('nitroServerEntries', '1004', '1005', '/home/u/my project/', '5'),
        ];
        yield 'listZipNames' => [
            fn () => Privileged::account()->listZipNames('/var/lib/panelalpha/archive-stage/ab/my archive.zip'),
            self::asked('listZipNames', '/var/lib/panelalpha/archive-stage/ab/my archive.zip'),
        ];
        yield 'listZipEntries' => [
            fn () => Privileged::account()->listZipEntries('/var/lib/panelalpha/archive-stage/ab/my archive.zip'),
            self::asked('listZipEntries', '/var/lib/panelalpha/archive-stage/ab/my archive.zip'),
        ];
        yield 'readZipMember' => [
            fn () => Privileged::account()->readZipMember('/var/lib/panelalpha/archive-stage/ab/my archive.zip', 'dir/my link'),
            self::asked('readZipMember', '/var/lib/panelalpha/archive-stage/ab/my archive.zip', 'dir/my link'),
        ];
        yield 'renameInHome' => [
            fn () => Privileged::account()->renameInHome('/home', '/home/.panelalpha-write-stage/a b', '/home/u/my site/.user.ini'),
            self::asked('renameInHome', '/home', '/home/.panelalpha-write-stage/a b', '/home/u/my site/.user.ini'),
        ];
        yield 'readInHome' => [
            fn () => Privileged::account()->readInHome('/home/u', '/home/u/my dir/.env'),
            self::asked('readInHome', '/home/u', '/home/u/my dir/.env'),
        ];
        yield 'gitOnAccountCheckout' => [
            fn () => Privileged::account()->gitOnAccountCheckout(1004, 1005, '/home/u/my project', ['log', '-1', '--format=%H %ct']),
            self::asked('gitOnAccountCheckout', '1004', '1005', '/home/u/my project', 'log', '-1', '--format=%H %ct'),
        ];
        yield 'gitOnHost' => [
            fn () => Privileged::account()->gitOnHost('u', ['env', 'GIT_TERMINAL_PROMPT=0', 'GIT_ASKPASS=/bin/false', 'SSH_ASKPASS=/bin/false', 'git', '-c', 'safe.directory=/home/u/public html', '-C', '/home/u/public html', 'status', '--porcelain']),
            [self::CLIENT, '--host', 'account.gitOnHost', '--', 'u', 'env', 'GIT_TERMINAL_PROMPT=0', 'GIT_ASKPASS=/bin/false', 'SSH_ASKPASS=/bin/false', 'git', '-c', 'safe.directory=/home/u/public html', '-C', '/home/u/public html', 'status', '--porcelain'],
        ];
        yield 'copyReplacingAsAccount' => [
            fn () => Privileged::account()->copyReplacingAsAccount(1004, 1005, '/home/u/.pre-push/my site/wp-config.php', '/home/u/my site/wp-config.php'),
            self::asked('copyReplacingAsAccount', '1004', '1005', '/home/u/.pre-push/my site/wp-config.php', '/home/u/my site/wp-config.php'),
        ];
        yield 'extractZip' => [
            fn () => Privileged::account()->extractZip(1004, 1005, '/var/lib/panelalpha/archive-stage/ab/archive.zip', '/home/u/.panelalpha/archive-extract 1'),
            self::asked('extractZip', '1004', '1005', '/var/lib/panelalpha/archive-stage/ab/archive.zip', '/home/u/.panelalpha/archive-extract 1'),
        ];
        yield 'extractTar' => [
            fn () => Privileged::account()->extractTar(1004, 1005, '/var/lib/panelalpha/archive-stage/ab/archive.tar.gz', '/home/u/.panelalpha/archive-extract 1'),
            self::asked('extractTar', '1004', '1005', '/var/lib/panelalpha/archive-stage/ab/archive.tar.gz', '/home/u/.panelalpha/archive-extract 1'),
        ];
        yield 'normaliseModes' => [
            fn () => Privileged::account()->normaliseModes(1004, 1005, '/home/u/.panelalpha/archive-extract 1'),
            self::asked('normaliseModes', '1004', '1005', '/home/u/.panelalpha/archive-extract 1'),
        ];
        yield 'readRegularFile' => [
            fn () => Privileged::account()->readRegularFile(1004, 1005, '/home/u/.panelalpha/archives/my app.zip'),
            self::asked('readRegularFile', '1004', '1005', '/home/u/.panelalpha/archives/my app.zip'),
        ];
        yield 'extractTarFromStdin' => [
            fn () => Privileged::account()->extractTarFromStdin(1004, 1005, '/home/u/.restore 9'),
            self::asked('extractTarFromStdin', '1004', '1005', '/home/u/.restore 9'),
        ];
        yield 'mergeTree' => [
            fn () => Privileged::account()->mergeTree(1004, 1005, '/home/u/.restore 9', '/home/u', ['public html', 'mail']),
            self::asked('mergeTree', '1004', '1005', '/home/u/.restore 9', '/home/u', 'public html', 'mail'),
        ];
        yield 'placeFile' => [
            fn () => Privileged::account()->placeFile(1004, 1005, '/home/u', 'my site/wp-content/plugins', '0755', 'config.php'),
            self::asked('placeFile', '1004', '1005', '/home/u', 'my site/wp-content/plugins', '0755', 'config.php'),
        ];
        yield 'removeFileBelow' => [
            fn () => Privileged::account()->removeFileBelow(1004, 1005, '/home/u', 'my site/wp-content', 'config.php'),
            self::asked('removeFileBelow', '1004', '1005', '/home/u', 'my site/wp-content', 'config.php'),
        ];
        yield 'ensureDirectoryBelow' => [
            fn () => Privileged::account()->ensureDirectoryBelow(1004, 1005, '/home/u', '.panelalpha/wordpress migrator', '0700'),
            self::asked('ensureDirectoryBelow', '1004', '1005', '/home/u', '.panelalpha/wordpress migrator', '0700'),
        ];
        yield 'lastLinesOfLog' => [
            fn () => Privileged::account()->lastLinesOfLog(1004, 1005, '/opt/panelalpha/shared-hosting/users/u/log/php/php_error.log', 201),
            self::asked('lastLinesOfLog', '1004', '1005', '/opt/panelalpha/shared-hosting/users/u/log/php/php_error.log', '201'),
        ];
        yield 'ensureLogFile' => [
            fn () => Privileged::account()->ensureLogFile(1004, 1005, '/opt/panelalpha/shared-hosting/users/u/log/php/php_error.log', '1004:1005'),
            self::asked('ensureLogFile', '1004', '1005', '/opt/panelalpha/shared-hosting/users/u/log/php/php_error.log', '1004:1005'),
        ];
        yield 'selfSignedCertificate' => [
            fn () => Privileged::account()->selfSignedCertificate('/opt/panelalpha/shared-hosting/users/u/ssl-certs/my site.key', '/opt/panelalpha/shared-hosting/users/u/ssl-certs/my site.crt', '/C=US/O=PanelAlpha/CN=a.com', '*.a.com'),
            self::asked('selfSignedCertificate', '/opt/panelalpha/shared-hosting/users/u/ssl-certs/my site.key', '/opt/panelalpha/shared-hosting/users/u/ssl-certs/my site.crt', '/C=US/O=PanelAlpha/CN=a.com', '*.a.com'),
        ];
        yield 'makeFolder' => [
            fn () => Privileged::accountFileManager(1004, 1005)->makeFolder('/home/u/my dir/new', true),
            self::asked('makeFolder', '1004', '1005', '/home/u/my dir/new', '1'),
        ];
        yield 'makeFolder, 2' => [
            fn () => Privileged::accountFileManager(1004, 1005)->makeFolder('/home/u/my dir/new/', false),
            self::asked('makeFolder', '1004', '1005', '/home/u/my dir/new/', '0'),
        ];
        yield 'writeCopy' => [
            fn () => Privileged::accountFileManager(1004, 1005)->writeCopy('/tmp/pa-put-abc', '/home/u/my dir/a.txt'),
            self::asked('writeCopy', '1004', '1005', '/tmp/pa-put-abc', '/home/u/my dir/a.txt'),
        ];
        yield 'exists' => [
            fn () => Privileged::accountFileManager(1004, 1005)->exists('/home/u/my dir/link'),
            self::asked('exists', '1004', '1005', '/home/u/my dir/link'),
        ];
        yield 'isFile' => [
            fn () => Privileged::accountFileManager(1004, 1005)->isFile('/home/u/my dir/a.txt'),
            self::asked('isFile', '1004', '1005', '/home/u/my dir/a.txt'),
        ];
        yield 'resolvedFile' => [
            fn () => Privileged::accountFileManager(1004, 1005)->resolvedFile('/home/u/sub/../my app.zip'),
            self::asked('resolvedFile', '1004', '1005', '/home/u/sub/../my app.zip'),
        ];
        yield 'entryFields' => [
            fn () => Privileged::accountFileManager(1004, 1005)->entryFields('/home/u/my dir'),
            self::asked('entryFields', '1004', '1005', '/home/u/my dir'),
        ];
        yield 'listPage' => [
            fn () => Privileged::accountFileManager(1004, 1005)->listPage('/home/u/my dir/', 5000, false),
            self::asked('listPage', '1004', '1005', '/home/u/my dir/', '5000', '0'),
        ];
        yield 'listPage, 2' => [
            fn () => Privileged::accountFileManager(1004, 1005)->listPage('/home/u/', 20, true),
            self::asked('listPage', '1004', '1005', '/home/u/', '20', '1'),
        ];
        yield 'subdirectoryParents' => [
            fn () => Privileged::accountFileManager(1004, 1005)->subdirectoryParents('/home/u/my dir/'),
            self::asked('subdirectoryParents', '1004', '1005', '/home/u/my dir/'),
        ];
        yield 'moveEntry' => [
            fn () => Privileged::accountFileManager(1004, 1005)->moveEntry('/home/u/my dir/a.txt', '/home/u/other dir/'),
            self::asked('moveEntry', '1004', '1005', '/home/u/my dir/a.txt', '/home/u/other dir/'),
        ];
        yield 'copyEntry' => [
            fn () => Privileged::accountFileManager(1004, 1005)->copyEntry('/home/u/my dir', '/home/u/my dir copy'),
            self::asked('copyEntry', '1004', '1005', '/home/u/my dir', '/home/u/my dir copy'),
        ];
        yield 'zip' => [
            fn () => Privileged::accountFileManager(1004, 1005)->zip('/home/u/my.zip', '/home/u/my dir', null, null),
            self::asked('zip', '1004', '1005', '/home/u/my.zip', '/home/u/my dir', '', ''),
        ];
        yield 'zip, 2' => [
            fn () => Privileged::accountFileManager(1004, 1005)->zip('/home/u/my.zip', '/home/u/my dir', 6, '2026-01-01'),
            self::asked('zip', '1004', '1005', '/home/u/my.zip', '/home/u/my dir', '6', '2026-01-01'),
        ];
        yield 'zipFrom' => [
            fn () => Privileged::accountFileManager(1004, 1005)->zipFrom('/home/u/sub dir', '/home/u/my.zip', 'my dir', null, null),
            self::asked('zipFrom', '1004', '1005', '/home/u/sub dir', '/home/u/my.zip', 'my dir', '', ''),
        ];
        yield 'copyArchiveToStage' => [
            fn () => Privileged::accountFileManager(1004, 1005)->copyArchiveToStage('/home/u/my app.zip', '/home/.panelalpha-unzip-stage/ab12/my app.zip'),
            self::asked('copyArchiveToStage', '1004', '1005', '/home/u/my app.zip', '/home/.panelalpha-unzip-stage/ab12/my app.zip'),
        ];
        yield 'unzipInto' => [
            fn () => Privileged::accountFileManager(1004, 1005)->unzipInto('/home/.panelalpha-unzip-stage/ab12/my app.zip', '/home/.panelalpha-unzip-stage/ab12/unpacked'),
            self::asked('unzipInto', '1004', '1005', '/home/.panelalpha-unzip-stage/ab12/my app.zip', '/home/.panelalpha-unzip-stage/ab12/unpacked'),
        ];
        yield 'untarInto' => [
            fn () => Privileged::accountFileManager(1004, 1005)->untarInto('/home/.panelalpha-unzip-stage/ab12/my app.tar.gz', '/home/.panelalpha-unzip-stage/ab12/unpacked'),
            self::asked('untarInto', '1004', '1005', '/home/.panelalpha-unzip-stage/ab12/my app.tar.gz', '/home/.panelalpha-unzip-stage/ab12/unpacked'),
        ];
        yield 'gunzipTo' => [
            fn () => Privileged::accountFileManager(1004, 1005)->gunzipTo('/home/.panelalpha-unzip-stage/ab12/my log.gz', '/home/.panelalpha-unzip-stage/ab12/unpacked/my log'),
            self::asked('gunzipTo', '1004', '1005', '/home/.panelalpha-unzip-stage/ab12/my log.gz', '/home/.panelalpha-unzip-stage/ab12/unpacked/my log'),
        ];
        yield 'moveUnpackedInto' => [
            fn () => Privileged::accountFileManager(1004, 1005)->moveUnpackedInto('/home/.panelalpha-unzip-stage/ab12/unpacked', '/home/u', '/home/u/my dir/'),
            self::asked('moveUnpackedInto', '1004', '1005', '/home/.panelalpha-unzip-stage/ab12/unpacked', '/home/u', '/home/u/my dir/'),
        ];
        yield 'removeEntry' => [
            fn () => Privileged::accountFileManager(1004, 1005)->removeEntry('/home/u/my dir', true),
            self::asked('removeEntry', '1004', '1005', '/home/u/my dir', '1'),
        ];
        yield 'removeEntry, 2' => [
            fn () => Privileged::accountFileManager(1004, 1005)->removeEntry('/home/u/my log.gz', false),
            self::asked('removeEntry', '1004', '1005', '/home/u/my log.gz', '0'),
        ];
        yield 'diskUsageExcluding' => [
            fn () => Privileged::accountFileManager(1004, 1005)->diskUsageExcluding('/home/u/', '/home/u/docker'),
            self::asked('diskUsageExcluding', '1004', '1005', '/home/u/', '/home/u/docker'),
        ];
        yield 'moveEntriesInto' => [
            fn () => Privileged::accountFileManager(1004, 1005)->moveEntriesInto('/home/u/my dir', '/home/u/other dir', true),
            self::asked('moveEntriesInto', '1004', '1005', '/home/u/my dir', '/home/u/other dir', '1'),
        ];
        yield 'moveEntriesInto, 2' => [
            fn () => Privileged::accountFileManager(1004, 1005)->moveEntriesInto('/home/u/my dir', '/home/u/other dir', false),
            self::asked('moveEntriesInto', '1004', '1005', '/home/u/my dir', '/home/u/other dir', '0'),
        ];
        yield 'renameOver' => [
            fn () => Privileged::accountFileManager(1004, 1005)->renameOver('/home/u/my dir/.pa-fetch-ab.part', '/home/u/my dir/a b.zip'),
            self::asked('renameOver', '1004', '1005', '/home/u/my dir/.pa-fetch-ab.part', '/home/u/my dir/a b.zip'),
        ];
        yield 'removeFile' => [
            fn () => Privileged::accountFileManager(1004, 1005)->removeFile('/home/u/my dir/.pa-fetch-ab.part'),
            self::asked('removeFile', '1004', '1005', '/home/u/my dir/.pa-fetch-ab.part'),
        ];
        yield 'download' => [
            fn () => Privileged::accountFileManager(1004, 1005)->download('https://example.com/a%20b.zip', '/home/u/my dir/.pa-fetch-ab.part', 1048576, 'example.com:443:'.TestNetwork::routable(34)),
            self::asked('download', '1004', '1005', 'https://example.com/a%20b.zip', '/home/u/my dir/.pa-fetch-ab.part', '1048576', 'example.com:443:'.TestNetwork::routable(34)),
        ];
        yield 'redirectLocation' => [
            fn () => Privileged::accountFileManager(1004, 1005)->redirectLocation('https://example.com/x', null),
            self::asked('redirectLocation', '1004', '1005', 'https://example.com/x', ''),
        ];
        yield 'setMode' => [
            fn () => Privileged::accountFileManager(1004, 1005)->setMode('/home/u/my dir/a.txt', '0644'),
            self::asked('setMode', '1004', '1005', '/home/u/my dir/a.txt', '0644'),
        ];
        yield 'confine' => [
            fn () => Privileged::accountFileManager(1004, 1005)->confine('/home/u', ['F:/home/u/my dir/a.txt', 'E:/home/u/my link/']),
            self::asked('confine', '1004', '1005', '/home/u', 'F:/home/u/my dir/a.txt', 'E:/home/u/my link/'),
        ];
        yield 'entryType' => [
            fn () => Privileged::accountFileManager(1004, 1005)->entryType('/home/u/my dir/'),
            self::asked('entryType', '1004', '1005', '/home/u/my dir/'),
        ];
        yield 'writeFrom wraps write' => [
            fn () => Privileged::accountFiles(1004, 1005)->writeFrom('/tmp/pa-ini-x', '/home/u/a b/.user.ini', '644'),
            ['bash', '-c', 'exec "${@:2}" < "$1"', 'bash', '/tmp/pa-ini-x', ...self::asked('write', '1004', '1005', '/home/u/a b/.user.ini', '644')],
        ];
        yield 'fileStream, confined' => [fn () => Privileged::account()->fileStream(1004, 1005), self::asked('fileStream', '1004', '1005')];
    }

    /**
     * @param  \Closure(): list<string>  $command
     * @param  list<string>  $expected
     */
    #[DataProvider('actions')]
    public function test_each_command_asks_the_broker_for_its_action_with_the_account_first(\Closure $command, array $expected): void
    {
        $this->assertSame($expected, $command());
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function refusedFileCommands(): iterable
    {
        yield 'uid 0' => [fn () => new AccountFiles(0, 1005)];
        yield 'gid 0' => [fn () => new AccountFiles(1004, 0)];
        yield 'negative uid' => [fn () => new AccountFiles(-1, 1005)];
        yield 'relative path' => [fn () => (new AccountFiles(1004, 1005))->removeIfExists('home/u/a')];
        yield 'path that is an option' => [fn () => (new AccountFiles(1004, 1005))->makeDirectories('-m777')];
        yield 'path climbing out' => [fn () => (new AccountFiles(1004, 1005))->changeMode('700', '/home/u/../v/.panelalpha')];
        yield 'path ending in ..' => [fn () => (new AccountFiles(1004, 1005))->listAllNames('/home/u/..')];
        yield 'path with a line break' => [fn () => (new AccountFiles(1004, 1005))->touch("/home/u/a\nb")];
        yield 'second path climbing out' => [fn () => (new AccountFiles(1004, 1005))->moveOverwriting('/home/u/a', '/home/u/../v/a')];
        yield 'write target climbing out' => [fn () => (new AccountFiles(1004, 1005))->write('/home/u/../v/.user.ini', '0644')];
        yield 'write source that is an option' => [fn () => (new AccountFiles(1004, 1005))->writeFrom('-x', '/home/u/a', '0644')];
        yield 'write mode that is a command' => [fn () => (new AccountFiles(1004, 1005))->write('/home/u/a', '644; id')];
        yield 'mode that is not one' => [fn () => (new AccountFiles(1004, 1005))->changeMode('rwx', '/home/u/a')];
        yield 'excluded path that is an option' => [fn () => (new AccountFiles(1004, 1005))->diskUsageInMegabytes('/home/u', '-L')];
        yield 'find dir that is an expression' => [fn () => (new AccountFiles(1004, 1005))->listEntryTypes('-delete')];
        yield 'nitro entries at a negative depth' => [fn () => (new AccountFiles(1004, 1005))->nitroServerEntries('/home/u/project', -1)];
    }

    /** @param  \Closure(): mixed  $build */
    #[DataProvider('refusedFileCommands')]
    public function test_a_value_an_account_file_command_could_misread_is_refused_before_the_broker_is_asked(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build();
    }

    /** @return iterable<string, array{\Closure(AccountCommands): mixed}> */
    public static function refusedAccountCommands(): iterable
    {
        yield 'relative zip' => [fn (AccountCommands $commands) => $commands->listZipNames('archive.zip')];
        yield 'zip that is an option' => [fn (AccountCommands $commands) => $commands->listZipEntries('-x')];
        yield 'zip member that is an option' => [fn (AccountCommands $commands) => $commands->readZipMember('/s/a.zip', '-d/etc')];
        yield 'zip member with a line break' => [fn (AccountCommands $commands) => $commands->readZipMember('/s/a.zip', "a\nb")];
        yield 'rename target climbing out' => [fn (AccountCommands $commands) => $commands->renameInHome('/home', '/home/.stage/a', '/home/alice/../bob/x')];
        yield 'relative homes dir' => [fn (AccountCommands $commands) => $commands->renameInHome('home', '/home/.stage/a', '/home/alice/x')];
        yield 'read path climbing out' => [fn (AccountCommands $commands) => $commands->readInHome('/home/alice', '/home/alice/../../etc/shadow')];
        yield 'git checkout that is an option' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '--exec-path=/tmp', ['rev-parse', 'HEAD'])];
        yield 'git checkout climbing out' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a/../b', ['rev-parse', 'HEAD'])];
        yield 'git leading option' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a', ['-c', 'core.pager=id', 'log'])];
        yield 'git exec path' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a', ['--exec-path=/tmp', 'log'])];
        yield 'git subcommand that writes' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a', ['config', 'core.fsmonitor', '/tmp/x'])];
        yield 'git subcommand that fetches' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a', ['fetch', '--upload-pack=id'])];
        yield 'git upload-pack' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a', ['log', '--upload-pack=id'])];
        yield 'git option after the subcommand' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a', ['log', '-c', 'core.pager=id'])];
        yield 'git external diff' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a', ['log', '-p', '--ext-diff'])];
        yield 'git signature format' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a', ['log', '-1', '--format=%G?'])];
        yield 'git with nothing to run' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(1004, 1005, '/home/a', [])];
        yield 'git as a negative uid' => [fn (AccountCommands $commands) => $commands->gitOnAccountCheckout(-1, 1005, '/home/a', ['rev-parse', 'HEAD'])];
        yield 'git on the host as a user with a space' => [fn (AccountCommands $commands) => $commands->gitOnHost('alice root', ['env', 'git'])];
        yield 'git on the host with nothing to run' => [fn (AccountCommands $commands) => $commands->gitOnHost('alice', [])];
        yield 'git on the host running an option' => [fn (AccountCommands $commands) => $commands->gitOnHost('alice', ['-i'])];
        yield 'copy as the account, a relative source' => [fn (AccountCommands $commands) => $commands->copyReplacingAsAccount(1004, 1005, 'wp-config.php', '/home/a/wp-config.php')];
        yield 'copy as the account, a target that is an option' => [fn (AccountCommands $commands) => $commands->copyReplacingAsAccount(1004, 1005, '/home/a/.pre-push/wp-config.php', '--target-directory=/etc')];
        yield 'copy as the account, a target climbing out' => [fn (AccountCommands $commands) => $commands->copyReplacingAsAccount(1004, 1005, '/home/a/x', '/home/a/../b/x')];
        yield 'relative key file' => [fn (AccountCommands $commands) => $commands->selfSignedCertificate('a.key', '/c/a.crt', '/CN=a.com', 'a.com')];
        yield 'subject that is an option' => [fn (AccountCommands $commands) => $commands->selfSignedCertificate('/c/a.key', '/c/a.crt', '-config', 'a.com')];
        yield 'domain adding a SAN entry' => [fn (AccountCommands $commands) => $commands->selfSignedCertificate('/c/a.key', '/c/a.crt', '/CN=a.com', 'a.com,IP:'.TestNetwork::public(4))];
        yield 'domain with a space' => [fn (AccountCommands $commands) => $commands->selfSignedCertificate('/c/a.key', '/c/a.crt', '/CN=a.com', 'a.com b')];
        yield 'negative log line count' => [fn (AccountCommands $commands) => $commands->lastLinesOfLog(1004, 1005, '/opt/x/users/u/log/php/error.log', -5)];
    }

    /** @param  \Closure(AccountCommands): mixed  $build */
    #[DataProvider('refusedAccountCommands')]
    public function test_a_value_an_account_command_could_misread_is_refused_before_the_broker_is_asked(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build(new AccountCommands);
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function refusedNamedCommands(): iterable
    {
        yield 'merge entry with a slash' => [fn () => Privileged::account()->mergeTree(1004, 1005, '/home/u/.restore', '/home/u', ['a/b'])];
        yield 'merge entry that climbs' => [fn () => Privileged::account()->mergeTree(1004, 1005, '/home/u/.restore', '/home/u', ['..'])];
        yield 'placed file below an absolute directory' => [fn () => Privileged::account()->placeFile(1004, 1005, '/home/u', '/etc', '0755', 'x')];
        yield 'placed file below a climbing directory' => [fn () => Privileged::account()->placeFile(1004, 1005, '/home/u', 'a/../../v', '0755', 'x')];
        yield 'placed file with a symbolic mode' => [fn () => Privileged::account()->placeFile(1004, 1005, '/home/u', 'a', 'u+x', 'x')];
        yield 'removed file named by a path' => [fn () => Privileged::account()->removeFileBelow(1004, 1005, '/home/u', 'a', 'b/c')];
        yield 'log handed to an owner by name' => [fn () => Privileged::account()->ensureLogFile(1004, 1005, '/opt/x/log', 'root root')];
        yield 'staged archive that is relative' => [fn () => Privileged::account()->extractZip(1004, 1005, 'archive.zip', '/home/u/x')];
        yield 'regular file that climbs' => [fn () => Privileged::account()->readRegularFile(1004, 1005, '/home/u/../v/x.zip')];
        yield 'git on the host as a user that is an option' => [fn () => Privileged::account()->gitOnHost('-s', ['env', 'git'])];
    }

    /** @param  \Closure(): mixed  $build */
    #[DataProvider('refusedNamedCommands')]
    public function test_a_named_command_refuses_a_value_that_could_change_it(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build();
    }

    public function test_the_file_stream_runs_as_the_account_through_the_broker_once_it_is_confined(): void
    {
        FileStreamWrapper::confineToAccount('/home/u', 1004, 1005);

        $this->assertSame([self::CLIENT, 'account.fileStream', '--', '1004', '1005'], $this->streamHelperCommand());
    }

    public function test_the_cases_the_broker_is_checked_against_are_the_gateways_own(): void
    {
        $cases = json_decode((string) file_get_contents(dirname(__DIR__, 5).self::CASES), true, flags: JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($cases);
        foreach ($cases as $case) {
            $this->assertSame($case['broker'], $this->call($case), $case['method']);
        }
    }

    /** @return list<string>|null */
    private function streamHelperCommand(): ?array
    {
        $wrapper = new class extends FileStreamWrapper
        {
            /** @return list<string>|null */
            public function command(): ?array
            {
                return $this->helperCommand();
            }
        };

        return $wrapper->command();
    }

    /**
     * @param  array{class: string, method: string, args: list<mixed>, appended?: list<string>}  $case
     * @return list<string>
     */
    private function call(array $case): array
    {
        $gateway = match ($case['class']) {
            'AccountFiles' => Privileged::accountFiles(1004, 1005),
            'AccountFileManager' => Privileged::accountFileManager(1004, 1005),
            default => Privileged::account(),
        };

        return [...$gateway->{$case['method']}(...$case['args']), ...($case['appended'] ?? [])];
    }

    /** @return list<string> */
    private static function asked(string $action, string ...$values): array
    {
        return [self::CLIENT, 'account.'.$action, '--', ...$values];
    }
}
