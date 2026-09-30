# phpSysInfo

System information page (PHP), served from the repository root on the engine's
PHP platform instead of the repo's Dockerfile (which clones upstream into a
subdirectory of an Ubuntu 20.04 / PHP 7.4 Apache and serves the default page
at `/`).

- `phpsysinfo.ini` is copied from the shipped `phpsysinfo.ini.new` at build
  unless the repository commits its own; to change settings, commit
  `phpsysinfo.ini` to a fork (edits in `~/project` are lost on redeploy).
- The page shows what the account's container sees (its /proc), not the
  physical host.
