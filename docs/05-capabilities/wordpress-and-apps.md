# WordPress and known apps

WordPress is a main way to use the engine, not an extra. Many hosting accounts on it are WordPress sites. You can ask the engine to set up a new one without bringing your own code, deploy one you already keep in git, or look after a WordPress site that is already running here.

This page covers all three, then day-to-day work: logging in, users, WP-CLI, and the other known apps the engine treats specially.

## If WordPress is already on this engine

Do not install it again. Ask the assistant to list your projects, then work on the one you mean.

```text
List the projects on this engine.
```

```text
Inspect this project. Tell me if it is WordPress, and where its files are.
```

From there you can add a domain, take a backup, change PHP, run WP-CLI, or manage WordPress users, using the requests later on this page.

There are two kinds of WordPress on this engine, and they are not the same:

- **Traditional PHP hosting.** Files live in `public_html`. Uploads show up on the site without a rebuild. You can change that domain's PHP version, set PHP memory, use FTP, backups, domains and WP-CLI. WordPress users, the install wizard and one-click login are not available on this kind. Neither is a Cloudflare tunnel.
- **Its own container.** Files live in `project/`. This is what you get when the engine sets WordPress up for you, or when you deploy it from git. WordPress users, one-click login and the install wizard work here, as do WP-CLI, backups and domains.

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

Plugin installs that fail for lack of memory are often a PHP limit. Raise it, then try WP-CLI again:

```text
Set PHP memory_limit to 256M on this project.
```

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

For a site the engine set up, you can manage its WordPress users from the chat:

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

**"App management is only available for this kind of project."**
The WordPress user, install and one-click-login actions work on a WordPress site in its own container. A WordPress site on traditional PHP hosting can still be managed with WP-CLI, and you can still change its PHP version.

**The install was refused.**
Either the project is not one the engine can install WordPress onto, or the admin password is shorter than 8 characters.

**WordPress is up but shows the wrong address, or redirects to the old one.**
WordPress stores its site address in its own database. Changing the project's domain does not update it. Ask the assistant to run the WP-CLI command that updates the site URL.

**Plugin installs or updates fail from inside wp-admin.**
Usually file permissions, the account's disk quota, or PHP running out of memory. Ask the assistant to check the project's usage against its limits, raise `memory_limit` if needed, then run the update through WP-CLI instead.

## From the server

Run WP-CLI on a site over SSH with `pae domain:wp-cli <domain> <command>`. See [CLI commands](../06-commands/pae-cli.md).
