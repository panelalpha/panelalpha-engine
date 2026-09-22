# Push to deploy

Instead of asking the assistant to pull and rebuild every time, you can have your git host call the engine itself: push to the tracked branch, and the project redeploys on its own. This is a Deploy Hook.

```text
Set up a push-to-deploy hook for this project. I'll be pushing from GitHub.
```

The assistant creates the hook and gives you a **URL** and a **secret**. The secret is shown this one time and cannot be shown again — copy both before you move on. If the assistant is unsure which git host you use, tell it (GitHub, GitLab, Bitbucket Cloud or Bitbucket Data Center); it narrows the setup notes it gives you to that one host.

Asking again later is safe: it returns the same URL without the secret, rather than creating a second hook.

## Register the hook in your git host

You do this part yourself, in your git host's own settings — the assistant does not have access to your GitHub, GitLab or Bitbucket account.

- **GitHub**: repository (or organization) **Settings > Webhooks > Add webhook**. Payload URL: the hook's URL. Content type: `application/json`. Secret: the hook's secret. Trigger: "Just the push event".
- **GitLab**: project **Settings > Webhooks**. URL: the hook's URL. Secret token: the hook's secret. Trigger: "Push events".
- **Bitbucket Cloud**: repository **Settings > Webhooks > Add webhook**. URL: the hook's URL. Secret: the hook's secret. Triggers: "Repository > Push". The secret is required — a delivery without it is rejected.
- **Bitbucket Data Center**: repository **Settings > Webhooks > Create webhook**. URL: the hook's URL. Secret: the hook's secret. Events: "Repository > Push".

Save it, then push a commit to the tracked branch and check the project updated.

## What a push does to the checkout

What happens depends on which checkout the hook is for:

- **The project's own checkout** (the one the engine deploys from — `project` on most setups). A push **force-updates it to match the repository** and rebuilds: any change made directly on the server to a tracked file, and any untracked file that is not one of the engine's own, is discarded first. Treat the repository as the only source of truth once a hook exists here.
- **A Site Git checkout** (for example `public_html` on a WordPress project connected to git for its theme or plugin code). A push only **fast-forwards** — files the repository does not know about, such as uploads or a config written by the admin, are left alone. If the checkout has diverged (a local edit to a tracked file, an untracked file in the way, or history that was rewritten), git refuses and **nothing changes**; the delivery's history says so, with the paths involved. There is no build here, just the files updating.

Ask the assistant which checkout a hook is for if you are not sure:

```text
Show me the push-to-deploy hook for this project's public_html checkout.
```

## If your git host can't reach it: TLS

A freshly installed engine with no public address serves a certificate it signed itself, which a git host does not trust by default. Creating or showing a hook tells you which situation you are in, under `tls`:

- `state: valid` — nothing to do, the git host will call the hook without complaint.
- `state: self_signed` — the response also lists what to change in each provider's webhook settings to call it anyway (GitHub and GitLab: turn off SSL verification for that one webhook. Bitbucket has no such switch, in either edition — the engine needs a trusted certificate first).

```text
Set up a push-to-deploy hook for this project. I'm registering it in Bitbucket Cloud.
```

narrows that advice to Bitbucket Cloud alone. The response also warns when the engine has no public address at all, in which case no git host on the internet can reach the hook regardless of the certificate.

The lasting fix, once you have a domain pointed at the VPS, is a trusted certificate rather than disabling verification per webhook: see [Install](../02-getting-started/install.md#troubleshooting) ("I want a real certificate on an engine that is already self-signed"). Until then, [trust the self-signed certificate](../04-connecting-your-ai/claude-code.md#trust-a-self-signed-engine-certificate) on your own computer only tells *your* assistant to accept it — it does nothing for GitHub, GitLab or Bitbucket, which need the provider-side change above.

## Did the push actually deploy?

Ask the assistant to show the hook. The answer includes:

- **`url` / `registered_url` / `url_changed_since_registration`** — `registered_url` is the address you last registered in your git host; `url` is the engine's current one. They differ once the engine's own address has moved on (an IP address a domain certificate later replaced, say), and `url_changed_since_registration` is `true` when that has happened — re-register the webhook at `url`.
- **`deliveries`** — the last 20 pushes this hook received, plus the last 5 requests it rejected (kept apart, so a flood of bad requests to the URL cannot push real deliveries out of the history), newest first. For each one:
  - `outcome` is what the request was answered with: `queued` (a deploy was started), `ignored` (a ping, another branch, a tag — no reason to deploy), `rejected` (the signature or provider did not check out), or `coalesced` (a push arrived while this project was already deploying and is waiting for that one to finish).
  - `result` is what a queued push actually reached: `deployed`, `partial` (the pushed code was deployed and started, but the app does not answer or answers with an error — `detail` says which), `deploy_failed`, `pull_refused` (a Site Git checkout would not fast-forward — see above), or `superseded` (a later push landed before this one's turn came). A push whose deploy was cut off half-way — the engine restarted under it, say — shows `deploy_failed` saying it was interrupted; push again.
  - `reason` and `detail` say why, in each case — the paths git refused on, or the build error.
  - `deploy_id`, when present, points at the full build log: `pae project:deploy:log <project> --id=<deploy_id>` (or ask the assistant to fetch it).

```text
Show me the last few deliveries on this project's push-to-deploy hook. Did the latest push deploy?
```

## Replacing or removing a hook

```text
Rotate the push-to-deploy hook for this project — I think the secret leaked.
```

Rotating swaps in a new URL and secret; the old URL stops answering immediately, so update the webhook in your git host with the new ones. Deleting removes the hook and its delivery history, and does not touch the checkout.

## From the server

The same operations from the command line: [CLI commands](../06-commands/pae-cli.md#repository-projects).

## Troubleshooting

**The webhook shows a failed delivery in my git host, but the assistant says nothing changed.**
Ask to see the hook's delivery history (above) — the `result` and `detail` on the matching delivery say what happened on the engine's side, which is more specific than a git host's own delivery log.

**I pushed and nothing happened at all.**
Check `outcome` on the newest delivery first. `ignored` most often means the push was to a branch the checkout does not track, or was a tag — check which branch the assistant says the checkout follows. If there is no delivery at all, the webhook likely is not calling the URL yet: confirm it is saved in your git host and, if `tls.state` was `self_signed`, that you made the provider-side change above.

**A Site Git push says `pull_refused`.**
Something on the server conflicts with what the push brought in — a file edited directly on the server, an upload sitting where the repository now wants to put a file, or history that was rewritten. The delivery's `detail` names the paths; resolve them (move the conflicting file aside, or reset the checkout) and push again.
