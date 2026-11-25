<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\NextExportConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * haim-ai/tamhil sets `output: "export"` only when GITHUB_PAGES=1; its `build` is a plain `next build`, which writes
 * `.next/` and no `out/`, and the static recipe served an empty directory as a 403.
 */
class NextExportConfigTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function exporting(): array
    {
        return [
            'plain object' => ['const nextConfig = { output: "export", images: { unoptimized: true } };'],
            'single quotes' => ["module.exports = {\n  output: 'export',\n}"],
            'typed config' => ['const nextConfig: NextConfig = { output: "export" as const };'],
            'wrapped' => ['export default withMDX({ output: "export", pageExtensions: ["mdx"] });'],
            'returned' => ['export default () => ({ output: "export" });'],
            'after a nested object' => ['const c = { images: { unoptimized: true }, output: "export" };'],
            'one unconditional of two' => ['const a = dev ? { output: "export" } : {}; const c = { output: "export" };'],
            'url before it' => ['const c = { assetPrefix: "https://cdn.example.com", output: "export" };'],
            'quoted key' => ['module.exports = { "output": "export" };'],
            'single-quoted key' => ["module.exports = { 'output': 'export' };"],
        ];
    }

    #[DataProvider('exporting')]
    public function test_an_export_for_every_build_counts(string $config): void
    {
        $this->assertTrue(NextExportConfig::exportsUnconditionally($config));
    }

    /** @return array<string, array{string}> */
    public static function notExporting(): array
    {
        return [
            'tamhil spread' => [
                "const pages = process.env.GITHUB_PAGES === \"1\";\nconst nextConfig: NextConfig = {\n"
                ."  ...(pages ? { output: \"export\" as const, basePath: base, trailingSlash: true } : {}),\n"
                ."  images: { unoptimized: true },\n};",
            ],
            'and spread' => ['module.exports = { ...(isStatic && { output: "export" }), reactStrictMode: true };'],
            'else branch' => ['module.exports = dev ? {} : { output: "export" };'],
            'or default' => ['module.exports = custom || { output: "export" };'],
            'line comment' => ["module.exports = {\n  // output: 'export',\n  reactStrictMode: true,\n};"],
            'block comment' => ['module.exports = { /* output: "export", */ reactStrictMode: true };'],
            'nested key' => ['module.exports = { experimental: { output: "export" } };'],
            'no export' => ['module.exports = { output: "standalone" };'],
        ];
    }

    #[DataProvider('notExporting')]
    public function test_a_conditional_or_commented_export_does_not(string $config): void
    {
        $this->assertFalse(NextExportConfig::exportsUnconditionally($config));
    }

    /** @return array<string, array{string, ?string}> */
    public static function basePaths(): array
    {
        return [
            'literal' => ['module.exports = { output: "export", basePath: "/nextjs-github-pages" };', '/nextjs-github-pages'],
            'quoted key, trailing slash' => ["module.exports = { 'basePath': '/docs/', output: 'export' };", '/docs'],
            'nested segments' => ['export default { basePath: "/a/b-c_1.2" };', '/a/b-c_1.2'],
            'under a condition' => ['export default { ...(pages ? { basePath: "/tamhil" } : {}) };', null],
            'from a variable' => ['export default { basePath: base };', null],
            'root' => ['export default { basePath: "/" };', null],
            'commented' => ["export default {\n  // basePath: '/old',\n};", null],
            'traversal' => ['export default { basePath: "/a/../b" };', null],
            'none' => ['export default { output: "export" };', null],
        ];
    }

    #[DataProvider('basePaths')]
    public function test_a_base_path_for_every_build_is_read(string $config, ?string $expected): void
    {
        $this->assertSame($expected, NextExportConfig::basePath($config));
    }
}
