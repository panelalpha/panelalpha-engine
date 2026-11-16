<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The host compile script for a JS project, pinned byte for byte.
 */
class HostNodeBuildInnerScriptTest extends TestCase
{
    public static function scripts(): array
    {
        return [
            'npm' => [['npm ci', 'npm run build'], 'export PATH=/tmp/corepack-bin:/tmp/corepack-npm/bin:$PATH && if [ -f /app/package.json ]; then cp /app/package.json /tmp/pa-package.json && { timeout 30 node -e \'const fs=require("fs");const p=JSON.parse(fs.readFileSync("package.json","utf8"));if(!p.scripts)process.exit(0);const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;let removed=false;for(const k of["preinstall","install","postinstall","prepare"]){const s=p.scripts[k];if(typeof s!=="string")continue;if(re.test(s)){delete p.scripts[k];removed=true;}}if(removed)fs.writeFileSync("package.json",JSON.stringify(p));\' || true; }; fi && { pa_install_status=0; if [ -f /app/node_modules/.pa-lock ] && { cmp -s /app/package-lock.json /app/node_modules/.pa-lock || cmp -s /app/pnpm-lock.yaml /app/node_modules/.pa-lock || cmp -s /app/yarn.lock /app/node_modules/.pa-lock || cmp -s /app/bun.lock /app/node_modules/.pa-lock; }; then echo "node_modules cache hit"; else npm ci && { for f in package-lock.json pnpm-lock.yaml yarn.lock bun.lock; do if [ -f "/app/$f" ]; then cp "/app/$f" /app/node_modules/.pa-lock; break; fi; done; true; }; fi || pa_install_status=$?; if [ -f /tmp/pa-package.json ]; then mv /tmp/pa-package.json /app/package.json; fi; if [ "$pa_install_status" -ne 0 ]; then exit $pa_install_status; fi; } && npm run build'],
            'bun with env' => [['HUSKY=0 LEFTHOOK=0 CI=1 bun install', 'bun run build'], 'export PATH=/tmp/corepack-bin:/tmp/corepack-npm/bin:$PATH && if [ -f /app/package.json ]; then cp /app/package.json /tmp/pa-package.json && { timeout 30 bun -e \'const fs=require("fs");const p=JSON.parse(fs.readFileSync("package.json","utf8"));if(!p.scripts)process.exit(0);const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;let removed=false;for(const k of["preinstall","install","postinstall","prepare"]){const s=p.scripts[k];if(typeof s!=="string")continue;if(re.test(s)){delete p.scripts[k];removed=true;}}if(removed)fs.writeFileSync("package.json",JSON.stringify(p));\' || true; }; fi && { pa_install_status=0; if [ -f /app/node_modules/.pa-lock ] && { cmp -s /app/package-lock.json /app/node_modules/.pa-lock || cmp -s /app/pnpm-lock.yaml /app/node_modules/.pa-lock || cmp -s /app/yarn.lock /app/node_modules/.pa-lock || cmp -s /app/bun.lock /app/node_modules/.pa-lock; }; then echo "node_modules cache hit"; else HUSKY=0 LEFTHOOK=0 CI=1 bun install && { for f in package-lock.json pnpm-lock.yaml yarn.lock bun.lock; do if [ -f "/app/$f" ]; then cp "/app/$f" /app/node_modules/.pa-lock; break; fi; done; true; }; fi || pa_install_status=$?; if [ -f /tmp/pa-package.json ]; then mv /tmp/pa-package.json /app/package.json; fi; if [ "$pa_install_status" -ne 0 ]; then exit $pa_install_status; fi; } && bun run build'],
            'pnpm in a subtree' => [['HUSKY=0 CI=1 sh -c \'command -v corepack >/dev/null 2>&1 || npm install -g corepack\' && corepack enable && corepack prepare pnpm@9 --activate && pnpm install --frozen-lockfile', 'pnpm run build', '/app/web'], 'export PATH=/tmp/corepack-bin:/tmp/corepack-npm/bin:$PATH && sh -c \'command -v corepack >/dev/null 2>&1 || npm install -g --prefix /tmp/corepack-npm corepack\' && mkdir -p /tmp/corepack-bin && corepack enable --install-directory /tmp/corepack-bin && corepack prepare pnpm@9 --activate && if [ -f /app/web/package.json ]; then cp /app/web/package.json /tmp/pa-package.json && { timeout 30 node -e \'const fs=require("fs");const p=JSON.parse(fs.readFileSync("package.json","utf8"));if(!p.scripts)process.exit(0);const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;let removed=false;for(const k of["preinstall","install","postinstall","prepare"]){const s=p.scripts[k];if(typeof s!=="string")continue;if(re.test(s)){delete p.scripts[k];removed=true;}}if(removed)fs.writeFileSync("package.json",JSON.stringify(p));\' || true; }; fi && { pa_install_status=0; if [ -f /app/web/node_modules/.pa-lock ] && { cmp -s /app/web/package-lock.json /app/web/node_modules/.pa-lock || cmp -s /app/web/pnpm-lock.yaml /app/web/node_modules/.pa-lock || cmp -s /app/web/yarn.lock /app/web/node_modules/.pa-lock || cmp -s /app/web/bun.lock /app/web/node_modules/.pa-lock; }; then echo "node_modules cache hit"; else HUSKY=0 CI=1 pnpm install --frozen-lockfile && { for f in package-lock.json pnpm-lock.yaml yarn.lock bun.lock; do if [ -f "/app/web/$f" ]; then cp "/app/web/$f" /app/web/node_modules/.pa-lock; break; fi; done; true; }; fi || pa_install_status=$?; if [ -f /tmp/pa-package.json ]; then mv /tmp/pa-package.json /app/web/package.json; fi; if [ "$pa_install_status" -ne 0 ]; then exit $pa_install_status; fi; } && pnpm run build'],
            'build only' => [['', 'npm run build'], 'export PATH=/tmp/corepack-bin:/tmp/corepack-npm/bin:$PATH && npm run build'],
            'install only' => [['npm ci', ''], 'export PATH=/tmp/corepack-bin:/tmp/corepack-npm/bin:$PATH && if [ -f /app/package.json ]; then cp /app/package.json /tmp/pa-package.json && { timeout 30 node -e \'const fs=require("fs");const p=JSON.parse(fs.readFileSync("package.json","utf8"));if(!p.scripts)process.exit(0);const re=/\\b(husky|lefthook|simple-git-hooks|yorkie|pre-commit)\\b/i;let removed=false;for(const k of["preinstall","install","postinstall","prepare"]){const s=p.scripts[k];if(typeof s!=="string")continue;if(re.test(s)){delete p.scripts[k];removed=true;}}if(removed)fs.writeFileSync("package.json",JSON.stringify(p));\' || true; }; fi && { pa_install_status=0; if [ -f /app/node_modules/.pa-lock ] && { cmp -s /app/package-lock.json /app/node_modules/.pa-lock || cmp -s /app/pnpm-lock.yaml /app/node_modules/.pa-lock || cmp -s /app/yarn.lock /app/node_modules/.pa-lock || cmp -s /app/bun.lock /app/node_modules/.pa-lock; }; then echo "node_modules cache hit"; else npm ci && { for f in package-lock.json pnpm-lock.yaml yarn.lock bun.lock; do if [ -f "/app/$f" ]; then cp "/app/$f" /app/node_modules/.pa-lock; break; fi; done; true; }; fi || pa_install_status=$?; if [ -f /tmp/pa-package.json ]; then mv /tmp/pa-package.json /app/package.json; fi; if [ "$pa_install_status" -ne 0 ]; then exit $pa_install_status; fi; }'],
            'nothing' => [['', ''], ''],
            'provisioning only' => [['corepack enable', 'yarn build'], 'export PATH=/tmp/corepack-bin:/tmp/corepack-npm/bin:$PATH && mkdir -p /tmp/corepack-bin && corepack enable --install-directory /tmp/corepack-bin && yarn build'],
        ];
    }

    /** @param list<string> $arguments */
    #[DataProvider('scripts')]
    public function test_the_inner_script_is_pinned(array $arguments, string $expected): void
    {
        $this->assertSame($expected, HostNodeBuild::innerScript(...$arguments));
    }

    public function test_the_manifest_the_strip_rewrites_is_the_checkouts_own_again(): void
    {
        $script = (string) HostNodeBuild::innerScript('npm ci', 'npm run build');

        $copied = strpos($script, 'cp /app/package.json /tmp/pa-package.json');
        $stripped = strpos($script, 'fs.writeFileSync("package.json"');
        $installed = strpos($script, 'npm ci &&');
        $restored = strpos($script, 'mv /tmp/pa-package.json /app/package.json');
        $build = strpos($script, 'npm run build');

        $this->assertIsInt($copied);
        $this->assertIsInt($stripped);
        $this->assertIsInt($installed);
        $this->assertIsInt($restored);
        $this->assertIsInt($build);
        $this->assertLessThan($stripped, $copied);
        $this->assertLessThan($installed, $stripped);
        $this->assertLessThan($restored, $installed);
        $this->assertLessThan($build, $restored);
    }

    public function test_the_manifest_comes_back_even_when_the_install_fails(): void
    {
        $script = (string) HostNodeBuild::innerScript('npm ci', 'npm run build');

        $this->assertStringContainsString(
            '|| pa_install_status=$?; if [ -f /tmp/pa-package.json ]; then mv /tmp/pa-package.json /app/package.json; fi; '
            .'if [ "$pa_install_status" -ne 0 ]; then exit $pa_install_status; fi; }',
            $script
        );
    }

    /** Each half is one command of the && chain: a provisioning or a backup that fails stops it before the install. */
    public function test_the_install_step_holds_the_chain_it_is_part_of(): void
    {
        $script = (string) HostNodeBuild::innerScript('corepack enable && corepack prepare pnpm@9 --activate && pnpm install', 'pnpm run build');

        $this->assertStringContainsString(
            'corepack prepare pnpm@9 --activate && if [ -f /app/package.json ]; then cp /app/package.json /tmp/pa-package.json && { ',
            $script
        );
        $this->assertStringContainsString(' || true; }; fi && { pa_install_status=0; if [ -f /app/node_modules/.pa-lock ]', $script);
        $this->assertStringEndsWith('then exit $pa_install_status; fi; } && pnpm run build', $script);
    }

    public function test_a_subtree_project_puts_its_own_manifest_back(): void
    {
        $script = (string) HostNodeBuild::innerScript('pnpm install --frozen-lockfile', 'pnpm run build', '/app/web');

        $this->assertStringContainsString('cp /app/web/package.json /tmp/pa-package.json', $script);
        $this->assertStringContainsString('mv /tmp/pa-package.json /app/web/package.json', $script);
    }
}
