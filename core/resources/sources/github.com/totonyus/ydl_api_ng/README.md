# ydl_api_ng (github.com/Totonyus/ydl_api_ng)

REST API around yt-dlp: `GET /download?url=...` queues a download on the
server (RQ workers + Redis), `/info`, `/queue`, `/programmation` and friends;
FastAPI serves interactive docs at `/docs`. Clients are the upstream
userscript, iOS shortcuts or anything that can call a URL.

The repo compose mounts `./params` from the checkout, and the checkout's
`params/params.ini` is the non-Docker config (`_listen_port = 5011`,
`_enable_redis = false`), so a plain deploy never answers on :80. The recipe
keeps upstream's image and Redis (`ydl_api_ng_redis`, the host name the
Docker config expects) and puts every mount on a named volume; the entrypoint
seeds `/app/params` from `params_docker.ini` when the volume is empty.

Image: `totonyus/ydl_api_ng` has no version tags, so `latest` is pinned by
digest (built 2026-09-14 from HEAD 399f6057). Edit settings in the `params`
volume (`params.ini`, `workers.ini`); `_enable_users_management` adds API
tokens. Downloads land in the `downloads` volume.
