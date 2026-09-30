# Shelly Manager

Published API and web images (1.10.0) instead of the repo's dev compose. The
web nginx proxies `/api` to the API so one published port serves both, and
`SHELLY_SECRET_KEY` is generated once into `~/.panelalpha/shelly-manager/`.
Devices are managed by IP from the server; there is no mDNS discovery.
