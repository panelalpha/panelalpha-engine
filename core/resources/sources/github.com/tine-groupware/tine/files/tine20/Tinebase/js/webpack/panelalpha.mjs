/*
 * tine's production webpack build, sized for a hosting account.
 *
 * Not webpack/prod.mjs, and not because prod.mjs is wrong -- it is what
 * upstream's release pipeline runs, on a CI runner. Run in the engine's host
 * build container it is killed: measured on a 15.6 GB host, where
 * DindEngine::resolveBuildMemory() gives the build container MemTotal/3 =
 * 5202 MB, the webpack process reached 5.0 GB anon-rss and the cgroup OOM
 * killer took it ("Memory cgroup out of memory: Killed process (webpack)
 * total-vm:35615720kB, anon-rss:5130116kB"), after 2m38s, with nothing but
 * `Killed` in the log. Raising the limit is not available to a recipe: it is
 * derived from the host's RAM, and this host is shared.
 *
 * So this config is prod.mjs minus the three things a hosting deployment pays
 * for and never uses. Everything else -- the entry points, the loaders, the
 * output layout, the asset manifest -- is webpack/common.mjs, imported
 * unchanged, so the bundles are the ones tine expects to find.
 *
 *   devtool: false  (prod.mjs: 'source-map')
 *       Full source maps for 29 entry points of ExtJS-era JavaScript, held in
 *       memory until emit. This is the single largest item and the one nobody
 *       here can use: the maps would be published to the customer's document
 *       root, and debugging a hosted tine against them is not a workflow this
 *       platform has.
 *
 *   no UnminifiedWebpackPlugin  (prod.mjs adds it)
 *       It emits a second, unminified copy of every bundle as `*-debug.js`.
 *       tine asks for those only when TINE20_BUILDTYPE is DEBUG
 *       (Tinebase/Frontend/Http.php:284 and :291), which upstream sets for
 *       beta releases; this recipe's config.inc.php says AUTODETECT, which
 *       resolves to RELEASE. So they are a second full asset set built, held
 *       and written for a code path that cannot be reached.
 *
 *   no BrotliPlugin  (prod.mjs adds it)
 *       Pre-compressed `.br` beside each bundle is only of use to a server
 *       configured to serve it. The generated Apache vhost
 *       (resources/deploy/templates/apache-vhost.stub) has no `AddEncoding br`
 *       and no rewrite to a `.br` file, so nothing ever requests one. mod_deflate
 *       is enabled in the base image and compresses on the fly instead.
 *
 * terser still runs -- the bundles are minified exactly as upstream's are --
 * but with `parallel: 2` rather than one worker per core: this host has 8, and
 * eight terser workers each with their own heap inside one cgroup is the other
 * half of the memory story.
 *
 * `extractComments: 'all'` is kept from prod.mjs, so the dependency licence
 * headers are written out beside the bundles instead of being dropped.
 */
import { merge } from 'webpack-merge';
import webpack from 'webpack';
import TerserPlugin from 'terser-webpack-plugin';
import common from './common.mjs';

export default async () => {
    const commonConfig = await common();

    return merge(commonConfig, {
        mode: 'production',
        devtool: false,
        optimization: {
            minimizer: [new TerserPlugin({
                parallel: 2,
                extractComments: 'all',
                terserOptions: {},
            })],
        },
        plugins: [
            // The client-side counterpart of TINE20_BUILDTYPE, read by
            // Tine.clientVersion.buildType. RELEASE, because that is what
            // Tinebase_Core::detectBuildType() will answer once this build has
            // written Tinebase/js/webpack-assets-FAT.json.
            new webpack.DefinePlugin({
                BUILD_TYPE: "'RELEASE'",
            }),
        ],
    });
};
