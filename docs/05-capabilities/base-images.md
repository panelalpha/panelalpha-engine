# Your own base images

Every container project has its own Docker, so nothing one project builds is reused by the next. When many projects start from the same heavy Dockerfile, such as a PHP server with Apache, extensions and a loader, each one installs all of it again on its first deploy.

Build that common part once on the server instead. The engine keeps it in the server's image cache, the same registry it keeps its own base images in, and every project's Dockerfile starts `FROM` it by its plain name:

```dockerfile
FROM whmcs-dev-base:php8.3
COPY . /var/www/html
```

The project then builds only its own layers. The base comes from the server in seconds and never touches the internet.

## What may go in

**Every project on this server can download every image you add.** The image cache has no login, by design: it is how projects get base images without leaving the server.

- No passwords, keys, tokens or licence keys, in the Dockerfile or in build arguments. Build arguments are also visible in the image's history.
- No licensed or paid code you may not hand to every customer on the server. A licensed application belongs in the project that holds the licence, not in its base.
- Public packages, and loaders their vendor lets you redistribute, are fine.

## Adding an image

There are three ways to add one. Each prints the build or download as it runs and finishes with the image's digest.

**Build it from a Dockerfile** on the server. The Dockerfile comes on standard input and is built with no build context, so it cannot `COPY` files from the server: write small files with `RUN`, and take files from other images with `COPY --from=<image>`.

```bash
pae system:image:add whmcs-dev-base:php8.3 --build --why="WHMCS dev server base" < Dockerfile
```

```dockerfile
FROM debian:bookworm-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl apache2 \
 && curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ bookworm main" \
      > /etc/apt/sources.list.d/sury-php.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends libapache2-mod-php8.3 php8.3-cli php8.3-curl \
      php8.3-gd php8.3-intl php8.3-mbstring php8.3-mysql php8.3-xml php8.3-zip php8.3-bcmath \
 && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz \
      | tar -xz -C /opt \
 && for sapi in apache2 cli; do \
      echo "zend_extension=/opt/ioncube/ioncube_loader_lin_8.3.so" > /etc/php/8.3/$sapi/conf.d/00-ioncube.ini; \
    done
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
```

Pass `--build-arg=NAME=value` as many times as the Dockerfile needs.

**Download one** that a registry publishes, to have it on the server before any project asks:

```bash
pae system:image:add debian:bookworm-slim --pull
```

**Use one you built yourself** with `docker build` on the server:

```bash
pae system:image:add acme-base:1.4 --local
```

Some names are refused, because every project would get your image instead of the one it asked for:

- a name Docker Hub already publishes, for `--build` and `--local`: pick another name, or add the public image with `--pull`;
- a name with a registry host in it (`ghcr.io/...`), for `--build` and `--local`;
- a name the engine uses for its own images, such as `node`, `nginx`, `composer` or anything under `panelalpha/`. Those are kept ready with `pae configure prewarm` instead.

The name always needs a tag, such as `:php8.3` or `:1.4`.

## Using it in a project

Write the name after `FROM`, exactly as you added it, in the project's Dockerfile or in the Dockerfile of any service a compose file builds. Before it builds, the deploy fetches every base it recognises as yours from the image cache, and the deploy log says so:

```
Pulled base image whmcs-dev-base:php8.3 from the cache registry
```

A compose service can also run the image directly with `image: whmcs-dev-base:php8.3`.

Write the name literally. A `FROM ${BASE}` built from a build argument is not recognised, and the build then asks Docker Hub for it.

## Changing, refreshing and removing

```bash
pae system:image:list
pae system:image:update whmcs-dev-base:php8.3 --build < Dockerfile
pae system:image:refresh whmcs-dev-base:php8.3
pae system:image:remove whmcs-dev-base:php8.3
```

- **`list`** shows your images and the engine's own, whether the image cache holds each right now, its size and when it was last refreshed. `--operator` shows only yours.
- **`update`** replaces the Dockerfile (`--build`), the build arguments (`--build-arg`, or `--no-build-args` to drop them) or the note (`--why`). A changed build is built and published again straight away.
- **`refresh`** does now what the weekly refresh does.
- **`remove`** takes the image out of the image cache and forgets it. Projects that already have it keep their copy, but a new build `FROM` it fails, so change those projects' Dockerfiles first.

The next deploy of a project after a refresh or an update picks up the new image.

## What happens to it over time

Every Sunday at 03:30 the server refreshes its images. Yours are refreshed according to how you added them:

| Added with | Weekly refresh |
|---|---|
| `--build` | Built again from scratch, with fresh downloads of every package, so security updates arrive. |
| `--pull` | Downloaded again. |
| `--local` | Published again from your copy on the server. When you have removed that copy, the image cache keeps what it has. |

A refresh that fails keeps the previous image in place; `pae system:image:list` shows it as `failed` with the reason.

None of the automatic clean-ups remove your images: not the weekly clean-up of the image cache, not the daily removal of unused base images, not the disk guard when the server runs low on space, and not an engine update. The prewarm disk budget does not count them.

## Disk use

An image takes space once in the image cache, once more on the server for an image the server built or downloaded, and once in the Docker of each project that builds from it. That last part counts towards each project's own disk use. A 650 MB base used by twenty projects therefore takes about 14 GB in total, so remove the images you no longer use.

## Checking that a deploy used it

- The deploy log has the line `Pulled base image <name> from the cache registry`.
- The build steps no longer include the base's own installs. Ask for the deploy's timings, or call `GET /api/projects/{project}/deploy-log?build_timings=1`: `timings.build.slowest` lists the slowest steps, and the `apt-get` and download steps of the base are not among them.
- `pae system:image:list` says the image is in the registry.

## For applications that create projects through the API

An application that creates projects built from your image can check that the image is there first, and fall back to a full build when it is not:

```bash
curl -s https://<engine>:2011/api/system/images -H "Authorization: Bearer $TOKEN"
```

Each entry has `ref`, `present` (whether the image cache holds it right now), `digest`, `size` and `refreshed_at`. A token limited to some projects is refused; an application with [Engine API access](../06-commands/pae-cli.md#engine-api-for-projects) needs the scope `all`. The same endpoint takes `POST` to add an image (`ref`, `source`, `dockerfile`, `build_args`, `why`), `PUT /system/images/{id}` to change one, `POST /system/images/{id}/refresh` and `DELETE /system/images/{id}`. Each of those runs as a task and answers with its `task_id`.

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)).

**Which base images the server keeps**

```text
Which base images does this server keep for its projects, and are they all in
the image cache right now?
```

Mode: read. Tools: `system_image_list`. Check: the answer separates the operator's images from the engine's and says for each whether it is present.

**Add a public base image**

```text
Keep debian:bookworm-slim on this server as a base image every project can
build from.
```

Mode: modify. Tools: `system_image_create`, `task_get`, `system_image_list`. Check: the task completes and the image is listed with source `pull` and `present: true`.
