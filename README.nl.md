<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>Jouw AI-agent draait<br>jouw server. Onder controle.</h1>

<h3>
Open source. Self-hosted. Makkelijk voor iedereen, niet alleen voor sysadmins.
</h3>

<h3>
<a href="#drie-eenvoudige-stappen-om-het-in-te-stellen"><b>Beginnen</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>Documentatie</b></a> ·
<a href="#praat-met-ons-op-discord"><b>Discord</b></a>
</h3>

<p>
<a href="#waar-je-gewoon-om-kunt-vragen">AI-besturing</a> ·
<a href="#alles-wat-je-nodig-hebt-voor-productie-standaard-aanwezig">Mogelijkheden</a> ·
<a href="#telemetrie">Telemetrie</a> ·
<a href="#faq">FAQ</a>
</p>

<p>
<a href="README.md">English</a>
· <a href="README.pl.md">Polski</a>
· <a href="README.de.md">Deutsch</a>
· <b>Nederlands</b>
· <a href="README.es.md">Español</a>
· <a href="README.fr.md">Français</a>
· <a href="README.it.md">Italiano</a>
· <a href="README.pt-BR.md">Português</a>
· <a href="README.uk.md">Українська</a>
· <a href="README.ar.md">العربية</a>
· <a href="README.zh-CN.md">简体中文</a>
</p>

<p>
<a href="#stap-1-installeer-engine-op-je-vps"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="Installatie met één commando"></a>
<a href="#stap-1-installeer-engine-op-je-vps"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="Ondersteunde systemen"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-199_tools-6f42c1" alt="199 MCP-tools"></a>
<a href="#licentie"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="Kom op Discord"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="Een project uitrollen door het je assistent te vragen" width="760"></p>

</div>

---

## Wat is PanelAlpha Engine?

PanelAlpha Engine is software die je op je VPS installeert om met AI gebouwde en vibe-coded projecten, websites en open source apps te hosten die je online vond. Na de installatie verbind je je eigen AI-agent rechtstreeks met PanelAlpha Engine en laat je hem deployments, onderhoud en serverbeheer voor je afhandelen. Je server blijft geordend en onder controle.

**Maar het belangrijkste: je eigen server/VPS beheren wordt écht eenvoudig**. Je hoeft geen sysadmin meer te zijn om zelf te hosten!

Direct na de installatie geeft PanelAlpha Engine jou en je AI alles wat jullie nodig hebben om echte projecten in productie te draaien:

- Elke stack uitrollen vanuit Git of bestanden
- Directe preview-URL's, optioneel met wachtwoordbeveiliging
- Staging- en Git-workflows met aparte live- en stagingomgevingen
- Automatische back-ups en herstel
- Externe monitoring en ingebouwde bezoekersstatistieken
- Projectisolatie in aparte Docker-containers
- Firewall- en OWASP/WAF-bescherming met toegangsregels per project
- Domeinen, SSL, cron, FTP/SFTP, databases, logs
- Eenvoudige Cloudflare-integratie voor DNS, Tunnels en caching

## Waarom dit moet bestaan

AI heeft software maken uit zijn oude grenzen gehaald. Meer mensen kunnen een idee omzetten in een werkend product, kleine teams bouwen veel meer dan vroeger, en open source groeit vol projecten die de moeite waard zijn om van jezelf te maken. Wat bijna niet zo snel is meegegaan, is het werk dat nodig is om het zelf te draaien. De meeste self-hosted tools verwachten nog steeds dat je Docker, een webserver, certificaten, databases, back-ups, een firewall en de updates die daarna komen begrijpt en beheert.

**PanelAlpha Engine** maakt het beheren van software in productie even makkelijk als AI het bouwen ervan heeft gemaakt.

- **Eén commando, één keer.** Daarna praat je met de AI-assistent die je toch al gebruikt.
- **Je vraagt in gewone woorden.** De engine bouwt het project, start het, geeft het HTTPS en houdt het geback-upt.
- **De AI krijgt nooit root.** Hij werkt via de engine, binnen regels die jij bepaalt.

[**Kom op Discord**](https://discord.gg/9twHWR7xGX). Dat is de belangrijkste plek om te vragen, te laten zien wat je hebt uitgerold, en te praten met de mensen die dit bouwen.

---

## Drie eenvoudige stappen om het in te stellen

### Stap 1: Installeer Engine op je VPS

Je hebt een **verse** server nodig met Debian 12/13 of Ubuntu 22.04/24.04/26.04, minimaal 2 GB RAM en 1 CPU, en je logt in als `root` via SSH:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

Dat is alles. Je server is klaar. Een eigen naam, je eigen TLS-certificaat of een server achter NAT: [installatie-opties](docs/02-getting-started/install.md).

### Stap 2: Verbind je AI-agent

Aan het eind van de installatie vraagt de engine welke assistent je gebruikt en drukt het exacte commando af dat je op je eigen computer draait. Wil je er later nog een verbinden, draai dan op de server:

```bash
pae connect
```

`pae connect` draai je op de engineserver, niet op je laptop. De regel die het afdrukt, draai je op je eigen computer.

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

Gebruik je iets anders? Elke assistent die MCP spreekt werkt: [andere assistenten verbinden](docs/04-connecting-your-ai/other-mcp-clients.md).

Een token met standaardrechten kan een heel project verwijderen. Je kunt in plaats daarvan een alleen-lezen token uitgeven, of losse tools afnemen: [bepaal wat de assistent mag](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### Stap 3: Klaar. Vraag om wat je wilt

Open de chat van je assistent en zeg het zoals je het tegen een mens zou zeggen:

```text
rol github.com/anna/invoicer uit op mijn server
```

> Klaar. Bereikbaar op `invoicer.panelalpha.online`

Elk project krijgt meteen een gratis `panelalpha.online`-adres, het moment dat het bestaat. Als je zover bent, vraag je om je eigen domein, en het certificaat komt daarbij.

Liever zelf vanaf de server? [Rechtstreeks vanuit git uitrollen](docs/README.md#3-put-your-project-online).

---

## Waar je gewoon om kunt vragen

Geen commando's om te leren, en ook geen toverformules. Dit zijn voorbeelden van het niveau van detail dat het waard is om mee te geven:

| Jij zegt | Wat er gebeurt |
|:---|:---|
| `Rol github.com/org/app uit op deze engine.` | Er komt een project, de stack wordt herkend, de app gebouwd, gestart en van een adres met HTTPS voorzien. |
| `Voeg shop.example.com toe en haal er een certificaat bij.` | Het domein wordt gekoppeld en Let's Encrypt geeft een certificaat af dat zichzelf vernieuwt. |
| `Deze site opent niet. Lees het deploylog en repareer wat je kunt.` | Je assistent leest het log, kijkt wat de site echt teruggeeft, past je code aan en rolt opnieuw uit. |
| `Push het eerst naar staging.` | Een gekoppelde kopie met een eigen adres. Push hem live als je tevreden bent, in beide richtingen. |
| `Installeer hier WordPress, admin anna.` | WordPress geïnstalleerd en klaar, met WP-CLI voor alles daarna. |
| `Maak een MySQL-database voor dit project met een gebruiker.` | Database, gebruiker en rechten, zonder dat jij SQL aanraakt. |
| `Zet de back-up van gisteren terug.` | De back-up wordt teruggezet, en de huidige staat wordt eerst bewaard, voor de zekerheid. |
| `Laat alleen ons kantoor bij deze interne tool.` | Een firewallregel die het project beperkt tot de adressen die je noemt. |
| `Zet een Cloudflare-tunnel op voor n8n.mydomain.com.` | DNS en tunnel geregeld, en zo serveer je ook vanaf een server achter NAT. |
| `Hoeveel verkeer kregen we vorige week?` | Verbruik, logs en limieten voor dat project, en voor de hele server. |

Meer uitgewerkte voorbeelden: [waar je om kunt vragen](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). De volledige lijst van wat je assistent kan bereiken: [199 tools](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">Eén VPS. Meerdere projecten. Werkt met alles.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
Je eigen tools, met AI gebouwde projecten of open source software die je online vond. Het maakt niet uit: PanelAlpha Engine draait het gewoon. Een paar applicaties krijgen extra aandacht omdat de algemene aanpak ze verkeerd zou behandelen: WordPress, Matomo, phpBB, Magento en Passbolt. Zie de <a href="docs/07-supported-projects/project-types.md">volledige lijst</a>.
</p>

---

## Laat AI geen puinhoop van je server maken

AI volledige roottoegang geven betekent open poorten, willekeurige instellingen en het ene project dat het andere beïnvloedt. PanelAlpha Engine stelt de regels:

- **Minder ruimte voor fouten.** De AI werkt via de engine, niet rechtstreeks op de server.
- **Een duidelijke structuur.** Projecten, accounts en domeinen blijven geordend.
- **Projectisolatie.** Elk project draait in zijn eigen Docker-container, met eigen limieten voor schijf, geheugen en CPU, en alleen de poorten die het nodig heeft.

Wat de assistent mag doen bepaal je voordat je hem verbindt. [Bepaal wat de assistent mag](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [Beveiliging](docs/05-capabilities/security.md)

---

## Alles wat je nodig hebt voor productie, standaard aanwezig

Uitrollen is pas het begin. Doorlopend beheer is er vanaf de eerste installatie:

- **Meteen online bekijken.** Een gratis `panelalpha.online`-hostnaam op elk project, plus je eigen domeinen wanneer je wilt. Certificaten komen van Let's Encrypt en vernieuwen zichzelf. [Domeinen en HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **Bouw op staging. Ga live wanneer het klaar is.** Een gekoppelde kopie om vrij op te slopen, en live te pushen als het werkt. Andersom kan ook, om je testkopie met echte data te vernieuwen. [Projecten](docs/05-capabilities/projects.md#staging)
- **Ingebouwde monitoring.** Servermetrieken, logs per site, Lighthouse-rapporten op verzoek en elke zes uur een controle of elke site zichzelf nog serveert in plaats van een lege pagina. [Monitoring en logs](docs/05-capabilities/monitoring-and-logs.md)
- **Alle essentials zijn gedekt.** Back-ups op de server en erbuiten, SSL, domeinen, databases, FTP- en SFTP-accounts, cronjobs en logs. [Back-ups](docs/05-capabilities/backups.md) · [Databases](docs/05-capabilities/databases.md) · [Bestanden en toegang](docs/05-capabilities/files-and-access.md)
- **Elk project krijgt zijn eigen veilige plek.** Docker-isolatie, een firewall en ModSecurity met de OWASP-regels. Eén kapotte app kan de andere niet meenemen. [Beveiliging](docs/05-capabilities/security.md)
- **Cloudflare in één verbinding.** DNS, tunnels en caching voor al je projecten, ook om een site te serveren vanaf een server achter NAT. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## Als een deploy mislukt, en hoe de engine daarvan leert

De meeste eerste deploys lukken. Die het niet doen hebben meestal een probleem in de repository. De meeste tools laten je achter met een stacktrace. Hier is het pad:

- **Je krijgt een zin, geen stacktrace.** "Dit project heeft PHP 8.2 nodig, maar is gebouwd met PHP 8.1." "De build raakte zonder geheugen." De volledige uitvoer staat er nog steeds onder, als iemand die wil.
- **Vanaf daar neemt je assistent het over.** Hij leest het log, kijkt wat de site echt serveert, repareert in je code wat te repareren valt en rolt opnieuw uit. Dat werk loopt op het AI-abonnement dat je al betaalt, niet op API-tokens.
- **De engine leert ervan.** Een anoniem rapport gaat naar PanelAlpha. Eén storing op jouw server kan jouw repository zijn. Dezelfde storing op dertig servers is een bug in de detectie of in een framework-recept, en die wordt een fix in de volgende update.
- **En als de engine degene was die het fout deed**, zeg dat, dan verzamelt hij het bewijs: vraag het aan je assistent, of draai `pae telemetry:bug-report <project>`.

De hele procedure: [als een deploy mislukt](docs/02-getting-started/what-happens.md) · [wat de foutmelding betekent](docs/02-getting-started/reading-errors.md).

---

## Telemetrie

Telemetrie staat na de installatie aan. Anonieme rapporten over deploys en latere healthchecks verlaten de server tenzij je het uitzet. Ze bevatten de publieke namen van je sites.

- **Verstuurd:** wat voor applicatie het was, hoe lang het duurde, de limieten van het project, en bij een mislukking de fase die brak plus het einde van het log met geheimen eruit. Een healthrapport alleen als een site later zichzelf niet meer serveert. Een bugrapport alleen als je er zelf een indient.
- **Nooit verstuurd:** je broncode, projectnamen, tokens, wachtwoorden, het IP-adres of de hostnaam van je server, namen van private repositories, of iets over de mensen die je sites bezoeken.
- **Bekijk een rapport voordat je beslist:** `pae telemetry:status` en `pae telemetry:show`.

Om te stoppen met versturen:

```bash
pae telemetry:disable
```

Wat er wordt verzameld, wat niet, en alle manieren om het uit te zetten: [wat er wordt verzameld](docs/02-getting-started/what-is-collected.md) · [hoe je het uitzet](docs/02-getting-started/how-to-turn-it-off.md).

---

## FAQ

<details>
<summary><b>Waarom heb ik PanelAlpha Engine eigenlijk nodig?</b></summary>

Omdat een applicatie niet af is wanneer de code af is. Je project heeft nog steeds ergens nodig om te draaien, om nog maar te zwijgen van alles wat nodig is om het gezond, bereikbaar en veilig te houden. PanelAlpha Engine geeft je AI-assistent een echte manier om die hele laag voor je af te handelen. Je vraagt om het resultaat dat je wilt, en de engine zet dat om in gecontroleerde serveroperaties zonder de AI onbeperkte toegang tot de machine te geven.

Als je Docker, reverse proxies, firewalls en serverlogs al kent, blijft die kennis ertoe doen. PanelAlpha probeert de infrastructuur niet voor je te verbergen of je eruit te sluiten. Het geeft je een schonere manier om haar te bedienen, de repetitieve delen te automatiseren en AI echt werk te laten doen zonder de controle in te leveren. Je kunt zo diep gaan als je wilt wanneer iets je aandacht verdient, en de routine overslaan wanneer niet.
</details>

<details>
<summary><b>Heb ik een eigen server nodig?</b></summary>

Ja. PanelAlpha Engine is een tool waarmee AI-agenten *jouw* server beheren. Je hebt een verse Debian- of Ubuntu-VPS nodig met minimaal 2 GB RAM en 1 CPU, waarop je als `root` via SSH inlogt. Je begint door de Engine-installatie zelf op die VPS te draaien. [Wat je nodig hebt](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>Welke AI-assistenten werken ermee?</b></summary>

Elke assistent die met een token verbinding kan maken met een MCP-server. Er zijn stap-voor-stap-pagina's voor [Claude Code](docs/04-connecting-your-ai/claude-code.md), [Cursor](docs/04-connecting-your-ai/cursor.md), [Codex](docs/04-connecting-your-ai/codex.md), [Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md), [VS Code en Copilot](docs/04-connecting-your-ai/vs-code-copilot.md), [Grok](docs/04-connecting-your-ai/grok.md), [OpenCode](docs/04-connecting-your-ai/opencode.md), [Windsurf](docs/04-connecting-your-ai/windsurf.md), [Pi](docs/04-connecting-your-ai/pi.md), [Hermes](docs/04-connecting-your-ai/hermes.md) en [OpenClaw](docs/04-connecting-your-ai/openclaw.md), plus [alles wat verder MCP spreekt](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>Wat kan ik uitrollen?</b></summary>

Het doel is een universeel hulpmiddel voor elk project. Statische sites, WordPress, PHP, Laravel, Node, Next.js, Django, Go, Rust, Java, een simpele `Dockerfile` of een Compose-bestand. Je eigen tools, projecten die je met AI bouwde, en open source software die je online vond. Zie [projecttypen](docs/07-supported-projects/project-types.md), en wil je het vooraf zeker weten, laat je assistent de repository eerst inspecteren.
</details>

<details>
<summary><b>Is het gratis? Waar betaal ik voor?</b></summary>

PanelAlpha Engine is gratis en open source onder de Apache 2.0-licentie. Je betaalt voor je server en voor het AI-abonnement, en die heb je allebei al. Het debuggen van een mislukte deploy loopt op het abonnement van je assistent, niet op API-tokens.
</details>

<details>
<summary><b>Kan ik beperken wat mijn assistent mag?</b></summary>

Ja, en het is de moeite waard voordat je een token plakt. Je kunt hem een alleen-lezen token geven, wijzigen toestaan maar verwijderen niet, alleen bepaalde gebieden openstellen, of losse tools zoals `project_delete` afnemen. [Bepaal wat de assistent mag](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>Wat wordt er gemeld als een deploy mislukt?</b></summary>

Een anonieme samenvatting: het soort applicatie, de fase die brak, het geschoonde einde van het log en de publieke namen van de sites. Nooit je broncode, projectnamen, inloggegevens of de identiteit van je server. Een wachtend rapport kun je zelf afdrukken met `pae telemetry:show`, en je kunt alles uitzetten. Alle details: [Telemetrie](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>Kan ik het voor shared hosting gebruiken?</b></summary>

Ja. Elk project is een apart account met eigen domeinen, databases, bestanden en limieten. Hostingproviders draaien er duizenden sites mee in productie.
</details>

<details>
<summary><b>De engine heeft mijn project verkeerd begrepen. Wat nu?</b></summary>

Dien een bugrapport in, dan verzamelt hij het bewijs zelf. Vraag het aan je assistent, of draai op de server `pae telemetry:bug-report <project>`. Wil je eerst precies zien wat er verstuurd zou worden, gebruik dan `--dry-run`.
</details>

---

## Praat met ons op Discord

**Discord is de belangrijkste plek om ons te bereiken.** Stel een vraag, laat ons zien wat je hebt uitgerold, deel een idee, of kom gewoon langs en kijk waar we aan werken. We zitten middenin die gesprekken, en wat je daar aankaart kan het volgende worden dat we bouwen, repareren of opnieuw doordenken.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="Praat met ons op Discord, de belangrijkste plek om het PanelAlpha-team te bereiken" width="760">
</a>
</div><br>

Niet zo van Discord? [Ons forum](https://community.panelalpha.com/) is net zo open.

## Je server is één commando verwijderd

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## Beveiliging

Een engine-host is een machine voor één doel. De installer vervangt de resolver en de firewall, dus behandel hem zo.

Een kwetsbaarheid gevonden? Meld die **privé**, nooit in een openbaar issue. Zie [`SECURITY.md`](SECURITY.md), of gebruik [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## Licentie

PanelAlpha Engine is open source onder de Apache 2.0-licentie.

## Bouw mee

Wil je meedoen? [`CONTRIBUTING.md`](CONTRIBUTING.md) zet je op weg. De documentatie voor wie de server beheert staat in [`docs/`](docs/README.md).
