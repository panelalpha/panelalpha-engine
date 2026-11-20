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
- The engine's login reaches the app as `env_file` (`JENKINS_ADMIN_USER`,
  always `admin`, and `JENKINS_ADMIN_PASSWORD`).
- `files/panelalpha/panelalpha-login.groovy` is mounted as
  `/usr/share/jenkins/ref/init.groovy.d/panelalpha-login.groovy.override`; the
  image's `jenkins.sh` copies `*.override` ref files into `$JENKINS_HOME` on
  every start and Jenkins runs `init.groovy.d/` at boot. Once the boot has
  completed (the setup wizard makes its user `admin` during it), while `admin`
  still has the random password of `secrets/initialAdminPassword`, the script
  gives it the engine's password and writes the same into that file.
  Otherwise it does nothing, so a password changed in Jenkins is never
  overwritten.

## First run

Open the site: Jenkins shows its own **Unlock Jenkins** wizard. The
administrator password is the engine's `JENKINS_ADMIN_PASSWORD`: read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). The
random password Jenkins prints to the log no longer works.

Then install plugins and either create the first admin in the wizard (it
replaces `admin`) or skip that step and go on as `admin` with the engine's
password. Set the Jenkins URL to the site's https address when asked.

The inbound-agent port 50000 is not published: agents connect over WebSocket
(Manage Jenkins -> Nodes, "Use WebSocket").
