# Push to deploy

Instead of asking the assistant to pull every time, you can have your git host call the engine itself: push to the tracked branch, and the project redeploys on its own. This is a Deploy Hook.

```text
Set up a push-to-deploy hook for this project. I'll be pushing from GitHub.
```

The assistant creates the hook and gives you a URL and a secret. The secret is shown this one time. Copy both before you move on. Name the git host (GitHub, GitLab, Bitbucket Cloud, or Bitbucket Data Center) and the certificate notes are only for that one.

Asking again later returns the same URL and does not show the secret again. It does not create a second hook. The project, or the folder you name, has to be connected to git already, or the assistant refuses.

## Register the hook in your git host

You do this in your git host. The assistant cannot open your GitHub, GitLab, or Bitbucket account.

The assistant can ask your git host to add the webhook for you, with the token the project clones with. If the git host refuses, because the project has no token or the token may not manage webhooks, the hook is still created and the refusal still gives you its URL and secret, this one time. Copy them and add the webhook yourself as below. The token needs the right to manage webhooks: `admin:repo_hook` on GitHub, Maintainer on GitLab, admin on Bitbucket. The refusal says why: `provider_token_missing` when the project has no token, `provider_permission_denied` when the token may not do it, `host_refused` when the git host is not on a public address, and 502 when the git host could not be reached. A git host the engine has no webhook API for gets the hook without a registration and a `registration_warning`. Deleting the hook also removes the webhook the assistant registered; disconnecting the repository does not.

- **GitHub**: repository or organization **Settings > Webhooks > Add webhook**. Payload URL: the hook's URL. Content type: `application/json`. Secret: the hook's secret. Trigger: "Just the push event".
- **GitLab**: project **Settings > Webhooks**. URL: the hook's URL. Trigger: "Push events". On GitLab 19.0 or later, put the hook's secret in **Signing token** if GitLab lets you paste one there: every call is then signed, and a recorded call cannot be sent again with another body. Otherwise put it in **Secret token**. Never put it in both: the secret token travels in every call as plain text. A hook the assistant registers for you is set up signed, unless the hook was made before engine 2.2: rotate such a hook to have its calls signed. On a self-managed GitLab served under a path, such as `https://git.example.com/gitlab`, the engine finds that path by asking the instance where its API answers.
- **Bitbucket Cloud**: repository **Settings > Webhooks > Add webhook**. URL: the hook's URL. Secret: the hook's secret. Triggers: "Repository > Push".
- **Bitbucket Data Center**: repository **Settings > Webhooks > Create webhook**. URL: the hook's URL. Secret: the hook's secret. Events: "Repository > Push".

Put the secret in on every host. A call that does not send it, or sends one that does not match, is refused. Once a GitLab hook has sent one signed call, a call that is not signed is refused.

Save it, then push a commit to the branch the project follows and check the project updated.

## What a push does

The hook follows the branch that copy is on now. A later change of branch is followed too. A test ping, a tag, a deleted branch, or a push to any other branch does nothing. The same delivery sent again does not deploy a second time, and neither does the same signed push sent again under a new delivery id.

What the push changes depends on which copy the hook is for:

- **The copy the engine deploys from.** A push makes that copy match the repository, then rebuilds the site. A tracked file you edited on the server is replaced. A file the repository does not know about is removed, except files the engine keeps for itself. Submodules are fetched at the commits the push records. Once the site has deployed, one the last deployed commit already had keeps whatever copy it had when it cannot be fetched, and the deploy log warns; one the push adds, or any on the first deploy, fails the deploy: [Connecting with Git](connecting-with-git.md#troubleshooting). Once this hook exists, the repository is the only source of truth for that copy.
- **A folder the site keeps in git**, such as `public_html` on a WordPress project. A push only moves that folder forward. Uploads and a config the site wrote stay. There is no rebuild. If something on the server conflicts with the push (a tracked file edited on the server, a file sitting where the repository now wants to put one, or history that was rewritten), nothing changes. The answer names the paths.

```text
Show me the push-to-deploy hook for this project's public_html folder.
```

A push that arrives while a deploy is already running waits. When that deploy finishes, the engine runs one more, from the latest push that waited. An earlier waiting push does not get a deploy of its own.

If the site was already running and the new version fails, or the deploy is cancelled, the site keeps serving the previous version and the copy on the server goes back to the commit it was on: [Deploying again later](../02-getting-started/how-a-deploy-works.md#deploying-again-later). If the site was not running, the new commit stays in the copy on the server. If the engine stops in the middle of the deploy, that push is marked failed and says it was interrupted. Push again, or ask for a rebuild.

## If your git host can't reach it

When the assistant creates or shows the hook, it also says whether a git host can call it.

If the engine's certificate is one a git host trusts, nothing else is needed.

If it is not (the engine signed it itself, it has expired, it is for a different name, or there is no certificate to show), the assistant says what to change for each host:

- **GitHub**: on that webhook, set "SSL verification" to Disable. This applies to this one webhook only.
- **GitLab**: when adding or editing the webhook, clear "Enable SSL verification" before saving.
- **Bitbucket Cloud**: there is no switch. Bitbucket always checks the certificate. Give the engine a trusted certificate for a domain first, then register the webhook against that domain.
- **Bitbucket Data Center**: in the webhook's advanced settings, turn certificate verification off for this webhook. Older releases have no such option and need a trusted certificate, the same as Bitbucket Cloud.

If the engine has no public address, the assistant says so separately. No git host on the internet can reach the hook, whatever the certificate is.

The lasting fix, once a domain points at the VPS, is a trusted certificate: [Install](../02-getting-started/install.md#troubleshooting), under "I want a real certificate on an engine that is already self-signed". Trusting the certificate on your own computer only tells your assistant to accept it. It does nothing for GitHub, GitLab, or Bitbucket.

## Did the push actually deploy?

```text
Show me the last few deliveries on this project's push-to-deploy hook. Did the latest push deploy?
```

The assistant keeps the last 20 pushes, and separately the last 5 calls it refused, so a run of bad calls cannot push real pushes out of the list. For each one it says what it did with the call, and how a deploy that started then ended.

A call was started, skipped, refused because the signature did not match, or left waiting because a deploy was already running. A started deploy then ends as deployed, started but not answering or answering with an error, failed, refused on a site folder (nothing on the server changed), or dropped because a later push took its place. Ask the assistant for the build log when you need the full deploy, not only this summary.

If the engine's address has changed since you saved the webhook, the assistant gives you the address to register now. Your git host is still calling the old one until you do.

## Replacing or removing a hook

```text
Rotate the push-to-deploy hook for this project. I think the secret leaked.
```

Rotating gives a new URL and a new secret, shown this once. The old URL stops answering immediately, so update the webhook in your git host. The history of earlier pushes stays. A webhook the assistant registered for you is updated with the rotation. If your git host refuses that or cannot be reached, the hook is rotated anyway, and you get the new URL and secret to enter in the git host yourself.

Deleting removes the hook and that history. It does not change the files on the server. Remove the webhook in your git host as well, or it keeps calling an address that no longer answers. Deleting the project removes its hooks too.

## More requests to try

These assume a project connected to git. **Mode** is the lowest permission mode that allows it: read, modify or full ([Decide what the assistant may do](../04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)). Examples assume a project named `tshop`.

**Create the hook**

```text
Set up a push-to-deploy hook for tshop. I'll be pushing from GitHub.
```

Mode: modify. Tools: `git_deploy_hook_create` with `provider`. Check: a URL and a secret come back, and the assistant tells the user to copy the secret now. A project that is not connected to git is refused.

**Asking again changes nothing**

```text
Show me the push-to-deploy hook for tshop. Create it again.
```

Mode: modify. Tools: `git_deploy_hook_show`, `git_deploy_hook_create`. Check: the URL is the same, the secret is not shown a second time, and no second hook exists. The `tls` state and `url_changed_since_registration` are reported.

**A push deploys**

```text
I pushed a commit to the branch tshop follows. Show me the last deliveries on
its hook. Did that push deploy?
```

Mode: read. Tools: `git_deploy_hook_show`, `deploy_log_get`. Check: the delivery is listed with its outcome. A push to another branch, a tag, or the same delivery sent twice does not start a second deploy.

**Replace and remove**

```text
Rotate the push-to-deploy hook of tshop, I think the secret leaked. Later,
delete it.
```

Mode: full. Tools: `git_deploy_hook_rotate`, `git_deploy_hook_delete`. Check: after the rotate the old URL answers 404 and a new URL and secret are given once. After the delete the URL answers 404, and the assistant reminds the user to remove the webhook in the git host.

## From the server

The same operations from the command line: [CLI commands](../06-commands/pae-cli.md#repository-projects).

## Troubleshooting

**The webhook shows a failed delivery in my git host, but the assistant says nothing changed.**
Ask to see the hook's delivery history. That answer is more specific than the git host's own log: it names the branch, and why a site folder was left as it was.

**I pushed and nothing happened at all.**
Look at the newest delivery first. A skip most often means the push was to a branch this copy does not follow, or was a tag. Ask which branch the copy follows. If there is no delivery at all, the webhook is not calling the URL yet: confirm it is saved in your git host and, if the certificate was not trusted, that you made the change for that host above.

**A push to a site folder changed nothing.**
Something on the server conflicts with what the push brought in. The delivery names the paths. Move the conflicting file aside, or reset that folder, and push again.

**A delivery comes back with "Too many failed signatures for this hook."**
The secret in the webhook does not match, or the call is not from a host the engine accepts. Fix the webhook, then wait a minute before trying again.
