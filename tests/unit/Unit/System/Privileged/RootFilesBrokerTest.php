<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\RootFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RootFilesBrokerTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    private const CASES = '/dockerfiles/root-broker/tests/rootfiles-cases.json';

    /** @return iterable<string, array{\Closure(RootFiles): list<string>, list<string>}> */
    public static function actions(): iterable
    {
        yield 'remove' => [fn (RootFiles $f) => $f->remove('/etc/a.conf'), self::asked('remove', '/etc/a.conf')];
        yield 'removeIfExists' => [fn (RootFiles $f) => $f->removeIfExists('/a', '/b'), self::asked('removeIfExists', '/a', '/b')];
        yield 'removeTree' => [fn (RootFiles $f) => $f->removeTree('/tmp/x', '/tmp/my dir'), self::asked('removeTree', '/tmp/x', '/tmp/my dir')];
        yield 'removeEmptyDirectory' => [fn (RootFiles $f) => $f->removeEmptyDirectory('/home/u/in'), self::asked('removeEmptyDirectory', '/home/u/in')];
        yield 'makeDirectory' => [fn (RootFiles $f) => $f->makeDirectory('/d'), self::asked('makeDirectory', '/d')];
        yield 'makeDirectoryWithMode' => [
            fn (RootFiles $f) => $f->makeDirectoryWithMode('700', '/home/u/my dir'),
            self::asked('makeDirectoryWithMode', '700', '/home/u/my dir'),
        ];
        yield 'makeDirectories' => [fn (RootFiles $f) => $f->makeDirectories('/d/e', '/f'), self::asked('makeDirectories', '/d/e', '/f')];
        yield 'makeDirectoriesWithMode' => [
            fn (RootFiles $f) => $f->makeDirectoriesWithMode('0700', '/s', '/t'),
            self::asked('makeDirectoriesWithMode', '0700', '/s', '/t'),
        ];
        yield 'move' => [fn (RootFiles $f) => $f->move('/a', '/home/u/'), self::asked('move', '/a', '/home/u/')];
        yield 'moveOverwriting' => [fn (RootFiles $f) => $f->moveOverwriting('/a', '/b'), self::asked('moveOverwriting', '/a', '/b')];
        yield 'rename' => [fn (RootFiles $f) => $f->rename("/it's", '/b'), self::asked('rename', "/it's", '/b')];
        yield 'copy' => [fn (RootFiles $f) => $f->copy('/a', '/b'), self::asked('copy', '/a', '/b')];
        yield 'replaceWithPreservedCopy' => [
            fn (RootFiles $f) => $f->replaceWithPreservedCopy('/a', '/b'),
            self::asked('replaceWithPreservedCopy', '/a', '/b'),
        ];
        yield 'copyTree' => [fn (RootFiles $f) => $f->copyTree('/a', '/b'), self::asked('copyTree', '/a', '/b')];
        yield 'install' => [
            fn (RootFiles $f) => $f->install('0600', '/tmp/pa-git-ssh-1', '/home/u/my dir/.pa-ssh-key'),
            self::asked('install', '0600', '/tmp/pa-git-ssh-1', '/home/u/my dir/.pa-ssh-key'),
        ];
        yield 'changeMode' => [fn (RootFiles $f) => $f->changeMode('-w', '/a', '/b'), self::asked('changeMode', '-w', '/a', '/b')];
        yield 'changeModeRecursively' => [
            fn (RootFiles $f) => $f->changeModeRecursively('ugo-rwx,u+rwX,go+rX', '/d'),
            self::asked('changeModeRecursively', 'ugo-rwx,u+rwX,go+rX', '/d'),
        ];
        yield 'changeOwner' => [fn (RootFiles $f) => $f->changeOwner('root:root', '/a', '/b'), self::asked('changeOwner', 'root:root', '/a', '/b')];
        yield 'changeOwnerRecursively' => [
            fn (RootFiles $f) => $f->changeOwnerRecursively('www-data:www-data', '/d'),
            self::asked('changeOwnerRecursively', 'www-data:www-data', '/d'),
        ];
        yield 'handTreeToAccount' => [
            fn (RootFiles $f) => $f->handTreeToAccount('/home/u', 1004, 1005, true),
            self::asked('handTreeToAccount', '/home/u', '1004', '1005', '1'),
        ];
        yield 'touch' => [fn (RootFiles $f) => $f->touch('/d/down'), self::asked('touch', '/d/down')];
        yield 'read' => [fn (RootFiles $f) => $f->read('/etc/x.conf'), self::asked('read', '/etc/x.conf')];
        yield 'lastLines' => [fn (RootFiles $f) => $f->lastLines(200, '/var/log/x'), self::asked('lastLines', '200', '/var/log/x')];
        yield 'exists' => [fn (RootFiles $f) => $f->exists('/home/u/my dir'), self::asked('exists', '/home/u/my dir')];
        yield 'isFile' => [fn (RootFiles $f) => $f->isFile('/a b'), self::asked('isFile', '/a b')];
        yield 'isDirectory' => [fn (RootFiles $f) => $f->isDirectory('/a'), self::asked('isDirectory', '/a')];
        yield 'isSymlink' => [fn (RootFiles $f) => $f->isSymlink('/a'), self::asked('isSymlink', '/a')];
        yield 'deviceNumbers' => [fn (RootFiles $f) => $f->deviceNumbers('/s', '/d'), self::asked('deviceNumbers', '/s', '/d')];
        yield 'modificationTime' => [fn (RootFiles $f) => $f->modificationTime('/a'), self::asked('modificationTime', '/a')];
        yield 'permissionBits' => [fn (RootFiles $f) => $f->permissionBits('/a'), self::asked('permissionBits', '/a')];
        yield 'existingRealPaths' => [
            fn (RootFiles $f) => $f->existingRealPaths('/home/u', '/home/u/project/../../v/.env'),
            self::asked('existingRealPaths', '/home/u', '/home/u/project/../../v/.env'),
        ];
        yield 'listNames' => [fn (RootFiles $f) => $f->listNames('/d'), self::asked('listNames', '/d')];
        yield 'listAllNames' => [fn (RootFiles $f) => $f->listAllNames('/d'), self::asked('listAllNames', '/d')];
        yield 'mirror' => [fn (RootFiles $f) => $f->mirror('/src//', '/dst'), self::asked('mirror', '/src//', '/dst')];
        yield 'mirrorByContent' => [fn (RootFiles $f) => $f->mirrorByContent('/src', '/dst/'), self::asked('mirrorByContent', '/src', '/dst/')];
        yield 'mirrorByContentProtecting' => [
            fn (RootFiles $f) => $f->mirrorByContentProtecting('/src', '/dst/', ['/.panelalpha-upload/***']),
            self::asked('mirrorByContentProtecting', '/src', '/dst/', '/.panelalpha-upload/***'),
        ];
        yield 'mirrorExcluding' => [
            fn (RootFiles $f) => $f->mirrorExcluding('/home/a', '/home/my b/.incoming/', ['.incoming', 'docker/']),
            self::asked('mirrorExcluding', '/home/a', '/home/my b/.incoming/', '.incoming', 'docker/'),
        ];
        yield 'mirrorExcluding, nothing left out' => [fn (RootFiles $f) => $f->mirrorExcluding('/a', '/b', []), self::asked('mirrorExcluding', '/a', '/b')];
        yield 'createArchive' => [
            fn (RootFiles $f) => $f->createArchive('/home/u', '/s/home.tar.gz', '.', ['./.incoming', './.pre-push']),
            self::asked('createArchive', '/home/u', '/s/home.tar.gz', '.', './.incoming', './.pre-push'),
        ];
        yield 'createArchive, the project' => [
            fn (RootFiles $f) => $f->createArchive('/home/u', '/s/files.tar.gz', 'project'),
            self::asked('createArchive', '/home/u', '/s/files.tar.gz', 'project'),
        ];
        yield 'extractArchive' => [
            fn (RootFiles $f) => $f->extractArchive('/s/home.tar.gz', '/home/u/.restore 1'),
            self::asked('extractArchive', '/s/home.tar.gz', '/home/u/.restore 1', '0'),
        ];
        yield 'extractArchive, stripping the top directory' => [
            fn (RootFiles $f) => $f->extractArchive('/s/files.tar.gz', '/home/u/project.incoming', 1),
            self::asked('extractArchive', '/s/files.tar.gz', '/home/u/project.incoming', '1'),
        ];
        yield 'diskUsageInMegabytes' => [fn (RootFiles $f) => $f->diskUsageInMegabytes('/h'), self::asked('diskUsageInMegabytes', '/h')];
        yield 'diskUsageInBytes' => [fn (RootFiles $f) => $f->diskUsageInBytes('/home/my u'), self::asked('diskUsageInBytes', '/home/my u')];
        yield 'diskFree' => [fn (RootFiles $f) => $f->diskFree('/home'), self::asked('diskFree', '/home')];
        yield 'listEntryTypes' => [fn (RootFiles $f) => $f->listEntryTypes('/t'), self::asked('listEntryTypes', '/t')];
        yield 'relativeFilePaths' => [fn (RootFiles $f) => $f->relativeFilePaths('/c'), self::asked('relativeFilePaths', '/c')];
        yield 'firstEntry' => [fn (RootFiles $f) => $f->firstEntry('/v'), self::asked('firstEntry', '/v')];
        yield 'symlinksIn' => [fn (RootFiles $f) => $f->symlinksIn('/home/u/.incoming'), self::asked('symlinksIn', '/home/u/.incoming')];
        yield 'findInDirectoryIgnoringCase' => [
            fn (RootFiles $f) => $f->findInDirectoryIgnoringCase('/certs', '*.Example.com.crt'),
            self::asked('findInDirectoryIgnoringCase', '/certs', '*.Example.com.crt'),
        ];
        yield 'emptyDirectory' => [fn (RootFiles $f) => $f->emptyDirectory('/home/u/project'), self::asked('emptyDirectory', '/home/u/project')];
        yield 'removeEntriesNamed' => [
            fn (RootFiles $f) => $f->removeEntriesNamed('/home/u', '.project-next*'),
            self::asked('removeEntriesNamed', '/home/u', '.project-next*'),
        ];
        yield 'removeEntriesOlderThan' => [
            fn (RootFiles $f) => $f->removeEntriesOlderThan('/home/.unzip', 1440),
            self::asked('removeEntriesOlderThan', '/home/.unzip', '1440'),
        ];
        yield 'moveEntriesInto' => [
            fn (RootFiles $f) => $f->moveEntriesInto('/home/u/.project-next-1', '/home/u/project'),
            self::asked('moveEntriesInto', '/home/u/.project-next-1', '/home/u/project'),
        ];
        yield 'deleteFilesTwoLevelsDownOlderThan' => [
            fn (RootFiles $f) => $f->deleteFilesTwoLevelsDownOlderThan('/acme', 60),
            self::asked('deleteFilesTwoLevelsDownOlderThan', '/acme', '60'),
        ];
        yield 'staleDirectoriesStartingWith' => [
            fn (RootFiles $f) => $f->staleDirectoriesStartingWith('/home/u/.panelalpha', 'extract-', 1440),
            self::asked('staleDirectoriesStartingWith', '/home/u/.panelalpha', 'extract-', '1440'),
        ];
        yield 'staleFilesStartingWith, one level' => [
            fn (RootFiles $f) => $f->staleFilesStartingWith('/tmp/', 1, 5, 'askpass-', 'ssh-'),
            self::asked('staleFilesStartingWith', '/tmp/', '1', '5', 'askpass-', 'ssh-'),
        ];
        yield 'staleFilesStartingWith, whole filesystem' => [
            fn (RootFiles $f) => $f->staleFilesStartingWith('/home', null, 5, 'askpass-'),
            self::asked('staleFilesStartingWith', '/home', '', '5', 'askpass-'),
        ];
        yield 'containerLogSizes' => [
            fn (RootFiles $f) => $f->containerLogSizes('/home/u/docker/containers'),
            self::asked('containerLogSizes', '/home/u/docker/containers'),
        ];
        yield 'parentsOfDirectoriesTwoLevelsDownNamed' => [
            fn (RootFiles $f) => $f->parentsOfDirectoriesTwoLevelsDownNamed('/home', '.project-aside'),
            self::asked('parentsOfDirectoriesTwoLevelsDownNamed', '/home', '.project-aside'),
        ];
        yield 'nitroServerEntries' => [
            fn (RootFiles $f) => $f->nitroServerEntries('/home/u/project/', 5),
            self::asked('nitroServerEntries', '/home/u/project/', '5'),
        ];
    }

    /**
     * @param  \Closure(RootFiles): list<string>  $command
     * @param  list<string>  $expected
     */
    #[DataProvider('actions')]
    public function test_each_command_asks_the_broker_for_its_action_with_its_values(\Closure $command, array $expected): void
    {
        $this->assertSame($expected, $command(Privileged::files()));
    }

    public function test_a_host_command_asks_for_the_hosts_namespaces(): void
    {
        $this->assertSame(
            [self::CLIENT, '--host', 'files.extractArchive', '--', '/s/a.tgz', '/home/u/in', '1'],
            Privileged::hostFiles()->extractArchive('/s/a.tgz', '/home/u/in', 1)
        );
        $this->assertSame([self::CLIENT, '--host', 'files.diskFree', '--', '/home'], Privileged::hostFiles()->diskFree('/home'));
    }

    /** @return iterable<string, array{\Closure(RootFiles): list<string>}> */
    public static function refusals(): iterable
    {
        yield 'remove, a relative path' => [fn (RootFiles $f) => $f->remove('etc/a.conf')];
        yield 'handTreeToAccount, to root' => [fn (RootFiles $f) => $f->handTreeToAccount('/home/u', 0, 0, false)];
        yield 'handTreeToAccount, a relative root' => [fn (RootFiles $f) => $f->handTreeToAccount('home/u', 1004, 1005, false)];
        yield 'removeIfExists, an option among the paths' => [fn (RootFiles $f) => $f->removeIfExists('/a', '--no-preserve-root')];
        yield 'removeTree, a path climbing out' => [fn (RootFiles $f) => $f->removeTree('/home/u/../v')];
        yield 'removeEmptyDirectory, a path ending in ..' => [fn (RootFiles $f) => $f->removeEmptyDirectory('/home/u/..')];
        yield 'makeDirectory, an option as the path' => [fn (RootFiles $f) => $f->makeDirectory('-m777')];
        yield 'makeDirectoryWithMode, an option as the path' => [fn (RootFiles $f) => $f->makeDirectoryWithMode('700', '-p')];
        yield 'makeDirectoryWithMode, a mode with a second command' => [fn (RootFiles $f) => $f->makeDirectoryWithMode('700;id', '/d')];
        yield 'makeDirectories, a newline in a path' => [fn (RootFiles $f) => $f->makeDirectories("/d\n/etc")];
        yield 'makeDirectoriesWithMode, a mode with a path' => [fn (RootFiles $f) => $f->makeDirectoriesWithMode('0700 /etc', '/s')];
        yield 'makeDirectoriesWithMode, a path climbing out' => [fn (RootFiles $f) => $f->makeDirectoriesWithMode('0700', '/s/../etc')];
        yield 'move, an option as the source' => [fn (RootFiles $f) => $f->move('--target-directory=/etc', '/b')];
        yield 'moveOverwriting, a relative target' => [fn (RootFiles $f) => $f->moveOverwriting('/a', 'b')];
        yield 'rename, a target climbing out' => [fn (RootFiles $f) => $f->rename('/a', '/home/u/../../etc/x')];
        yield 'copy, a NUL in the source' => [fn (RootFiles $f) => $f->copy("/a\0", '/b')];
        yield 'replaceWithPreservedCopy, a target climbing out' => [fn (RootFiles $f) => $f->replaceWithPreservedCopy('/a', '/b/../../etc/passwd')];
        yield 'copyTree, an option as the target' => [fn (RootFiles $f) => $f->copyTree('/a', '-t/etc')];
        yield 'install, an option as the mode' => [fn (RootFiles $f) => $f->install('--owner=root', '/tmp/a', '/b')];
        yield 'install, a target climbing out' => [fn (RootFiles $f) => $f->install('0600', '/tmp/a', '/home/u/../../etc/sudoers')];
        yield 'changeMode, a mode with a second command' => [fn (RootFiles $f) => $f->changeMode('600; id', '/a')];
        yield 'changeMode, an option as the mode' => [fn (RootFiles $f) => $f->changeMode('--reference=/etc/shadow', '/a')];
        yield 'changeModeRecursively, a path climbing out' => [fn (RootFiles $f) => $f->changeModeRecursively('755', '/home/u/..')];
        yield 'changeOwner, an option as the owner' => [fn (RootFiles $f) => $f->changeOwner('--from=root', '/a')];
        yield 'changeOwner, a relative path' => [fn (RootFiles $f) => $f->changeOwner('root:root', 'a')];
        yield 'changeOwnerRecursively, an owner with a space' => [fn (RootFiles $f) => $f->changeOwnerRecursively('root root', '/a')];
        yield 'touch, an option as the path' => [fn (RootFiles $f) => $f->touch('-d')];
        yield 'read, a path climbing out' => [fn (RootFiles $f) => $f->read('/home/u/../../etc/shadow')];
        yield 'lastLines, an option as the path' => [fn (RootFiles $f) => $f->lastLines(10, '-f')];
        yield 'exists, a relative path' => [fn (RootFiles $f) => $f->exists('home/u')];
        yield 'isFile, an option as the path' => [fn (RootFiles $f) => $f->isFile('-e')];
        yield 'isDirectory, a relative path' => [fn (RootFiles $f) => $f->isDirectory('home')];
        yield 'isSymlink, a newline in the path' => [fn (RootFiles $f) => $f->isSymlink("/a\n")];
        yield 'deviceNumbers, a relative path' => [fn (RootFiles $f) => $f->deviceNumbers('/s', 'd')];
        yield 'modificationTime, a path climbing out' => [fn (RootFiles $f) => $f->modificationTime('/a/../b')];
        yield 'permissionBits, an option as the path' => [fn (RootFiles $f) => $f->permissionBits('--printf=%n')];
        yield 'existingRealPaths, an option as a path' => [fn (RootFiles $f) => $f->existingRealPaths('/home/u', '-s')];
        yield 'existingRealPaths, a newline in a path' => [fn (RootFiles $f) => $f->existingRealPaths("/home/u\n/etc")];
        yield 'listNames, an option as the directory' => [fn (RootFiles $f) => $f->listNames('-R')];
        yield 'listAllNames, a path climbing out' => [fn (RootFiles $f) => $f->listAllNames('/home/u/..')];
        yield 'mirror, an option as the source' => [fn (RootFiles $f) => $f->mirror('-e sh -c id', '/dst')];
        yield 'mirrorByContent, a relative target' => [fn (RootFiles $f) => $f->mirrorByContent('/src', 'dst')];
        yield 'mirrorExcluding, an option as a pattern' => [fn (RootFiles $f) => $f->mirrorExcluding('/a', '/b', ['-e sh'])];
        yield 'mirrorExcluding, a relative source' => [fn (RootFiles $f) => $f->mirrorExcluding('a', '/b', [])];
        yield 'createArchive, an option as the entry' => [fn (RootFiles $f) => $f->createArchive('/home/u', '/s/a.tgz', '--to-command=id')];
        yield 'createArchive, a relative archive' => [fn (RootFiles $f) => $f->createArchive('/home/u', 'a.tgz', 'project')];
        yield 'createArchive, an option as a pattern' => [fn (RootFiles $f) => $f->createArchive('/home/u', '/s/a.tgz', '.', ['--checkpoint-action=exec=id'])];
        yield 'createArchive, a host file as the entry' => [fn (RootFiles $f) => $f->createArchive('/home/u', '/s/a.tgz', '/proc/1/root/root/secret')];
        yield 'createArchive, an entry climbing out' => [fn (RootFiles $f) => $f->createArchive('/home/u', '/s/a.tgz', '../v')];
        yield 'createArchive, an entry through a name' => [fn (RootFiles $f) => $f->createArchive('/home/u', '/s/a.tgz', 'link/secret')];
        yield 'createArchive, an absolute pattern' => [fn (RootFiles $f) => $f->createArchive('/home/u', '/s/a.tgz', '.', ['/etc'])];
        yield 'createArchive, a pattern climbing out' => [fn (RootFiles $f) => $f->createArchive('/home/u', '/s/a.tgz', '.', ['./a/../../b'])];
        yield 'extractArchive, a target climbing out' => [fn (RootFiles $f) => $f->extractArchive('/s/a.tgz', '/home/u/../v')];
        yield 'extractArchive, an option as the archive' => [fn (RootFiles $f) => $f->extractArchive('--to-command=id', '/home/u')];
        yield 'extractArchive, negative components' => [fn (RootFiles $f) => $f->extractArchive('/s/a.tgz', '/home/u', -1)];
        yield 'diskUsageInMegabytes, a path climbing out' => [fn (RootFiles $f) => $f->diskUsageInMegabytes('/h/..')];
        yield 'diskUsageInBytes, a path climbing out' => [fn (RootFiles $f) => $f->diskUsageInBytes('/home/u/..')];
        yield 'diskFree, an option as the path' => [fn (RootFiles $f) => $f->diskFree('--total')];
        yield 'symlinksIn, an expression as the directory' => [fn (RootFiles $f) => $f->symlinksIn('-delete')];
        yield 'listEntryTypes, an expression as the directory' => [fn (RootFiles $f) => $f->listEntryTypes('-delete')];
        yield 'relativeFilePaths, a relative directory' => [fn (RootFiles $f) => $f->relativeFilePaths('c')];
        yield 'firstEntry, a path climbing out' => [fn (RootFiles $f) => $f->firstEntry('/v/../..')];
        yield 'findInDirectoryIgnoringCase, an expression as the name' => [fn (RootFiles $f) => $f->findInDirectoryIgnoringCase('/certs', '-exec')];
        yield 'findInDirectoryIgnoringCase, an operator as the name' => [fn (RootFiles $f) => $f->findInDirectoryIgnoringCase('/certs', '(')];
        yield 'findInDirectoryIgnoringCase, a relative directory' => [fn (RootFiles $f) => $f->findInDirectoryIgnoringCase('certs', 'a.pem')];
        yield 'emptyDirectory, an expression as the directory' => [fn (RootFiles $f) => $f->emptyDirectory('(')];
        yield 'removeEntriesNamed, an expression as the pattern' => [fn (RootFiles $f) => $f->removeEntriesNamed('/home/u', '-o')];
        yield 'removeEntriesNamed, a path climbing out' => [fn (RootFiles $f) => $f->removeEntriesNamed('/home/u/..', '.x*')];
        yield 'removeEntriesOlderThan, a relative directory' => [fn (RootFiles $f) => $f->removeEntriesOlderThan('.unzip', 60)];
        yield 'moveEntriesInto, a relative target' => [fn (RootFiles $f) => $f->moveEntriesInto('/home/u/in', 'project')];
        yield 'moveEntriesInto, a source climbing out' => [fn (RootFiles $f) => $f->moveEntriesInto('/home/u/../v', '/home/u/project')];
        yield 'deleteFilesTwoLevelsDownOlderThan, a relative directory' => [fn (RootFiles $f) => $f->deleteFilesTwoLevelsDownOlderThan('acme', 60)];
        yield 'staleDirectoriesStartingWith, an option as the prefix' => [fn (RootFiles $f) => $f->staleDirectoriesStartingWith('/home/u', '-exec', 60)];
        yield 'staleDirectoriesStartingWith, a path climbing out' => [fn (RootFiles $f) => $f->staleDirectoriesStartingWith('/home/u/..', 'x', 60)];
        yield 'staleFilesStartingWith, an option as a prefix' => [fn (RootFiles $f) => $f->staleFilesStartingWith('/home', null, 5, 'askpass-', '-delete')];
        yield 'staleFilesStartingWith, a relative root' => [fn (RootFiles $f) => $f->staleFilesStartingWith('home', null, 5, 'askpass-')];
        yield 'containerLogSizes, a path climbing out' => [fn (RootFiles $f) => $f->containerLogSizes('/home/u/../v/docker')];
        yield 'parentsOfDirectoriesTwoLevelsDownNamed, an operator as the name' => [fn (RootFiles $f) => $f->parentsOfDirectoriesTwoLevelsDownNamed('/home', '!')];
        yield 'parentsOfDirectoriesTwoLevelsDownNamed, a relative directory' => [fn (RootFiles $f) => $f->parentsOfDirectoriesTwoLevelsDownNamed('home', '.x')];
        yield 'nitroServerEntries, an option as the directory' => [fn (RootFiles $f) => $f->nitroServerEntries('-L', 5)];
        yield 'nitroServerEntries, a negative depth' => [fn (RootFiles $f) => $f->nitroServerEntries('/home/u/project', -1)];
        yield 'lastLines, a negative count' => [fn (RootFiles $f) => $f->lastLines(-5, '/opt/panelalpha/log/x')];
        yield 'removeEntriesOlderThan, negative minutes' => [fn (RootFiles $f) => $f->removeEntriesOlderThan('/home/u/x', -5)];
        yield 'deleteFilesTwoLevelsDownOlderThan, negative minutes' => [fn (RootFiles $f) => $f->deleteFilesTwoLevelsDownOlderThan('/opt/x', -5)];
        yield 'staleDirectoriesStartingWith, negative minutes' => [fn (RootFiles $f) => $f->staleDirectoriesStartingWith('/home/u', 'x', -5)];
        yield 'staleFilesStartingWith, a negative depth' => [fn (RootFiles $f) => $f->staleFilesStartingWith('/home', -1, 5, 'askpass-')];
        yield 'staleFilesStartingWith, negative minutes' => [fn (RootFiles $f) => $f->staleFilesStartingWith('/home', null, -5, 'askpass-')];
    }

    /** @param  \Closure(RootFiles): list<string>  $command */
    #[DataProvider('refusals')]
    public function test_a_value_the_command_could_misread_is_refused_before_the_broker_is_asked(\Closure $command): void
    {
        $this->expectException(InvalidArgument::class);

        $command(Privileged::files());
    }

    public function test_a_host_command_refuses_what_the_container_one_does(): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::hostFiles()->move('/home/u/project', '--backup=t');
    }

    public function test_the_cases_the_broker_is_checked_against_are_the_gateways_own(): void
    {
        $cases = json_decode((string) file_get_contents(dirname(__DIR__, 5).self::CASES), true, flags: JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($cases);
        foreach ($cases as $case) {
            $this->assertSame($case['broker'], $this->call($case), $case['method']);
        }
    }

    /**
     * @param  array{method: string, host: bool, args: list<mixed>}  $case
     * @return list<string>
     */
    private function call(array $case): array
    {
        $files = $case['host'] ? Privileged::hostFiles() : Privileged::files();

        return $files->{$case['method']}(...$case['args']);
    }

    /** @return list<string> */
    private static function asked(string $action, string ...$values): array
    {
        return [self::CLIENT, 'files.'.$action, '--', ...$values];
    }
}
