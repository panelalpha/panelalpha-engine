# Databases

Each project can have its own MySQL databases and database logins, on the engine's database server.

Ask your assistant rather than opening a MySQL shell as root. The engine tracks what it created, applies the project's naming rules, and counts it against the project's plan limits - none of which happens if you create things by hand.

## Creating a database

```text
Create a MySQL database named shop on this project, create a user for it,
and grant that user access.
```

Three things happen: the database is created, a login is created, and that login is given permission to use that database. **All three are needed.** A newly created database user has no access to anything until permissions are granted, which is the usual reason a fresh setup fails to connect.

## Two things that catch everyone out

**1. The names get a prefix.** Ask for `shop` and you get something like `myshop_shop`. Every project has its own prefix so two projects can both have a database called `shop` without colliding.

**Give your application the prefixed name the engine reports back**, not the short name you asked for. "Unknown database 'shop'" is almost always this.

**2. The database host is not `localhost`.** Your application runs in its own container; the database server runs elsewhere. `localhost` and `127.0.0.1` inside your application point at your application, where there is no database.

```text
Give me the MySQL connection details for this project.
```

Use the host and port that comes back, in your application's environment variables: [Environment variables](projects.md#environment-variables).

## phpMyAdmin

To browse a database in a web interface, ask for a sign-on link. A new install exposes that. If the assistant cannot give you one, those tools have been turned off: [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

```text
Give me a phpMyAdmin sign-on link for this project.
```

You get a URL that logs you straight in. **It expires after five minutes.** Do not bookmark it - when it stops working, ask for a new one, which takes a second. A suspended project gets no sign-on link.

phpMyAdmin opens on the project's main domain. On a free `panelalpha.online` address an **import never gets through**: PanelAlpha Online's proxy, in front of that name, does not pass a `multipart/form-data` body on - the way a browser sends a file - so the POST hangs until the browser gives up and phpMyAdmin ends with "Some error occurred while getting SQL debug info. Error text: error (rejected)". It is not phpMyAdmin's alone: **every upload on such a name fails the same way** - a media file in WordPress, any form that posts a file. Pages, downloads and a form that posts only plain fields work. To upload, use a name that points straight at this server - see [Troubleshooting](#troubleshooting).

## Renaming a database

```text
Rename the database shop on this project to store.
```

The new name gets the project's prefix, like any new database. The data is moved, every user keeps the access it had, and the old name disappears. If anything fails along the way, the old database is kept as it was. Update your application to the new prefixed name afterwards, and rebuild.

A database that holds only tables is renamed in one quick step. **A database that also holds views, triggers, stored routines or events is copied instead, and for that time its users lose access to the old name and their open connections are closed.** A write from your application during the copy fails with an error, so nothing is lost without a sign. Access is put back if the rename fails. Do it when the site is quiet.

The application database that the engine creates for a container project (its name ends in `app`) cannot be renamed. A name that another database of the project already uses is refused. So is a rename while a push between the project and its staging copy is running, and a push while a rename is running.

## Copies of a project

On a traditional PHP project, a staging copy or a clone brings the databases with it, under the new project's prefix, with new users and passwords and the same permissions. A push between the two replaces the databases on the other side, or only the tables you pick, and keeps that side's users and passwords. Tables whose structure differs only in their AUTO_INCREMENT counter count as the same. A push with an empty list of tables is refused: leave the list out to push the whole database. A container project's databases are not copied. See [Staging](projects.md#staging) and [Cloning](projects.md#cloning).

## Limits

Passwords must be at least 8 characters. Each project's plan caps how many databases it may have.

## When your application needs a database at deploy time

Some applications cannot start without a database and cannot ask for one interactively. For well-known applications the engine recognises, it creates one during the deploy automatically.

For a general repository, it will not guess. Create the database first, then give your application its details as environment variables: [Environment variables](projects.md#environment-variables).

## Troubleshooting

**"Unknown database" or "database does not exist".**
You gave the application the short name instead of the prefixed name the engine returned. See [above](#two-things-that-catch-everyone-out).

**"Cannot connect to MySQL server".**
Your application is pointed at `localhost`. Ask the assistant for the connection details and use the host it gives you.

**"Access denied for user".**
The user exists but has no permission on that database, or the password does not match. Ask the assistant to grant access, or reset the password.

**The application cannot log in after a rebuild, and used to work.**
The database still holds the password it was first created with, while your application now has a different one. Changing the environment variable does not change the password inside the database. See [Reading errors](../02-getting-started/reading-errors.md).

**A rename is refused.**
The name is already used by another database of the project, is reserved, or is too long once the prefix is added. A container project's own application database (ending in `app`) cannot be renamed.

**"Database limit reached".**
The project has used up its plan's database allowance. Either raise the limit ([Limits](projects.md#limits)) or delete a database it no longer needs.

**phpMyAdmin gives a 404, or loops back to the login page.**
The sign-on link has expired - they last five minutes. Ask for a fresh one. If a new link does the same, the project's database user is missing; recreate it, then request the link again.

**An upload fails on a `panelalpha.online` address.**
In phpMyAdmin an import ends with "Some error occurred while getting SQL debug info. Error text: error (rejected)"; a media file in WordPress spins and then fails; the engine's log of that name holds no request that carried the file. PanelAlpha Online's proxy, in front of every `panelalpha.online` name, does not pass a request whose body is `multipart/form-data` on - the way a browser sends a file - so the POST hangs until the browser gives up and the site never sees it. Every upload there fails for that reason, not only phpMyAdmin's: a page, a download, and a form that posts plain fields without a file, work. Add a name that points straight at this server - a domain you own, or a `panelalpha.direct` name for it ([Adding your own domain](domains-and-ssl.md#adding-your-own-domain)) - make it the project's main domain ([Moving a project to another domain](domains-and-ssl.md#moving-a-project-to-another-domain-or-https-port)), and upload there, where the same file goes through at once.

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Examples assume a project named `tshop`.

**A working database**

```text
Create a MySQL database named shop on tshop, create a user shopuser for it with
a strong password, and give that user full access. Then give me the details my
application needs.
```

Mode: modify. Tools: `mysql_database_create`, `mysql_user_create`, `mysql_privileges_set`, `mysql_server_info`. Check: the database and user names come back with the project's prefix, and the assistant hands over those prefixed names and the host and port, not `localhost`.

**Look at what exists**

```text
List the databases and database users of tshop. Show the details of the
database shop and of the user shopuser, and what shopuser may do on shop.
```

Mode: read. Tools: `mysql_database_list`, `mysql_database_get`, `mysql_user_list`, `mysql_user_get`, `mysql_privileges_get`. Check: the privileges read back as the ones granted when the database was created.

**Narrow, then remove, access**

```text
Make shopuser read-only on shop. Then take all its access to shop away.
```

Mode: full. Tools: `mysql_privileges_set` with a read-only list, `mysql_privileges_get`, `mysql_privileges_revoke`. Check: after the first call the user can read and not write. After the revoke the user exists and has no access.

**Password and name of a user**

```text
Change the password of shopuser to a new strong one, rename the user to
shopowner, and tell me what my application must change.
```

Mode: modify. Tools: `mysql_user_change_password`, `mysql_user_rename`. Check: the assistant says the application's settings must be updated and a rebuild is needed, and that changing the variable alone does not change the password inside the database. A password under 8 characters is refused.

**Browse in phpMyAdmin**

```text
Give me a phpMyAdmin sign-on link for tshop.
```

Mode: modify. Tools: `phpmyadmin_sso_token_create`. Check: the link logs in straight away and the assistant says it expires after five minutes.

**The limit**

```text
Set tshop's database limit to 1, then create a second database.
```

Mode: modify. Tools: `project_update`, `mysql_database_create`. Check: the second database is refused as "limit reached", and the assistant points at raising the limit or deleting one.

**Rename a database**

```text
Rename the database shop on tshop to store. Then list its databases and what
shopuser may do on store.
```

Mode: full. Tools: `mysql_database_rename` with `new_dbname`, `task_get`, `mysql_database_list`, `mysql_privileges_get`. Check: the rename runs as a task, the new name has the project's prefix, shopuser keeps its access on the new name, and the old name is gone. On a container project the application's own database (ending in `app`) is refused.

**Rename and push do not overlap**

```text
Rename the database shop on tphpsite to store while a push to its staging copy
is running.
```

Mode: full. Tools: `mysql_database_rename`, `project_push`, `task_get`. Check: while the push runs the rename is refused as busy, and the other way round. Skip when you cannot start both within the push's run time.

**Push selected tables**

```text
Pick one table of a database on tphpsite and push only that table to
tphpstage. Then try to push with an empty list of tables.
```

Mode: modify. Tools: `project_push` with `tables`. Check: only that table is replaced on the other side, and the empty list is refused with nothing replaced.

**Database size**

```text
How big is each database of tshop?
```

Mode: read. Tools: `mysql_database_list`. Check: a database that holds data reports a size above 0, not 0.

**Remove**

```text
Delete the user shopowner and the database shop from tshop.
```

Mode: full. Tools: `mysql_user_delete`, `mysql_database_delete`, `mysql_database_list`. Check: the assistant asks first, and both are gone from the lists.

## From the server

Databases have no `pae` command of their own. Ask your assistant, or use phpMyAdmin.
