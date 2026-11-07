<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which scripts reach php, and the git-hook strip command, pinned per input.
 */
class JsPackageManagerScriptsTest extends TestCase
{
    public static function packages(): array
    {
        return [
            'gulpfile shells out to composer' => [['scripts' => ['build' => 'gulp default']], 'npm run build', ['gulpfile.js' => 'task(\'x\', () => run(\'composer licenses --format=json\'))'], true],
            'gulpfile without php' => [['scripts' => ['build' => 'gulp default']], 'npm run build', ['gulpfile.js' => 'task(\'x\', () => run(\'sass\'))'], false],
            'grunt coffee runs php' => [['scripts' => ['postinstall' => 'grunt']], '', ['Gruntfile.coffee' => 'exec "php bin/setup"'], true],
            'runner not visited' => [['scripts' => ['lint' => 'gulp lint']], 'npm run build', ['gulpfile.js' => 'run(\'composer x\')'], false],
            'workspace member installs with composer' => [['workspaces' => ['packages/a', 'packages/*']], 'vite build', ['packages/a/package.json' => '{"scripts":{"postinstall":"composer install"}}'], true],
            'workspace member without php' => [['workspaces' => ['packages/a']], 'vite build', ['packages/a/package.json' => '{"scripts":{"build":"vite build"}}'], false],
            'glob workspace is not read' => [['workspaces' => ['packages/*']], 'vite build', ['packages/*/package.json' => '{"scripts":{"postinstall":"composer install"}}'], false],
            'nothing readable' => [['scripts' => ['build' => 'gulp']], 'npm run build', [], false],
            'no reader' => [['scripts' => ['build' => 'gulp']], 'npm run build', null, false],
            'pre and post scripts are followed' => [['scripts' => ['assets' => 'vite build', 'postassets' => 'php artisan x']], 'yarn run assets', null, true],
            'a cycle terminates' => [['scripts' => ['a' => 'npm run b', 'b' => 'npm run a']], 'npm run a', null, false],
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<string, string>|null  $files
     */
    #[DataProvider('packages')]
    public function test_scripts_calling_php_are_pinned(array $package, string $build, ?array $files, bool $expected): void
    {
        $read = $files === null ? null : static fn (string $file): ?string => $files[$file] ?? null;

        $this->assertSame($expected, JsPackageManager::scriptsCallPhp($package, $build, $read));
    }

    public static function stripCommands(): array
    {
        return [
            'npm before the source' => ['npm', false, 'timeout 30 node -e \'const fs=require("fs");const p=JSON.parse(fs.readFileSync("package.json","utf8"));if(!p.scripts)process.exit(0);const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;const local=/(?:^|[\\s;&|(])(?:node|bash|sh|zsh|dash|bun|deno|ts-node|tsx)\\s+(?:\\.\\/)?((?:[\\w.-]+\\/)*[\\w.-]+\\.(?:cjs|mjs|js|ts|sh))\\b/gi;const localHit=s=>{local.lastIndex=0;let m;while((m=local.exec(s))!==null){if(m[1].toLowerCase().indexOf("node_modules/")!==0)return true;}return false;};let removed=false;for(const k of["preinstall","install","postinstall","prepare"]){const s=p.scripts[k];if(typeof s!=="string")continue;if(re.test(s)||localHit(s)){delete p.scripts[k];removed=true;}}if(removed)fs.writeFileSync("package.json",JSON.stringify(p));\' || true'],
            'npm with the source' => ['npm', true, 'timeout 30 node -e \'const fs=require("fs");const p=JSON.parse(fs.readFileSync("package.json","utf8"));if(!p.scripts)process.exit(0);const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;let removed=false;for(const k of["preinstall","install","postinstall","prepare"]){const s=p.scripts[k];if(typeof s!=="string")continue;if(re.test(s)){delete p.scripts[k];removed=true;}}if(removed)fs.writeFileSync("package.json",JSON.stringify(p));\' || true'],
            'bun before the source' => ['bun', false, 'timeout 30 bun -e \'const fs=require("fs");const p=JSON.parse(fs.readFileSync("package.json","utf8"));if(!p.scripts)process.exit(0);const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;const local=/(?:^|[\\s;&|(])(?:node|bash|sh|zsh|dash|bun|deno|ts-node|tsx)\\s+(?:\\.\\/)?((?:[\\w.-]+\\/)*[\\w.-]+\\.(?:cjs|mjs|js|ts|sh))\\b/gi;const localHit=s=>{local.lastIndex=0;let m;while((m=local.exec(s))!==null){if(m[1].toLowerCase().indexOf("node_modules/")!==0)return true;}return false;};let removed=false;for(const k of["preinstall","install","postinstall","prepare"]){const s=p.scripts[k];if(typeof s!=="string")continue;if(re.test(s)||localHit(s)){delete p.scripts[k];removed=true;}}if(removed)fs.writeFileSync("package.json",JSON.stringify(p));\' || true'],
            'bun with the source' => ['bun', true, 'timeout 30 bun -e \'const fs=require("fs");const p=JSON.parse(fs.readFileSync("package.json","utf8"));if(!p.scripts)process.exit(0);const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;let removed=false;for(const k of["preinstall","install","postinstall","prepare"]){const s=p.scripts[k];if(typeof s!=="string")continue;if(re.test(s)){delete p.scripts[k];removed=true;}}if(removed)fs.writeFileSync("package.json",JSON.stringify(p));\' || true'],
            'pnpm before the source' => ['pnpm', false, 'timeout 30 node -e \'const fs=require("fs");const p=JSON.parse(fs.readFileSync("package.json","utf8"));if(!p.scripts)process.exit(0);const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;const local=/(?:^|[\\s;&|(])(?:node|bash|sh|zsh|dash|bun|deno|ts-node|tsx)\\s+(?:\\.\\/)?((?:[\\w.-]+\\/)*[\\w.-]+\\.(?:cjs|mjs|js|ts|sh))\\b/gi;const localHit=s=>{local.lastIndex=0;let m;while((m=local.exec(s))!==null){if(m[1].toLowerCase().indexOf("node_modules/")!==0)return true;}return false;};let removed=false;for(const k of["preinstall","install","postinstall","prepare"]){const s=p.scripts[k];if(typeof s!=="string")continue;if(re.test(s)||localHit(s)){delete p.scripts[k];removed=true;}}if(removed)fs.writeFileSync("package.json",JSON.stringify(p));\' || true'],
        ];
    }

    #[DataProvider('stripCommands')]
    public function test_the_strip_command_is_pinned(string $pm, bool $sourcePresent, string $expected): void
    {
        $this->assertSame($expected, JsPackageManager::dockerfileStripGitHookScriptsCommand($pm, $sourcePresent));
    }
}
