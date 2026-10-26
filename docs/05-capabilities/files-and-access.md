# Files, FTP and SFTP

Each project's files live in that account's home directory. For repository deploys, the application itself is in a subdirectory called `project`.

**That subdirectory matters more than anything else on this page.** Files placed at the top level of the account are not served by the webserver. "I uploaded my site and nothing changed" is nearly always this.

## Through your assistant

```text
Upload this archive into the project and deploy it: /path/to/app.zip
```

```text
Write these files into the project and rebuild.
```

The assistant can upload a file, write files directly, download a file from an address, or deploy a whole archive. An archive goes through the deploy process: it is unpacked into `project`, identified, and started, just like a repository would be.

```text
Download https://example.com/plugin.zip into /project on this project.
```

The file is saved into a directory that already exists. Only an `http` or `https` address works, and an address that points at the server itself or at a private network is refused. A file over the size limit (2 GB) is not kept: nothing is left behind. This does not deploy the file. On a site deployed from an archive or from files, say "and rebuild" if the running site should pick it up.

```text
List what is in /project on this project.
```

The assistant sees each entry's name, type, size, change time and permissions, folders first. Links are shown as links, with where they point. It reads the folder as the project's own account, so a path that leads out of the account's home, or a path that is a file, is refused. A very long folder is cut off after 5000 entries by default, and the answer says so.

```text
Set the permissions on /project/script.sh to 755.
```

That changes that one file or directory. It does not walk into folders inside it. The mode is three or four digits from 0 to 7, such as `755` or `0644`.

```text
Move everything directly inside /project/incoming into /project.
Leave files that are already there.
```

Only the immediate children move. The destination directory has to exist already. If you do not say to leave existing files, a file with the same name is replaced.

**After changing files on a site deployed from an archive or from files, rebuild.** The running application does not pick up changed files on its own. It is running the version that was built. Say "and rebuild" as part of the request and it is handled.

**A project connected to git is different.** A rebuild clones its branch again, so a file written into `/project` is gone afterwards. Commit the change to the repository and ask for a pull instead. Data the application writes, in its volumes or a database, is kept across rebuilds, and so is what it writes under container paths named in the project's `persist-paths` setting ([CLI commands](../06-commands/pae-cli.md)).

## FTP and SFTP

To let someone manage a project's files directly, ask your assistant. If it cannot create an account, see [Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

```text
Create an FTP account on this project for user ftpuser1 on shop.example.com,
with directory /project.
```

The directory must already exist inside the project's home: `/project` on a repository-deployed project, `/public_html` on traditional PHP hosting. A folder that does not exist is refused.

An FTP account needs a password of at least 8 characters, and the engine does not generate one. Do not type it into the chat: the assistant sends a paste page for it, or makes one up and shows it to you. An SFTP account takes a password the same way, or a public key. You get credentials to hand over. Prefer SFTP where the software at the other end supports it - plain FTP sends the password unencrypted.

An SFTP login can only move files. It cannot open a shell, and it sees only that project's files.

How many accounts a project may have comes from its plan limits.

Deleting an SFTP account also ends the sessions it has open. To cut someone off without deleting the account, for example after a password leaked, ask the assistant to end the sessions:

```text
End every open SFTP session of the account tshop_sftp1 on this project.
```

To end the sessions of every SFTP account on the project at once, say "all SFTP sessions on this project". The answer says how many were open. People can log in again straight away unless you also change the password or delete the account.

**Upload into `/project/`**, not into `/`. Anything at the top level is outside what the webserver serves. For traditional PHP hosting (including most existing WordPress sites) the folder is `/public_html` instead, and those files are served as soon as you upload them. Ask the assistant to inspect the project if you are unsure which applies.

## Troubleshooting

**"I uploaded my files and the site did not change."**
Two possible causes, in order of likelihood. Either the files went to the account's top level rather than into `project/` (or `public_html` on traditional PHP hosting). Or this is a site deployed from an archive or from files and it needs a rebuild - the running application is still the previously built version. On a project connected to git, a rebuild replaces the uploaded files with the repository's, so the change has to go into the repository. Traditional PHP hosting, including WordPress in `public_html`, does not need a rebuild after an upload.

**"Permission denied" on upload.**
The path is outside the account's home directory, or the account has hit its disk quota. Ask the assistant to check the project's usage against its limits.

**The FTP account connects but shows an empty folder.**
It is looking at the directory it was configured with. Check that against where the site's files actually are - for repository deploys, `/project`.

**I deployed an archive and the site is showing a directory listing.**
Your archive had a wrapper folder inside it, so the site's files ended up one level too deep. Repackage it with the files at the top level of the zip.

**Unpacking an archive fails.**
The engine checks every entry in the archive before it unpacks. It refuses an archive with absolute paths, paths that climb out of the folder, links that point outside it, or more than 100,000 entries or 5 GB once unpacked. It unpacks as the project's own account, so it cannot write where that account cannot.

## More requests to try

**Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Examples assume a project named `tshop`.

**Look before touching**

```text
In tshop, list /project. Then tell me if /project/index.html exists, show its
size and permissions, and show me the first 200 bytes.
```

Mode: read, and modify for the last part. Tools: `file_list` with `show_hidden` and `limit`, `file_exists`, `file_stat`, `file_download` with `offset` and `length`. Check: the three answers agree, and the download returns only the range asked for. `file_download` can hand out files that hold passwords, so a read-only token does not get it. A path outside the home, or a file given to `file_list`, is refused.

**Write, upload, fetch**

```text
In tshop, create the folder /project/notes, write hello.txt into it with the
text "test run", upload a small file named upload.txt, and download
https://example.com/robots.txt into the same folder.
```

Mode: modify. Tools: `file_mkdir` (with `parents`), `file_write`, `file_upload`, `file_fetch`. Check: all three files exist afterwards with the content asked for. A non-http address for the fetch is refused, and so is an address on the server itself or a private network.

**Move, copy, rename**

```text
In tshop, copy /project/notes/hello.txt to /project/notes/hello-copy.txt, rename
the copy to greeting.txt, and move everything directly inside /project/notes
into /project/archive. Keep files that are already there.
```

Mode: modify. Tools: `file_copy`, `file_move`, `file_move_contents` (`override` false), `file_mkdir`. Check: only the immediate children moved, the destination folder existed first, and an existing file with the same name was not replaced.

**Permissions**

```text
In tshop, set /project/notes/hello.txt to mode 640, then to 999.
```

Mode: modify. Tools: `file_chmod`, `file_stat`. Check: 640 is applied and read back. 999 is refused: a mode is three or four digits from 0 to 7.

**Archive and unpack**

```text
In tshop, zip /project/notes into /project/notes.zip, then unzip it into
/project/restored.
```

Mode: modify. Tools: `file_zip`, `file_unzip`, `file_exists`. Check: the zip exists, and the unpacked folder has the same files. An archive with an entry that climbs out of the folder is refused. Unpacking never replaces or merges into what is already there: if the folder already holds a file or folder of the same name as one the archive unpacks to at its top level (for a `.gz`, the unpacked file), the request is refused with 409 and nothing is extracted. Unpack into a new or empty folder instead; a folder that does not exist yet is created.

**Delete**

```text
In tshop, delete /project/restored and everything in it.
```

Mode: full. Tools: `file_delete` with `recursive`. Check: the assistant asks first, and the folder is gone. Without `recursive`, a non-empty folder is not deleted.

**Change a file and have the site follow**

```text
In tshop, write "maintenance" into /project/public/status.txt and rebuild so
the running site picks it up.
```

Mode: modify. Tools: `file_write`, `project_rebuild`, `task_get`. Check: on a project deployed from an archive or from files, the rebuild is part of the answer and the file is served afterwards. On a project connected to git, the assistant says a rebuild clones the repository again and would delete the file, and suggests committing it instead. Traditional PHP hosting needs no rebuild.

**FTP**

```text
Give tshop an FTP account ftpuser1 for shop.example.com with directory /project,
a 50 MB quota and a strong password you generate. Then raise it to unlimited,
change its password, and delete it.
```

On traditional PHP hosting, ask for directory /public_html instead: `/project` does not exist there.

Mode: full. Tools: `ftp_account_create`, `ftp_account_list`, `ftp_account_update`, `ftp_account_delete`. Check: a password of at least 8 characters is sent, since the engine makes none up, the credentials come back once, and the quota reads back as 50 and then with `unlimited_quota: true`. An unlimited account's `quota` is not 0: it shows the project's disk space limit, or -1 when that is unlimited. The account is gone from the list at the end.

**SFTP with a password and with a key**

```text
Give tshop an SFTP account tshop_sftp1 with a password. Then change it to key
login using this public key: ssh-ed25519 AAAA...example. Delete it afterwards.
```

Mode: full. Tools: `sftp_account_create`, `sftp_account_list`, `sftp_account_update` with `auth_method`, `sftp_account_delete`. Check: an SFTP account name is the project's name, an underscore and a suffix (`tshop_sftp1`), and any other name is refused. After the update the account accepts the key and not the password. Prefer SFTP in the answer over plain FTP.

**End SFTP sessions**

```text
End the open SFTP sessions of tshop_sftp1 on tshop. Then end every SFTP session on tshop.
```

Mode: modify. Tools: `sftp_session_kill`, `sftp_session_kill_all`. Check: each answer says how many sessions were open, and a connected client is dropped. The account still exists and can log in again.

**A command inside the project**

```text
Run `ls -la /project | head` inside the tshop container and show me the output.
```

Mode: modify. Tools: `ssh_run` with `cwd` and `timeout`. Check: pipes work, a non-zero exit is reported in the answer rather than as an error, and the same request on traditional PHP hosting is refused.

## From the server

Copying single files in and out: [CLI commands](../06-commands/pae-cli.md#files).
