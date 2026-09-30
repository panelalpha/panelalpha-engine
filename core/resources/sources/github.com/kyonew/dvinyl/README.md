# DVinyl (github.com/kyonew/dvinyl)

A collection manager for physical media: an Express/TypeScript app on :3099
with MongoDB. See `panelalpha.yaml` for why the recipe replaces the
repository's compose file.

## Which MongoDB

Upstream's compose uses `mongo:latest`. Every maintained 8.x image (`mongo:8`
= 8.3.11, `mongo:8.0` = 8.0.32, checked 2026-09-29) refuses to start on Linux
6.19 or newer:

```
"s":"F","c":"CONTROL","id":12257600,"msg":"MongoDB cannot start: Linux kernel
versions 6.19 and newer has a known incompatibility with this version of
MongoDB. See https://jira.mongodb.org/browse/SERVER-121912"
```

Containers share the host's kernel, so on such a host the deploy fails at the
mongodb healthcheck. The recipe runs `mongo:7.0`, which has no such guard.
DVinyl supports it: its driver is mongoose 8, and upstream's own
`docs/docker.md` tells users to pin an older major where the latest one does
not run.

`mongo:8.2` (8.2.12) does start, but that line is finished: its image was last
rebuilt on 2026-07-23, when 8.3 replaced it, while 7.0, 8.0 and 8.3 were all
rebuilt on 2026-09-16. 7.0 is the newest maintained line that starts.

**An install already on mongo 8 cannot simply switch to 7.0.** MongoDB 7.0
will not open a data directory written by 8.x (featureCompatibilityVersion
8.0); upstream's docs say the same about downgrades. Moving such an install
means `mongodump` from the 8.x server on a host that can still run it, then
`mongorestore` into 7.0; or lowering the featureCompatibilityVersion to 7.0 on
the 8.x server first, per MongoDB's downgrade procedure. Neither works on a
6.19+ kernel, where 8.x does not start.
