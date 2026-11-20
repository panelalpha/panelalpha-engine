<?php

namespace Tests\Support;

final class AstroBuildFailure
{
    /**
     * An Astro build failure as AppLauncher::failureOutput() hands it on; no rule names its error.
     */
    public const OUTPUT = <<<'OUT'
    #11 [build 5/5] RUN npm run build
    #11 0.412
    #11 0.412 > static-site@1.0.0 build
    #11 0.412 > astro build
    #11 2.871 12:01:33 [content] Syncing content
    #11 3.105 12:01:33 [build] output: "static"
    #11 3.552 [InvalidComponentArgs] Invalid arguments passed to <Layout> component.
    #11 3.552   Location:
    #11 3.552     /app/src/pages/index.astro:5:1
    #11 3.560 npm error Lifecycle script `build` failed with error:
    #11 3.560 npm error code 1
    #11 3.560 npm error path /app
    #11 3.560 npm error command failed
    #11 3.560 npm error command sh -c astro build
    #11 ERROR: process "/bin/sh -c npm run build" did not complete successfully: exit code: 1
     Image project-app Building
    Dockerfile:12
    failed to solve: process "/bin/sh -c npm run build" did not complete successfully: exit code: 1
    OUT;
}
