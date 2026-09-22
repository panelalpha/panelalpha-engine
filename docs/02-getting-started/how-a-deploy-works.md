# How a deploy works

When you ask your assistant to put a project online, the engine runs one process from start to finish. That process is a **deploy**. You stay in the chat. You do not pick a webserver, write a Dockerfile, or create a database first unless you want to.

```text
Deploy my application https://github.com/org/app on this PanelAlpha Engine.
```

What this does: the assistant creates a project and starts a deploy.

What you should see: progress in the chat, then a web address to open.

## What happens after you ask

**1. A project is created.** A project is one application or website on your VPS. It gets its own isolated container, so a broken app cannot take the others down.

**2. The engine fetches the code.** For git, it clones the repository. For a zip, it unpacks the files you pointed at. A private repository needs a git token first, pasted on a page the assistant sends you, not in the chat: [Connecting with Git](../05-capabilities/connecting-with-git.md).

**3. It works out what the application is.** Laravel, Next.js, Django, Rails, Go, WordPress, a plain `Dockerfile` or `Containerfile`, and many more. It also reads which language versions your project asks for. The file that identifies the application (`package.json`, `composer.json`, `go.mod`, and so on) has to sit at the top level of the repository: [What your repository needs](../07-supported-projects/what-your-repo-needs.md).

**4. It builds and starts the application.** Dependencies are installed, the application is built, and it is started inside that container.

**5. It gives the project an address and HTTPS.** The engine's webserver sends that hostname to the container and requests a free Let's Encrypt certificate. If you did not name a domain, it picks a public name and tells you what it chose. Do not name a domain unless that domain already points at this VPS. Naming one that is not set up yet turns a working deploy into a failed one: [Looking after your project](looking-after-your-project.md).

**6. It checks that the address is really your application.** The engine opens the project from inside the account and checks that what answers is your app, not a placeholder page or an error.

If nothing answered, or the hostname cannot be opened from the internet, the deploy finishes as **partial** rather than successful and says in plain sentences what is wrong. See [Monitoring and logs](../05-capabilities/monitoring-and-logs.md) and [When a deploy fails](what-happens.md).

## What you do next

Open the address in a browser.

- **It opens and looks right.** The first deploy is done. Add your own domain, take a backup, or make a test copy: [Looking after your project](looking-after-your-project.md).
- **The address ends in `.local`.** Nobody on the internet can open that project. The application is fine; it has no public name. Point DNS at your VPS, then add a domain: [Domains and HTTPS](../05-capabilities/domains-and-ssl.md).
- **The deploy failed, or came back "partial".** [When a deploy fails](what-happens.md).
- **It opens but is not your application** (a placeholder, a directory listing, an error page). Ask:

```text
This project does not open properly. Read the deploy log, check what it is
actually serving, and tell me what is wrong.
```

## Deploying again later

At the end of a successful deploy the engine saves the exact plan it used. A **rebuild** replays that saved plan, so it runs what shipped last time rather than deciding again from scratch. Once a project is deployed from a repository, updating it is a rebuild.

```text
Rebuild this project.
```

If the project is connected to git, ask the assistant to pull the latest commit. That pull rebuilds the site: [Connecting with Git](../05-capabilities/connecting-with-git.md).
