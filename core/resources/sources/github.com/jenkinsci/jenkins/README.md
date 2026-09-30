# Jenkins (github.com/jenkinsci/jenkins)

The open-source automation server (Java).

## What the recipe does

- The repository is Jenkins core's Maven source tree; a plain deploy took its
  web-UI `package.json` for a Vite site and failed (`dist/index.html is
  missing`).
- `overrides/docker-compose.yml` runs the official
  `jenkins/jenkins:2.568.3-lts-jdk21` image on port 8080, with
  `/var/jenkins_home` on the named volume `jenkins_home` (kept across
  redeploys). `ready` makes `compose up -d` wait until `/login` answers.
- The JVM heap is capped at 60% of the 1.5 GB container limit.

## First run

Open the site: Jenkins shows its own **Unlock Jenkins** wizard. The
password is in the app container's log and in
`/var/jenkins_home/secrets/initialAdminPassword`:

```
docker compose -p project exec app cat /var/jenkins_home/secrets/initialAdminPassword
```

Then install plugins and create the first admin in the wizard. Set the Jenkins
URL to the site's https address when asked.

The inbound-agent port 50000 is not published: agents connect over WebSocket
(Manage Jenkins -> Nodes, "Use WebSocket").
