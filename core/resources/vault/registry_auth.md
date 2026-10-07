### Logins for private image registries

One line per registry: the registry host, the username, then a token that can
pull, separated by spaces.

```
ghcr.io my-user ghp_xxxxxxxxxxxx
registry.example.com:5000 deploy s3cr3t
```

- **GitHub (ghcr.io)**: a [personal access token](https://github.com/settings/tokens) with `read:packages`
- **GitLab**: a deploy token or access token with `read_registry`
- **Docker Hub (docker.io)**: an [access token](https://app.docker.com/settings/personal-access-tokens) with read access

Paste the lines below. They are stored **encrypted** on this server, written
into your project only while a deploy pulls its images, and never shown back to
anyone — including the assistant that sent you here.
