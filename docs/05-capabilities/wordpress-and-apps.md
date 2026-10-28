# WordPress and known apps

WordPress is a main way to use the engine, not an extra. Many hosting accounts on it are WordPress sites. You can ask the engine to set up a new one without bringing your own code, deploy one you already keep in git, or look after a WordPress site that is already running here.

This page covers all three, how to move a WordPress site here from another host, then day-to-day work: logging in, users, WP-CLI, and the other known apps the engine treats specially.

## If WordPress is already on this engine

Do not install it again. Ask the assistant to list your projects, then work on the one you mean.

```text
List the projects on this engine.
```

```text
Inspect this project. Tell me if it is WordPress, and where its files are.
```

From there you can add a domain, take a backup, change PHP, run WP-CLI, or manage WordPress users, using the requests later on this page. Which of these work depends on the kind of WordPress it is.

There are two kinds of WordPress on this engine, and they are not the same:

- **Traditional PHP hosting.** Files live in the domain's folder, `/<domain>/public_html`. Uploads show up on the site without a rebuild. You can change that domain's PHP version, set PHP memory, use FTP, backups, domains and WP-CLI, which also covers WordPress users and plugins. The App users tools, with the install wizard, are not available on this kind; the WP-CLI operations below cover users and a one-time login link instead. Cloudflare is not available either: a tunnel, a cache purge or the security level.
- **Its own container.** Files live in `project/`. This is what you get when the engine sets WordPress up for you, or when you deploy it from git. WordPress users, one-click login and the install wizard work here, as do WP-CLI, backups and domains. That includes WordPress from a Docker Compose file of your own, such as the official `wordpress` image next to a database.

If an action is refused, see [Troubleshooting](#troubleshooting). Older versions called a project a "user". If output still says `username`, that is the project's name.

Open the database in a browser with a phpMyAdmin link: [Databases](databases.md#phpmyadmin).

## Change the PHP version

On traditional PHP hosting you can change which PHP that domain runs. List the versions this VPS has, then pick one.

```text
What PHP versions can this engine run?
Set shop.example.com to PHP 8.2.
```

What this does: the assistant lists the versions installed on this VPS, then switches that domain to the one you named.

What you should see: the site still opens. Ask what version the domain is on if you want to check.

This domain setting is for traditional PHP hosting. A WordPress site in its own container does not use it.

Plugin installs that fail for lack of memory are often a PHP limit. On traditional PHP hosting, raise it, then try WP-CLI again:

```text
Set PHP memory_limit to 256M on this project.
```

A WordPress site in its own container reads its PHP settings from its own image, so the assistant is told this setting does not apply there.

## Set up WordPress

Do the domain first if you are using your own. WordPress records its own site address when it is installed and does not take kindly to that address changing afterwards: [Domains and HTTPS](domains-and-ssl.md).

Then ask your assistant:

```text
Set up WordPress on a new project on this PanelAlpha Engine.
```

The engine creates the project in its own isolated container, creates a MySQL database for it, and writes the WordPress configuration. It does not finish the WordPress setup wizard for you; that is the next step.

## Finish the install

Give WordPress the details it needs for the first administrator account. You need all five: the site address, the site title, an admin username, an admin email, and a password of at least 8 characters.

```text
Install WordPress on this project. Title: My Site. Admin user: admin.
Admin email: admin@example.com. Admin password: <password>
```

When it finishes, the site is live at its address.

You can also finish the setup yourself by opening the site in a browser and completing WordPress's own first-run screens. Either way ends in the same place.

<!-- TODO screenshot: show the WordPress first-run setup screen (or the site's front page immediately after install) so the reader recognises a finished install. -->

## Open the site and log in

Open the site's address. The WordPress admin is at `/wp-admin`, where you log in with the username and password from the install step.

For a login that skips the password, ask your assistant:

```text
Give me a one-click login link for the admin user of this WordPress site.
```

It returns a link that logs you straight into the WordPress admin. The link is single-use and expires quickly, so open it right away.

<!-- TODO screenshot: show the /wp-admin login screen with the username field, so the reader knows where to log in manually. -->

## Manage WordPress users

You can manage a site's WordPress users from the chat. On WordPress in its own container the engine uses its App users actions; on any WordPress, including traditional PHP hosting, it can use the WP-CLI user operations (`wp_user_*`) instead:

```text
List the users on this WordPress site.
Add an editor to this WordPress site: login jdoe, email jdoe@example.com.
Reset the password for user 5 on this WordPress site.
Delete WordPress user 5 on this site.
```

Ask for the list of roles if you are not sure which one to give a new user. Passwords for WordPress users must also be at least 8 characters. If the assistant cannot do these, see [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

## Run WP-CLI

WP-CLI is WordPress's own command-line tool, for jobs the admin screens make slow: bulk updates, plugin management, or changing the recorded site address.

```text
Run `wp core version` on this site.
Update all plugins on this site.
```

Commands run as that site's own user, in the right directory, so you do not need to know where the files are.

On WordPress in its own container, the engine finds the container that runs WordPress and runs the command there, as the owner of `wp-content`, so plugins it installs can be updated from wp-admin later. The container needs PHP and a WordPress directory. That holds for the engine's own WordPress and for the official `wordpress` image in a Compose file of your own.

WordPress does not have to be at the top of the project. Two settings point the engine at it:

- **path**: the WordPress directory. On PHP hosting it is inside the project's home: give it relative to the home, such as `blog`, or in full. A path outside the home is read as inside it, the way the file manager reads one, and a path that climbs out of the home with `..` is refused. On WordPress in its own container, it is the directory as the container sees it: in full, such as `/var/www/html/blog`, or relative to the container's working directory, such as `blog`.
- **service**: the Compose service whose container runs WordPress. Use it when more than one container has PHP and WordPress.

```text
Run `wp core version` on this site, with WordPress in the blog directory.
List the plugins of the WordPress in the shop service.
```

### Named WordPress operations

For common jobs the assistant does not have to write a WP-CLI command. Each of these is its own tool (`wp_*`), its own API route under `/projects/{project}/wp/`, and its own `pae wordpress:*` command:

- **Plugins and themes**: search wordpress.org, list, read one, check whether one is installed, install, activate, update and delete. Plugins can also be deactivated; a theme is switched off by activating another one.
- **Users**: list (by `role`, with chosen `fields`), create (with `send_email` to send the welcome mail), update, set a password, delete, application passwords, and a one-time login link.
- **Core**: status, available updates, download, install (with `skip_email`) and update.
- **Site settings**: maintenance mode, cache flush, `wp-config.php` (list, create from database details, read, set and delete constants or variables), options, auto-updates, site language.
- **Search and replace** in the database, for example after a domain change.
- **Database export and import.** An export can be limited to some `tables`.

All of them take the same `path` and `service` as above, and `skip_plugins`, which loads WordPress without its plugins and themes, so a site with a broken plugin can still be managed. Plugin and theme lists take `fields` and `status`, and search takes `fields` and `per_page`. A value that starts with `-`, such as a slug, login or option name, is refused, so it cannot be read as a WP-CLI option.

A database export is readable by the project's own user only, so the webserver cannot serve it and answers 403 if someone asks for it by URL.

## Move a WordPress site here

The engine can copy a WordPress site from another host into a project here, with its files, database and domain. Ask the assistant to test the source first; a test creates nothing:

```text
Can you import https://example.com into this engine? Test it first.
```

There are two ways to reach the source site:

- **wp-admin login** (`wp_admin`). Give the wp-admin user name and password. If the site uses a custom login page, give its address too (`login_url`). The engine logs in, installs its migrator plugin, and removes it again when the import is cancelled.
- **Migrator plugin** (`manual_plugin`). Ask for the plugin package. You get a download link that works for 24 hours and a token. Install the plugin on the source site yourself, then start the import with that token. Remove the plugin from the source when the import is done.

A test answers the domain the site would get, or why the import would fail: `unreachable`, `tls`, `not_wordpress`, `cloudflare`, `login_failed`, `plugin_not_found` or `unauthorized`, or the same codes a start would refuse with. At most two tests run at the same time (a third is refused with 429), and each one stops after 30 seconds.

**Where the site goes.** By default it keeps its own domain, the source's address without `www.`, in a new traditional PHP project. You can choose:

- `domain`: another domain for the site. Its links are rewritten to it.
- `project`: an existing traditional PHP project to import into, as long as its site folder holds nothing but the placeholder page.
- `project_name`: the name of the new project.
- `source_ip`: the public address of the source server, when the domain's DNS already points somewhere else.

A start is refused with 422 `domain_taken` when a project here already has the domain, and `domain_required` when the source is given as an IP address and the engine has no PanelAlpha Connect. It is refused with 409 `engine_address_private` when the source site could not reach this engine: see [Install](../02-getting-started/install.md).

**While the domain still points to the old host.** With [PanelAlpha Connect](../02-getting-started/connect-to-panelalpha-connect.md), the import opens a preview of the site on a `panelalpha.online` address (`preview_url`). The import status shows the DNS record to set. Every 15 minutes the engine checks the domain; once it points here, the engine requests the certificate and removes the preview. Without Connect there is no preview: the site answers on its domain once DNS points here.

**Following, resuming and cancelling.** The status shows how far the whole run is, the step it is on, and the file or table it is sending. A failed import, or one that has not moved for 15 minutes, can be resumed. Cancelling removes the project, files and database the import made here, and on a `wp_admin` source the migrator plugin. A finished, failed or cancelled import can be deleted from the list.

**Secrets.** The wp-admin password and the plugin token are kept encrypted and never shown. If an import sits idle for 7 days, they are cleared; resuming it then asks for them again.

**For the server operator.** Each import installs one pinned release of the migrator plugin, checked by its SHA-256. To use another release, set both `WORDPRESS_MIGRATOR_RELEASE_URL` and `WORDPRESS_MIGRATOR_SHA256` in `/opt/panelalpha/shared-hosting/.env-core`. The source, and every address it redirects to, must be public and not the engine's own; a source on your own private network needs `EGRESS_ALLOW_PRIVATE=true` ([Install](../02-getting-started/install.md)).

## Deploying a WordPress project from your own repository

If you already have a WordPress site in a git repository (its files include `wp-settings.php` and `wp-admin`), you can deploy it like any other git project: [Connecting with Git](connecting-with-git.md). The engine recognises it as WordPress, creates a database, and writes a `wp-config.php` if one is missing. Finish the first-run setup in the browser afterwards.

This is a different starting point from asking the engine to set up a fresh WordPress site: [Set up WordPress](#set-up-wordpress).

## "Known apps" versus "detected frameworks"

Two different things, and the difference explains some confusing results.

**Detected frameworks** are Next.js, Laravel, Django and the rest. The engine works these out from the files in your repository: [How detection works](../07-supported-projects/how-detection-works.md).

**Known apps** are WordPress, Matomo, phpBB, Magento, Passbolt, OpenCart, osTicket, Flarum, SuiteCRM, Chamilo, MantisBT, Easy!Appointments, Adminer, and phpMyAdmin. These get extra handling because the general-purpose approach would get something wrong, such as serving the wrong folder or missing a database.

To see what the engine will pick for a given repository, and whether another type could also run it:

```text
Inspect this repository and tell me what stack you detect: https://github.com/org/app
```

If inspect lists more than one option, you can ask for a specific one: "Deploy this as php."

A few other known apps worth knowing:

- **Magento** from its official repository gets a search engine and a first-run install. Ask your assistant for the admin address when it finishes. It does not offer the one-click login that WordPress does.
- **Matomo** and **phpBB** are set up so their own browser installer can finish; the engine prepares the database and writable folders, and you complete the install in the browser.
- **Passbolt** gets a database and the keys it needs to start. The first user is created on Passbolt's own registration page, not by the engine.
- **Flarum, SuiteCRM, and Chamilo** get a database, and you finish their own installer in the browser.
- **MantisBT and Easy!Appointments** get a database and a configuration file written from it, so you are not asked for credentials the engine already has.
- **Adminer** is built into a single page you can open. It does not get a database of its own.
- **phpMyAdmin** deployed from its repository is a project that signs in to that project's database. The sign-on link on [Databases](databases.md#phpmyadmin) is different: it opens the engine's own phpMyAdmin for a project you already have.

## Troubleshooting

**"App user management works only on container projects (template dind)."**
The WordPress user, install and one-click-login actions work on a WordPress site in its own container. A WordPress site on traditional PHP hosting can still be managed with WP-CLI, and you can still change its PHP version.

**"No WordPress install was found in this project's containers."**
The project is running, but no container has both PHP and a WordPress directory the engine knows to look in. Pass the WordPress directory as the container sees it, for example `/var/www/html/blog`. A site served by nginx with PHP in a second container works; WP-CLI runs in the PHP one.

**The core status says WordPress is not installed.**
The status also names why, in its `state`:

| State | What it means | What to do |
|---|---|---|
| `not_installed` | The files, `wp-config.php` and the database are all fine; WordPress was never installed into that database. | Finish the install. |
| `no_config` | WordPress's files are there, but there is no `wp-config.php`. | Create one, or ask the assistant to run `wp config create`. |
| `db_unreachable` | `wp-config.php` is there, but WordPress cannot connect to the database it names. | Check the database name, user, password and host in `wp-config.php`, and that the database is running. |
| `no_core` | There is no WordPress at that path. | Check the path, or the service on a project with its own containers. |
| `no_db_client` | WordPress is not installed or cannot reach its database; the engine checks the database with the MySQL client, and no container with WordPress has one. | Add a service on the `wordpress:cli` image with the same volumes and environment as the WordPress one. The engine's own WordPress recipe has one; redeploy a project made from it before that. |
| `unknown` | WordPress is not installed, for a reason the engine cannot tell, for example output it does not recognise. | Run `wp core is-installed` through the assistant and read its output. |

**"WP-CLI's database commands need the MySQL client …"**
Exporting, importing or querying the database runs the MySQL client, which the official `wordpress` image does not include. The engine runs those commands in a container of the project that has WordPress and the client, such as a `wordpress:cli` service with the same volumes and environment as the WordPress one. The engine's own WordPress recipe has one; redeploy a project made from it before that.

**"Could not remove the DEFINER clauses from …, so it was not imported"**
A database import first removes the `DEFINER` clauses that views, triggers and routines from another server carry, by rewriting the file where it is. The file has them, and the folder it is in cannot be written by the user the import runs as. Put the file in a folder the project can write to, and import it from there.

**"MySQL rejected … statement(s) in …, so it was not fully imported"**
MySQL refused one or more statements of the SQL file during a database import; the message lists the first of them with their line numbers. WP-CLI reports such an import as a success, so the engine reads the MySQL client's errors instead. The statements MySQL accepted are already in the database. Fix the file, or the missing privilege or object a listed line names, and import it again.

**"No running container of this project belongs to service …"**
The service name is not one the project runs. Use the service name from the project's Compose file.

**The install was refused.**
Either the project is not one the engine can install WordPress onto, or the admin password is shorter than 8 characters.

**"Insufficient permission to create directory …" when downloading WordPress.**
On traditional PHP hosting the top level of the account belongs to root, so no new folder can be made there. Download into a folder below one the account owns, such as the domain folder: `/example.com/blog`, not `/blog` or `/tmp/wp`.

**WordPress is up but shows the wrong address, or redirects to the old one.**
WordPress stores its site address in its own database. Changing the project's domain does not update it. Ask the assistant to run the WP-CLI command that updates the site URL.

**The site shows a blank page or a PHP error.**
On traditional PHP hosting, ask the assistant to read the project's PHP error log: [Monitoring and logs](monitoring-and-logs.md#3-domain-logs---what-are-visitors-doing). A WordPress site in its own container has no separate file, so the assistant reads what the app container printed instead.

**Every WordPress action fails with a PHP fatal error from one plugin or theme.**
WordPress loads all active plugins first, so one broken plugin stops the rest. Ask the assistant to do it again without loading plugins and themes: listing plugins, themes, users or options, reading or changing `wp-config.php`, maintenance mode, the database and the core status all work that way. Then deactivate or delete the broken plugin. A site that fails to load is reported as an error, never as "WordPress is not installed".

**The site still shows old pages after flushing the cache.**
The flush empties WordPress's object cache, then asks WP Super Cache, LiteSpeed Cache, W3 Total Cache, WP Rocket and WP Fastest Cache to purge their page caches. If the result says the page caches failed, purge them from that plugin's own settings. Another caching plugin, or a CDN in front of the site, is not covered.

**Plugin installs or updates fail from inside wp-admin.**
Usually file permissions, the account's disk quota, or PHP running out of memory. Ask the assistant to check the project's usage against its limits, raise `memory_limit` if needed, then run the update through WP-CLI instead.

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Examples assume a WordPress project named `twp`.

**Fresh WordPress**

```text
Set up WordPress on a new project called twp on this PanelAlpha Engine.
```

Mode: modify. Tools: `project_create`, `task_get`, `app_info`. Check: the project is created in its own container with its own database, and the WordPress setup wizard is not finished yet.

**What the engine knows about the app**

```text
What application is twp, what can the engine do with it, and has it been
installed yet?
```

Mode: read. Tools: `app_info`, `app_role_list`. Check: the answer names WordPress, lists what the engine can manage, and the roles come from the application. Before the install is finished, the roles and users are refused with "WordPress is not installed yet."

**Finish the install**

```text
Install WordPress on twp. Address: the project's address. Title: Test Site.
Admin user: admin. Admin email: admin@example.com. Generate a strong password
and show it to me once.
```

Mode: modify. Tools: `app_install`, `app_health_check`. Check: all five values were supplied, a password under 8 characters would be refused, and the site then serves WordPress instead of the installer.

**Log in without a password**

```text
Give me a one-click login link for the admin user of twp.
```

Mode: modify (held back in `readonly`). Tools: `app_user_list`, `app_user_sso_create`. Check: the link signs in straight to the admin, works once, and expires quickly.

**The generated login**

```text
What admin login did the engine create for twp?
```

Mode: modify. Tools: `app_credentials_get`. Check: the answer has the address, username and password, or says the application declares none. The password is not in the deploy log.

**Users of the application**

```text
On twp: list the users, add an editor with login jdoe and email
jdoe@example.com, reset that user's password, then delete the user.
```

Mode: full. Tools: `app_user_list`, `app_user_create` with `role`, `app_user_reset_password`, `app_user_delete`. Check: the new user has the editor role, the old password stops working after the reset, and the user is gone from the list.

**WP-CLI**

```text
On the traditional WordPress site tphpwp, run `wp core version`, then update
all plugins. Do the same on twp.
```

Mode: modify. Tools: `wp_cli_run` with `args`, `wp_plugin_update`. Check: the version comes back and the plugin update output is reported on both `tphpwp` (traditional PHP hosting) and `twp` (a container project).

**Import a WordPress site**

```text
Test whether https://example.com can be imported with the wp-admin user
admin, then import it and tell me which DNS record to set.
```

Mode: modify. Tools: `wordpress_migration_test`, `wordpress_migration_create`, `wordpress_migration_get`. Check: the test creates nothing and names the domain, the import runs to `completed`, and the status names the DNS record and, with PanelAlpha Connect, a `preview_url`.

**New WordPress on traditional PHP hosting**

```text
On the traditional PHP hosting project tphp, download WordPress in Polish,
create a database and a user for it, write wp-config.php, then install it.
```

Mode: modify. Tools: `wp_core_download` with `locale`, `mysql_database_create`, `mysql_user_create`, `mysql_privileges_set`, `wp_config_create`, `wp_core_install`, `wp_language_set`. Check: the download reports the version and language it fetched, and the same request on a directory that already holds WordPress is refused rather than overwritten. A project in its own containers is refused too: its WordPress comes from its image.

**Known apps and what the engine picks**

```text
Inspect https://github.com/matomo-org/matomo and tell me whether the engine
treats it as a known app or just as PHP.
```

Mode: read. Tools: `source_inspect`. Check: the answer names the known app and the stack, and nothing is deployed.

## From the server

Run WP-CLI on a project over SSH with `pae wordpress:command <project> <command>`, or use a named operation such as `pae wordpress:plugin:list <project>`. Import a site with `pae wordpress:migrate <url>`. `pae domain:wp-cli` still works but is deprecated. See [CLI commands](../06-commands/pae-cli.md#wordpress).
