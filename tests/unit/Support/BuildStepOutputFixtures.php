<?php

namespace Tests\Support;

trait BuildStepOutputFixtures
{
    /** wastebin, the failing step as compose printed it on stdout. */
    private const RUST_STDOUT = <<<'OUT'
    #12 [builder 7/9] RUN cargo fetch
    #12 DONE 31.2s

    #13 [builder 8/9] RUN cargo zigbuild --release --bin wastebin
    #13 247.8    Compiling wastebin_core v3.7.2 (/app/crates/wastebin_core)
    #14 [geodata 2/2] RUN apk add --no-cache curl
    #13 248.7    Compiling wastebin v3.7.2 (/app/crates/wastebin_server)
    #13 249.2 error[E0432]: unresolved import `crate::handlers::owner`
    #13 249.2   --> crates/wastebin_server/src/handlers/html/paste.rs:12:22
    #13 249.2    |
    #13 249.2 12 | use crate::handlers::owner::verify_owner_token;
    #13 249.2    |                      ^^^^^ could not find `owner` in `handlers`
    #13 251.0 For more information about this error, try `rustc --explain E0432`.
    #13 251.0 error: could not compile `wastebin` (bin "wastebin") due to 1 previous error
    #13 251.0 warning: build failed, waiting for other jobs to finish...
    #13 ERROR: process "/bin/sh -c cargo zigbuild --release --bin wastebin" did not complete successfully: exit code: 101
    #14 CANCELED
    ------
     > [builder 8/9] RUN cargo zigbuild --release --bin wastebin:
    251.0 error: could not compile `wastebin` (bin "wastebin") due to 1 previous error
    ------
    OUT;

    private const RUST_STDERR = <<<'OUT'
     Image project-app Building
    WARNING: current commit information was not captured by the build: failed to read current commit information with git rev-parse --is-inside-work-tree
    Dockerfile:30
    --------------------
      30 | >>> RUN cargo zigbuild --release --bin wastebin
    --------------------
    failed to solve: process "/bin/sh -c cargo zigbuild --release --bin wastebin" did not complete successfully: exit code: 101
    OUT;

    private const GO_STDERR = <<<'OUT'
     Image project-app Building
    Dockerfile:9
    failed to solve: process "/bin/sh -c set -eou pipefail && CGO_ENABLED=0 go build -tags embedui,embeddocs,embedgeo -o /build/photofield ." did not complete successfully: exit code: 1
    OUT;
}
