<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>Dein KI-Agent betreibt<br>deinen Server. Unter Kontrolle.</h1>

<h3>
Open Source. Self-hosted. Einfach für alle, nicht nur für Sysadmins.
</h3>

<h3>
<a href="#drei-einfache-schritte-zur-einrichtung"><b>Loslegen</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>Dokumentation</b></a> ·
<a href="#sprich-mit-uns-auf-discord"><b>Discord</b></a>
</h3>

<p>
<a href="#worum-du-einfach-bitten-kannst">KI-Steuerung</a> ·
<a href="#alles-was-du-für-den-produktiven-einsatz-brauchst-direkt-dabei">Funktionen</a> ·
<a href="#telemetrie">Telemetrie</a> ·
<a href="#faq">FAQ</a>
</p>

<p>
<a href="README.md">English</a>
· <a href="README.pl.md">Polski</a>
· <b>Deutsch</b>
· <a href="README.nl.md">Nederlands</a>
· <a href="README.es.md">Español</a>
· <a href="README.fr.md">Français</a>
· <a href="README.it.md">Italiano</a>
· <a href="README.pt-BR.md">Português</a>
· <a href="README.uk.md">Українська</a>
· <a href="README.ar.md">العربية</a>
· <a href="README.zh-CN.md">简体中文</a>
</p>

<p>
<a href="#schritt-1-engine-auf-deinem-vps-installieren"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="Installation mit einem Befehl"></a>
<a href="#schritt-1-engine-auf-deinem-vps-installieren"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="Unterstützte Systeme"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-199_tools-6f42c1" alt="199 MCP-Werkzeuge"></a>
<a href="#lizenz"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="Discord beitreten"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="Ein Projekt per Chat ausrollen" width="760"></p>

</div>

---

## Was ist PanelAlpha Engine?

PanelAlpha Engine ist Software, die du auf deinem VPS installierst, um mit KI gebaute und vibe-coded Projekte, Websites und Open-Source-Apps zu hosten, die du online gefunden hast. Nach der Installation verbindest du deinen eigenen KI-Agenten direkt mit PanelAlpha Engine und lässt ihn Deployments, Wartung und Serververwaltung für dich übernehmen. Dein Server bleibt organisiert und unter Kontrolle.

**Vor allem aber wird das Verwalten deines eigenen Servers/VPS richtig einfach**. Du musst kein Sysadmin mehr sein, um selbst zu hosten!

Direkt nach der Installation gibt PanelAlpha Engine dir und deiner KI alles, was ihr braucht, um echte Projekte produktiv zu betreiben:

- Beliebigen Stack aus Git oder Dateien ausrollen
- Sofortige Vorschau-URLs, optional mit Passwortschutz
- Staging- und Git-Workflows mit getrennten Live- und Staging-Umgebungen
- Automatische Backups und Wiederherstellung
- Externe Überwachung und eingebaute Besucherstatistiken
- Projektisolierung in eigenen Docker-Containern
- Firewall- und OWASP/WAF-Schutz mit Zugriffsregeln pro Projekt
- Domains, SSL, Cron, FTP/SFTP, Datenbanken, Logs
- Einfache Cloudflare-Anbindung für DNS, Tunnel und Caching

## Warum das existieren muss

KI hat die Softwareerstellung aus ihren alten Grenzen geholt. Mehr Menschen können Ideen in funktionierende Produkte verwandeln, kleine Teams bauen deutlich mehr als früher, und Open Source boomt mit Projekten, die sich lohnen, zu den eigenen zu machen. Was sich kaum so schnell mitbewegt hat, ist die Arbeit, die nötig ist, um das selbst zu betreiben. Die meisten Self-hosted-Werkzeuge erwarten weiterhin, dass du Docker, einen Webserver, Zertifikate, Datenbanken, Backups, eine Firewall und die späteren Updates verstehst und verwaltest.

**PanelAlpha Engine** macht das Betreiben von Software in Produktion so einfach, wie KI das Bauen gemacht hat.

- **Ein Befehl, einmal.** Danach sprichst du mit dem KI-Assistenten, den du ohnehin benutzt.
- **Du bittest in normalen Worten.** Die Engine baut das Projekt, startet es, gibt ihm HTTPS und hält es gesichert.
- **Die KI bekommt nie Root.** Sie arbeitet durch die Engine, innerhalb der Regeln, die du festlegst.

[**Discord beitreten**](https://discord.gg/9twHWR7xGX). Das ist der wichtigste Ort, um zu fragen, zu zeigen, was du ausgerollt hast, und mit den Leuten zu sprechen, die das bauen.

---

## Drei einfache Schritte zur Einrichtung

### Schritt 1: Engine auf deinem VPS installieren

Du brauchst einen **frischen** Server mit Debian 12/13 oder Ubuntu 22.04/24.04/26.04, mindestens 2 GB RAM und 1 CPU, und du meldest dich als `root` per SSH an:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

Das war's. Dein Server ist bereit. Eigener Name, eigenes TLS-Zertifikat oder ein Server hinter NAT: [Installationsoptionen](docs/02-getting-started/install.md).

### Schritt 2: KI-Agent verbinden

Am Ende der Installation fragt die Engine, welchen Assistenten du benutzt, und gibt den fertigen Befehl für deinen eigenen Rechner aus. Um später einen weiteren zu verbinden, führe auf dem Server aus:

```bash
pae connect
```

`pae connect` führst du auf dem Engine-Server aus, nicht auf deinem Laptop. Die Zeile, die ausgegeben wird, führst du auf deinem Rechner aus.

<div align="center">

<a href="docs/04-connecting-your-ai/claude-code.md"><img src="https://github.com/claude.png" width="46" alt="Claude Code"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/cursor.md"><img src="https://github.com/cursor.png" width="46" alt="Cursor"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/codex.md"><img src="https://github.com/openai.png" width="46" alt="Codex"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/gemini-cli.md"><img src="https://github.com/google-gemini.png" width="46" alt="Gemini CLI"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/vs-code-copilot.md"><img src="https://skillicons.dev/icons?i=vscode" width="46" alt="VS Code / Copilot"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/grok.md"><img src="https://github.com/xai-org.png" width="46" alt="Grok"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/opencode.md"><img src="https://opencode.ai/apple-touch-icon-v3.png" width="46" alt="OpenCode"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/windsurf.md"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/windsurf-white.svg"><img src="docs/assets/windsurf-black.svg" width="46" height="46" alt="Windsurf"></picture></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/pi.md"><img src="https://pi.dev/logo-auto.svg" width="46" alt="Pi"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/hermes.md"><img src="docs/assets/hermes.png" width="46" height="46" alt="Hermes"></a>&nbsp;&nbsp;
<a href="docs/04-connecting-your-ai/openclaw.md"><img src="https://github.com/openclaw.png" width="46" alt="OpenClaw"></a>

<sub><b>Claude Code&nbsp; · &nbsp;Cursor&nbsp; · &nbsp;Codex&nbsp; · &nbsp;Gemini CLI&nbsp; · &nbsp;VS Code / Copilot&nbsp; · &nbsp;Grok&nbsp; · &nbsp;OpenCode&nbsp; · &nbsp;Windsurf&nbsp; · &nbsp;Pi&nbsp; · &nbsp;Hermes&nbsp; · &nbsp;OpenClaw</b></sub>

</div>

Du benutzt etwas anderes? Jeder Assistent, der MCP spricht, funktioniert: [andere Assistenten verbinden](docs/04-connecting-your-ai/other-mcp-clients.md).

Ein Token mit Standardrechten kann ein ganzes Projekt löschen. Du kannst stattdessen ein Nur-Lese-Token vergeben oder einzelne Werkzeuge entziehen: [entscheide, was der Assistent darf](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### Schritt 3: Fertig. Sag, was du willst

Öffne den Chat deines Assistenten und sag es so, wie du es einem Menschen sagen würdest:

```text
rolle github.com/anna/invoicer auf meinem Server aus
```

> Fertig. Erreichbar unter `invoicer.panelalpha.online`

Jedes Projekt bekommt sofort eine kostenlose `panelalpha.online`-Adresse, sobald es existiert. Wenn du so weit bist, bittest du um deine eigene Domain, und das Zertifikat kommt mit.

Lieber selbst vom Server aus? [Direkt aus Git ausrollen](docs/README.md#3-put-your-project-online).

---

## Worum du einfach bitten kannst

Keine Befehle zu lernen, und auch keine Zauberformeln. Das hier zeigt nur, wie detailliert eine Bitte sinnvollerweise ist:

| Du sagst | Was passiert |
|:---|:---|
| `Rolle github.com/org/app auf dieser Engine aus.` | Ein Projekt entsteht, der Stack wird erkannt, die App gebaut, gestartet und bekommt eine Adresse mit HTTPS. |
| `Füge shop.example.com hinzu und hol ein Zertifikat dafür.` | Die Domain wird eingehängt, Let's Encrypt stellt ein Zertifikat aus, das sich selbst erneuert. |
| `Die Seite geht nicht auf. Lies das Deploy-Log und repariere, was geht.` | Dein Assistent liest das Log, prüft, was die Seite wirklich ausliefert, ändert deinen Code und rollt neu aus. |
| `Schieb es zuerst auf Staging.` | Eine verknüpfte Kopie mit eigener Adresse. Wenn es passt, schiebst du sie live, in beide Richtungen. |
| `Installier hier WordPress, Admin anna.` | WordPress installiert und startklar, und für alles danach steht WP-CLI bereit. |
| `Leg eine MySQL-Datenbank für dieses Projekt an, mit Benutzer.` | Datenbank, Benutzer und Rechte, ohne dass du SQL anfasst. |
| `Stell das gestrige Backup wieder her.` | Das Backup wird eingespielt, der aktuelle Stand vorher gesichert, für alle Fälle. |
| `Lass nur unser Büro auf dieses interne Tool zugreifen.` | Eine Firewall-Regel, die das Projekt auf die Adressen begrenzt, die du nennst. |
| `Richte einen Cloudflare-Tunnel für n8n.mydomain.com ein.` | DNS und Tunnel konfiguriert, und genau so lieferst du auch von einem Server hinter NAT aus. |
| `Wie viel Traffic hatten wir letzte Woche?` | Verbrauch, Logs und Limits für dieses Projekt und für den ganzen Server. |

Mehr ausgearbeitete Beispiele: [worum du bitten kannst](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). Die vollständige Liste dessen, was dein Assistent erreichen kann: [199 Werkzeuge](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">Ein VPS. Viele Projekte. Funktioniert mit allem.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
Deine eigenen Werkzeuge, mit KI gebaute Projekte oder Open-Source-Software aus dem Netz. Es spielt keine Rolle: PanelAlpha Engine betreibt sie einfach. Ein paar Anwendungen bekommen besondere Aufmerksamkeit, weil der allgemeine Weg sie falsch behandeln würde: WordPress, Matomo, phpBB, Magento und Passbolt. Siehe die <a href="docs/07-supported-projects/project-types.md">vollständige Liste</a>.
</p>

---

## Lass KI deinen Server nicht ins Chaos stürzen

Voller Root-Zugriff für KI bedeutet offene Ports, zufällige Einstellungen und ein Projekt, das ein anderes beeinträchtigt. PanelAlpha Engine legt die Regeln fest:

- **Weniger Raum für Fehler.** Die KI arbeitet durch die Engine, nicht direkt auf dem Server.
- **Eine klare Struktur.** Projekte, Konten und Domains bleiben organisiert.
- **Projektisolierung.** Jedes Projekt läuft in seinem eigenen Docker-Container, mit eigenen Grenzen für Platte, Speicher und CPU und nur den Ports, die es braucht.

Was der Assistent tun darf, entscheidest du vor dem Verbinden. [Entscheide, was der Assistent darf](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [Sicherheit](docs/05-capabilities/security.md)

---

## Alles, was du für den produktiven Einsatz brauchst, direkt dabei

Das Ausrollen ist nur der Anfang. Laufende Verwaltung ist ab der ersten Installation da:

- **Sofort online ansehen.** Ein kostenloser `panelalpha.online`-Hostname für jedes Projekt, plus deine eigenen Domains, wann immer du willst. Zertifikate kommen von Let's Encrypt und erneuern sich selbst. [Domains und HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **Auf Staging bauen. Live gehen, wenn es fertig ist.** Eine verknüpfte Kopie, auf der du frei etwas kaputt machen kannst, und die du live schiebst, wenn sie funktioniert. Andersherum geht auch, um die Testkopie mit echten Daten aufzufrischen. [Projekte](docs/05-capabilities/projects.md#staging)
- **Integriertes Monitoring.** Servermetriken, Logs pro Seite, Lighthouse-Berichte auf Zuruf und alle sechs Stunden die Prüfung, ob jede Seite noch sich selbst ausliefert statt einer leeren Seite. [Monitoring und Logs](docs/05-capabilities/monitoring-and-logs.md)
- **Alles Wesentliche ist abgedeckt.** Backups auf dem Server und außerhalb, SSL, Domains, Datenbanken, FTP- und SFTP-Zugänge, Cronjobs und Logs. [Backups](docs/05-capabilities/backups.md) · [Datenbanken](docs/05-capabilities/databases.md) · [Dateien und Zugänge](docs/05-capabilities/files-and-access.md)
- **Ein sicherer Bereich pro Projekt.** Docker-Isolierung, eine Firewall und ModSecurity mit den OWASP-Regeln. Eine kaputte App kann die anderen nicht mitreißen. [Sicherheit](docs/05-capabilities/security.md)
- **Cloudflare in einer Verbindung.** DNS, Tunnel und Caching für deine Projekte, auch um eine Seite von einem Server hinter NAT bereitzustellen. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## Wenn ein Deploy fehlschlägt, und wie die Engine daraus lernt

Die meisten ersten Deploys laufen. Die, die es nicht tun, haben meist ein Problem im Repository. Die meisten Werkzeuge lassen dich mit einem Stacktrace stehen. Hier ist der Weg:

- **Du bekommst einen Satz, keinen Stacktrace.** "Dieses Projekt braucht PHP 8.2, wurde aber mit PHP 8.1 gebaut." "Dem Build ist der Speicher ausgegangen." Die vollständige Ausgabe steht weiterhin darunter, falls sie jemand braucht.
- **Ab da übernimmt dein Assistent.** Er liest das Log, sieht, was die Seite wirklich ausliefert, repariert im Code, was reparierbar ist, und rollt erneut aus. Diese Arbeit läuft über das KI-Abo, das du ohnehin bezahlst, nicht über API-Tokens.
- **Die Engine lernt daraus.** Ein anonymer Bericht geht an PanelAlpha. Ein Fehler auf deinem Server kann dein Repository sein. Derselbe Fehler auf dreißig Servern ist ein Bug in der Erkennung oder in einem Framework-Rezept, und der wird zum Fix im nächsten Update.
- **Wenn die Engine diejenige war, die es falsch gemacht hat**, sag es, und sie sammelt die Belege: bitte deinen Assistenten darum, oder führe `pae telemetry:bug-report <projekt>` aus.

Das ganze Vorgehen: [wenn ein Deploy fehlschlägt](docs/02-getting-started/what-happens.md) · [was die Fehlermeldung heißt](docs/02-getting-started/reading-errors.md).

---

## Telemetrie

Telemetrie ist nach der Installation an. Anonyme Berichte über Deploys und spätere Health-Prüfungen verlassen den Server, solange du sie nicht abschaltest. Sie enthalten die öffentlichen Namen deiner Seiten.

- **Gesendet:** welche Art Anwendung es war, wie lange es gedauert hat, die Grenzen des Projekts, und bei einem Fehlschlag die Stufe, die gebrochen ist, plus das Ende des Logs mit entfernten Geheimnissen. Ein Health-Bericht nur, wenn eine Seite sich später nicht mehr selbst ausliefert. Ein Fehlerbericht nur, wenn du selbst einen einreichst.
- **Nie gesendet:** dein Quellcode, Projektnamen, Tokens, Passwörter, die IP-Adresse oder der Hostname deines Servers, Namen privater Repositories oder irgendetwas über die Menschen, die deine Seiten besuchen.
- **Sieh dir einen Bericht an, bevor du entscheidest:** `pae telemetry:status` und `pae telemetry:show`.

Um das Senden zu stoppen:

```bash
pae telemetry:disable
```

Was erhoben wird, was nicht, und jeder Weg zum Abschalten: [was erhoben wird](docs/02-getting-started/what-is-collected.md) · [wie du es abschaltest](docs/02-getting-started/how-to-turn-it-off.md).

---

## FAQ

<details>
<summary><b>Warum brauche ich PanelAlpha Engine überhaupt?</b></summary>

Weil eine Anwendung nicht fertig ist, wenn der Code fertig ist. Dein Projekt braucht weiterhin einen Ort, an dem es läuft, ganz zu schweigen von allem, was nötig ist, damit es gesund, erreichbar und sicher bleibt. PanelAlpha Engine gibt deinem KI-Assistenten einen richtigen Weg, diese ganze Schicht für dich zu übernehmen. Du bittest um das Ergebnis, das du willst, und die Engine macht daraus kontrollierte Serveroperationen, ohne der KI uneingeschränkten Zugriff auf die Maschine zu geben.

Wenn du Docker, Reverse Proxies, Firewalls und Serverlogs schon kennst, bleibt dieses Wissen wichtig. PanelAlpha versucht nicht, die Infrastruktur vor dir zu verstecken oder dich auszusperren. Es gibt dir einen klareren Weg, sie zu betreiben, die repetitiven Teile zu automatisieren und KI echte Arbeit übernehmen zu lassen, ohne die Kontrolle abzugeben. Du kannst so tief einsteigen, wie du willst, wenn etwas deine Aufmerksamkeit verdient, und die Routine überspringen, wenn nicht.
</details>

<details>
<summary><b>Brauche ich einen eigenen Server?</b></summary>

Ja. PanelAlpha Engine ist ein Werkzeug, mit dem KI-Agenten *deinen* Server verwalten. Du brauchst einen frischen Debian- oder Ubuntu-VPS mit mindestens 2 GB RAM und 1 CPU, auf dem du dich als `root` per SSH anmeldest. Du beginnst damit, die Engine-Installation auf diesem VPS selbst auszuführen. [Was du brauchst](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>Welche KI-Agenten funktionieren mit PanelAlpha Engine?</b></summary>

Jeder Assistent, der sich mit einem Token an einen MCP-Server hängen kann. Schritt-für-Schritt-Seiten gibt es für [Claude Code](docs/04-connecting-your-ai/claude-code.md), [Cursor](docs/04-connecting-your-ai/cursor.md), [Codex](docs/04-connecting-your-ai/codex.md), [Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md), [VS Code und Copilot](docs/04-connecting-your-ai/vs-code-copilot.md), [Grok](docs/04-connecting-your-ai/grok.md), [OpenCode](docs/04-connecting-your-ai/opencode.md), [Windsurf](docs/04-connecting-your-ai/windsurf.md), [Pi](docs/04-connecting-your-ai/pi.md), [Hermes](docs/04-connecting-your-ai/hermes.md) und [OpenClaw](docs/04-connecting-your-ai/openclaw.md), dazu [alles andere, was MCP spricht](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>Welche Projekte kann ich mit PanelAlpha Engine ausrollen?</b></summary>

Das Ziel ist ein universelles Werkzeug für jedes Projekt. Statische Seiten, WordPress, PHP, Laravel, Node, Next.js, Django, Go, Rust, Java, ein einfaches `Dockerfile` oder eine Compose-Datei. Deine eigenen Werkzeuge, die mit KI gebauten, und Open-Source-Software aus dem Netz. Siehe [Projekttypen](docs/07-supported-projects/project-types.md), und wenn du vorher sicher sein willst, lass deinen Assistenten das Repository erst inspizieren.
</details>

<details>
<summary><b>Ist das kostenlos? Wofür zahle ich?</b></summary>

PanelAlpha Engine ist kostenlos und Open Source unter der Apache-2.0-Lizenz. Du zahlst für den Server und für das KI-Abo, und beides hast du bereits. Das Debuggen eines fehlgeschlagenen Deploys läuft über das Abo deines Assistenten, nicht über API-Tokens.
</details>

<details>
<summary><b>Kann ich einschränken, was mein Assistent darf?</b></summary>

Ja, und es lohnt sich, bevor du ein Token einfügst. Du kannst ihm ein Nur-Lese-Token geben, Änderungen ohne Löschen erlauben, nur einzelne Bereiche freigeben oder einzelne Werkzeuge wie `project_delete` entziehen. [Entscheide, was der Assistent darf](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>Was wird gemeldet, wenn ein Deploy fehlschlägt?</b></summary>

Eine anonyme Zusammenfassung: die Art der Anwendung, die gebrochene Stufe, das bereinigte Ende des Logs und die öffentlichen Namen der Seiten. Nie dein Quellcode, deine Projektnamen, deine Zugangsdaten oder die Identität deines Servers. Einen wartenden Bericht kannst du dir mit `pae telemetry:show` ausgeben lassen und das Ganze abschalten. Alle Details: [Telemetrie](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>Kann ich PanelAlpha Engine für Shared Hosting nutzen?</b></summary>

Ja. Jedes Projekt ist ein eigenes Konto mit eigenen Domains, Datenbanken, Dateien und Grenzen. Hosting-Anbieter betreiben damit tausende Websites im Produktivbetrieb.
</details>

<details>
<summary><b>Die Engine hat mein Projekt falsch verstanden. Was jetzt?</b></summary>

Reich einen Fehlerbericht ein, und sie sammelt die Belege selbst. Bitte deinen Assistenten darum oder führe auf dem Server `pae telemetry:bug-report <projekt>` aus. Mit `--dry-run` siehst du vorher genau, was gesendet würde.
</details>

---

## Sprich mit uns auf Discord

**Discord ist der wichtigste Ort, um uns zu erreichen.** Stell eine Frage, zeig uns, was du ausgerollt hast, teile eine Idee, oder schau einfach vorbei und sieh, woran wir arbeiten. Wir sind mittendrin in diesen Gesprächen, und was du dort einbringst, kann als Nächstes gebaut, repariert oder neu gedacht werden.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="Sprich mit uns auf Discord, der wichtigste Ort, um das PanelAlpha-Team zu erreichen" width="760">
</a>
</div><br>

Nicht so der Discord-Typ? [Unser Forum](https://community.panelalpha.com/) ist genauso offen.

## Dein Server ist nur einen Befehl entfernt

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## Sicherheit

Ein Engine-Host ist eine Maschine für genau einen Zweck. Der Installer ersetzt Resolver und Firewall, behandle ihn also entsprechend.

Eine Schwachstelle gefunden? Bitte **privat** melden, nie in einem öffentlichen Issue. Siehe [`SECURITY.md`](SECURITY.md) oder [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## Lizenz

PanelAlpha Engine ist Open Source unter der Apache-2.0-Lizenz.

## Bau mit uns

Willst du mitmachen? [`CONTRIBUTING.md`](CONTRIBUTING.md) bringt dich ins Laufen. Die Dokumentation für Betreiber liegt in [`docs/`](docs/README.md).
