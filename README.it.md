<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>Il tuo agente AI fa girare<br>il tuo server. Sotto controllo.</h1>

<h3>
Open source. Self-hosted. Facile per chiunque, non solo per i sysadmin.
</h3>

<h3>
<a href="#tre-semplici-passi-per-configurarlo"><b>Inizia</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>Documentazione</b></a> ·
<a href="#parla-con-noi-su-discord"><b>Discord</b></a>
</h3>

<p>
<a href="#cosa-puoi-semplicemente-chiedere">Controllo AI</a> ·
<a href="#tutto-ciò-che-serve-per-la-produzione-pronto-alluso">Funzionalità</a> ·
<a href="#telemetria">Telemetria</a> ·
<a href="#faq">FAQ</a>
</p>

<p>
<a href="README.md">English</a>
· <a href="README.pl.md">Polski</a>
· <a href="README.de.md">Deutsch</a>
· <a href="README.nl.md">Nederlands</a>
· <a href="README.es.md">Español</a>
· <a href="README.fr.md">Français</a>
· <b>Italiano</b>
· <a href="README.pt-BR.md">Português</a>
· <a href="README.uk.md">Українська</a>
· <a href="README.ar.md">العربية</a>
· <a href="README.zh-CN.md">简体中文</a>
</p>

<p>
<a href="#passo-1-installalo-sul-tuo-vps"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="Installazione con un comando"></a>
<a href="#passo-1-installalo-sul-tuo-vps"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="Sistemi supportati"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-199_tools-6f42c1" alt="199 strumenti MCP"></a>
<a href="#licenza"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="Entra su Discord"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="Mettere online un progetto chiedendolo all'assistente" width="760"></p>

</div>

---

## Cos'è PanelAlpha Engine?

PanelAlpha Engine è un software che installi sul tuo VPS per ospitare progetti costruiti con l'AI e vibe-coded, siti e app open source trovate in rete. Una volta installato, colleghi il tuo agente AI direttamente a PanelAlpha Engine e lo lasci gestire deploy, manutenzione e amministrazione del server. Il tuo server resta organizzato e sotto controllo.

**Ma soprattutto, gestire il tuo server/VPS diventa davvero semplice**. Non devi più essere un sysadmin per fare self-hosting!

Già dall'installazione, PanelAlpha Engine dà a te e alla tua AI tutto il necessario per far girare progetti veri in produzione:

- Metti online qualsiasi stack da Git o da file
- URL di anteprima immediate, con protezione opzionale da password
- Workflow di staging e Git, con ambienti live e staging separati
- Backup automatici e ripristino
- Monitoraggio esterno e statistiche dei visitatori integrate
- Isolamento dei progetti in container Docker separati
- Firewall e protezione OWASP/WAF con regole di accesso per progetto
- Domini, SSL, cron, FTP/SFTP, database, log
- Integrazione Cloudflare semplice per DNS, tunnel e caching

## Perché deve esistere

L'AI ha fatto uscire la creazione del software dai suoi vecchi limiti. Più persone riescono a trasformare un'idea in un prodotto che funziona, i team piccoli costruiscono molto più di prima, e l'open source è pieno di progetti che vale la pena fare propri. Quello che quasi non ha tenuto lo stesso passo è il lavoro necessario per farlo girare da soli. La maggior parte degli strumenti self-hosted si aspetta ancora che tu capisca e gestisca Docker, un webserver, i certificati, i database, i backup, un firewall e gli aggiornamenti successivi.

**PanelAlpha Engine** rende la gestione del software in produzione facile quanto l'AI ha reso il costruirlo.

- **Un comando, una volta sola.** Poi parli con l'assistente AI che già usi.
- **Chiedi con parole normali.** Il motore costruisce il progetto, lo avvia, gli dà HTTPS e lo tiene sotto backup.
- **L'AI non ottiene mai root.** Lavora attraverso il motore, secondo le regole che imposti.

[**Entra su Discord**](https://discord.gg/9twHWR7xGX). È il posto principale per chiedere, mostrare cosa hai messo online e parlare con chi costruisce tutto questo.

---

## Tre semplici passi per configurarlo

### Passo 1: Installalo sul tuo VPS

Ti serve un server **nuovo** con Debian 12/13 o Ubuntu 22.04/24.04/26.04, almeno 2 GB di RAM e 1 CPU, e accedi come `root` via SSH:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

Tutto qui. Il server è pronto. Un nome tuo, un certificato TLS tuo o un server dietro NAT: [opzioni di installazione](docs/02-getting-started/install.md).

### Passo 2: Collega il tuo agente AI

Alla fine dell'installazione il motore chiede quale assistente usi e stampa il comando esatto da eseguire sul tuo computer. Per collegarne un altro più avanti, esegui sul server:

```bash
pae connect
```

`pae connect` lo esegui sul server del motore, non sul portatile. La riga che stampa è quella da eseguire sul tuo computer.

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

Usi qualcos'altro? Va bene qualsiasi assistente che parli MCP: [collegare altri assistenti](docs/04-connecting-your-ai/other-mcp-clients.md).

Un token con i permessi di default può cancellare un intero progetto. Puoi darne uno in sola lettura, oppure togliere singoli strumenti: [decidi cosa può fare l'assistente](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### Passo 3: Fatto. Chiedi quello che vuoi

Apri la chat del tuo assistente e dillo come lo diresti a una persona:

```text
metti online github.com/anna/invoicer sul mio server
```

> Fatto. Disponibile su `invoicer.panelalpha.online`

Ogni progetto riceve un indirizzo `panelalpha.online` gratuito nel momento in cui nasce. Quando sei pronto chiedi il tuo dominio, e il certificato arriva insieme.

Preferisci farlo dal server? [Deploy direttamente da git](docs/README.md#3-put-your-project-online).

---

## Cosa puoi semplicemente chiedere

Nessun comando da imparare, e nemmeno formule magiche. Questi esempi mostrano il livello di dettaglio che conviene dare:

| Tu dici | Cosa succede |
|:---|:---|
| `Metti online github.com/org/app su questo motore.` | Nasce un progetto, viene rilevato lo stack, l'app viene costruita, avviata e riceve un indirizzo con HTTPS. |
| `Aggiungi shop.example.com a questo progetto e prendi un certificato.` | Il dominio viene agganciato e Let's Encrypt emette un certificato che si rinnova da solo. |
| `Questo sito non si apre. Leggi il log del deploy e sistema quello che puoi.` | Il tuo assistente legge il log, guarda cosa serve davvero il sito, cambia il codice e rifà il deploy. |
| `Mandalo prima in staging.` | Una copia collegata con un indirizzo suo. La mandi in produzione quando ti convince, in entrambe le direzioni. |
| `Installa qui WordPress, admin anna.` | WordPress installato e pronto, con WP-CLI per tutto quello che viene dopo. |
| `Crea un database MySQL per questo progetto e un utente.` | Database, utente e permessi, senza che tu tocchi SQL. |
| `Ripristina il backup di ieri.` | Il backup viene ripristinato, e lo stato attuale viene messo da parte prima, per sicurezza. |
| `Fai entrare solo il nostro ufficio in questo strumento interno.` | Una regola di firewall che limita quel progetto agli indirizzi che indichi. |
| `Configura un tunnel Cloudflare per n8n.mydomain.com.` | DNS e tunnel pronti, ed è anche il modo di servire da un server dietro NAT. |
| `Quanto traffico abbiamo ricevuto la settimana scorsa?` | Utilizzo, log e limiti di quel progetto, e di tutto il server. |

Altri esempi pronti: [cosa chiedere](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). L'elenco completo di ciò che il tuo assistente può raggiungere: [199 strumenti](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">Un VPS. Più progetti. Funziona con qualsiasi cosa.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
I tuoi strumenti, progetti costruiti con l'AI o software open source trovato online. Non importa: PanelAlpha Engine lo fa girare. Qualche applicazione riceve un trattamento a parte perché l'approccio generale la romperebbe: WordPress, Matomo, phpBB, Magento e Passbolt. Vedi l'<a href="docs/07-supported-projects/project-types.md">elenco completo</a>.
</p>

---

## Non lasciare che l'AI trasformi il tuo server in un caos

Dare all'AI accesso root completo significa porte aperte, impostazioni casuali e un progetto che ne danneggia un altro. PanelAlpha Engine stabilisce le regole:

- **Meno spazio per gli errori.** L'AI lavora attraverso il motore, non direttamente sul server.
- **Una struttura chiara.** Progetti, account e domini restano organizzati.
- **Isolamento dei progetti.** Ognuno gira nel proprio container Docker, con limiti propri per disco, memoria e CPU, e solo le porte necessarie aperte.

Sei tu a decidere cosa può fare l'assistente prima di collegarlo. [Decidi cosa può fare l'assistente](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [Sicurezza](docs/05-capabilities/security.md)

---

## Tutto ciò che serve per la produzione, pronto all'uso

Il deploy è solo l'inizio. La gestione continua c'è dalla prima installazione:

- **Guardalo online subito.** Un hostname `panelalpha.online` gratuito su ogni progetto, più i tuoi domini quando vuoi. I certificati arrivano da Let's Encrypt e si rinnovano da soli. [Domini e HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **Costruisci in staging. Vai online quando sei pronto.** Una copia collegata su cui sperimentare liberamente, da mandare in produzione quando funziona. Puoi anche fare il contrario, per aggiornare la copia di prova con dati reali. [Progetti](docs/05-capabilities/projects.md#staging)
- **Monitoraggio integrato.** Metriche del server, log per sito, report Lighthouse su richiesta e un controllo ogni sei ore che ogni sito stia ancora servendo sé stesso invece di una pagina vuota. [Monitoraggio e log](docs/05-capabilities/monitoring-and-logs.md)
- **Tutto l'essenziale è coperto.** Backup sul server e fuori, SSL, domini, database, accessi FTP e SFTP, cron e log. [Backup](docs/05-capabilities/backups.md) · [Database](docs/05-capabilities/databases.md) · [File e accessi](docs/05-capabilities/files-and-access.md)
- **Ogni progetto ha il proprio spazio sicuro.** Isolamento Docker, firewall e ModSecurity con le regole OWASP. Un'app guasta non può abbattere le altre. [Sicurezza](docs/05-capabilities/security.md)
- **Cloudflare in una sola connessione.** DNS, tunnel e caching per tutti i tuoi progetti, anche per servire un sito da un server dietro NAT. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## Quando un deploy fallisce, e come il motore ci impara qualcosa

La maggior parte dei primi deploy riesce. Quelli che non riescono di solito hanno un problema nel repository. La maggior parte degli strumenti ti lascia con uno stack trace. Qui il percorso è:

- **Ricevi una frase, non uno stack trace.** "Questo progetto richiede PHP 8.2, ma è stato costruito con PHP 8.1." "La build ha esaurito la memoria." L'output completo resta sotto, se a qualcuno serve.
- **Da lì in poi ci pensa il tuo assistente.** Legge il log, guarda cosa sta servendo davvero il sito, sistema nel codice quello che si può sistemare e rifà il deploy. Quel lavoro gira sull'abbonamento AI che già paghi, non su token API.
- **Il motore ci impara qualcosa.** Un rapporto anonimo arriva a PanelAlpha. Un guasto sul tuo server può essere il tuo repository. Lo stesso guasto su trenta server è un bug nel rilevamento o in una ricetta per un framework, e diventa una correzione nell'aggiornamento successivo.
- **E quando è il motore ad aver sbagliato**, dillo e raccoglie le prove: chiedilo al tuo assistente, oppure esegui `pae telemetry:bug-report <progetto>`.

L'intera procedura: [quando un deploy fallisce](docs/02-getting-started/what-happens.md) · [cosa significa l'errore](docs/02-getting-started/reading-errors.md).

---

## Telemetria

La telemetria è attiva dopo l'installazione. Rapporti anonimi su deploy e controlli di salute successivi lasciano il server finché non la spegni. Includono i nomi pubblici dei tuoi siti.

- **Inviato:** che tipo di applicazione era, quanto ci ha messo, i limiti del progetto e, in caso di errore, la fase che si è rotta più la coda del log con i segreti rimossi. Un rapporto di salute solo quando un sito smette in seguito di servire sé stesso. Un rapporto di bug solo quando lo mandi tu.
- **Mai inviato:** il tuo codice sorgente, i nomi dei progetti, token, password, l'indirizzo IP o il nome del tuo server, i nomi dei repository privati, o qualsiasi cosa sulle persone che visitano i tuoi siti.
- **Guarda un rapporto prima di decidere:** `pae telemetry:status` e `pae telemetry:show`.

Per smettere di inviare:

```bash
pae telemetry:disable
```

Cosa viene raccolto, cosa no, e tutti i modi per spegnerla: [cosa viene raccolto](docs/02-getting-started/what-is-collected.md) · [come spegnerla](docs/02-getting-started/how-to-turn-it-off.md).

---

## FAQ

<details>
<summary><b>Perché mi serve davvero PanelAlpha Engine?</b></summary>

Perché un'applicazione non è finita quando il codice è finito. Il tuo progetto ha ancora bisogno di un posto in cui girare, per non parlare di tutto ciò che serve per tenerlo in salute, raggiungibile e al sicuro. PanelAlpha Engine dà al tuo assistente AI un modo vero di gestire per te tutto quello strato. Chiedi il risultato che vuoi, e il motore lo trasforma in operazioni controllate sul server, senza dare all'AI accesso illimitato alla macchina.

Se conosci già Docker, i reverse proxy, i firewall e i log del server, quella conoscenza conta ancora. PanelAlpha non cerca di nasconderti l'infrastruttura né di chiudertela fuori. Ti dà un modo più pulito di operarla, automatizzare le parti ripetitive e lasciare che l'AI prenda in carico lavoro vero senza cedere il controllo. Puoi scendere tanto in profondità quanto vuoi quando qualcosa merita la tua attenzione, e saltare la routine quando non la merita.
</details>

<details>
<summary><b>Mi serve un server mio?</b></summary>

Sì. PanelAlpha Engine è uno strumento che permette agli agenti AI di gestire *il tuo* server. Ti serve un VPS nuovo con Debian o Ubuntu, almeno 2 GB di RAM e 1 CPU, su cui accedi come `root` via SSH. Inizi eseguendo tu stesso l'installazione di Engine su quel VPS. [Cosa ti serve](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>Con quali assistenti AI funziona?</b></summary>

Con qualsiasi assistente che sappia collegarsi a un server MCP con un token. Ci sono pagine passo passo per [Claude Code](docs/04-connecting-your-ai/claude-code.md), [Cursor](docs/04-connecting-your-ai/cursor.md), [Codex](docs/04-connecting-your-ai/codex.md), [Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md), [VS Code e Copilot](docs/04-connecting-your-ai/vs-code-copilot.md), [Grok](docs/04-connecting-your-ai/grok.md), [OpenCode](docs/04-connecting-your-ai/opencode.md), [Windsurf](docs/04-connecting-your-ai/windsurf.md), [Pi](docs/04-connecting-your-ai/pi.md), [Hermes](docs/04-connecting-your-ai/hermes.md) e [OpenClaw](docs/04-connecting-your-ai/openclaw.md), più [tutto il resto che parla MCP](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>Cosa posso mettere online?</b></summary>

L'obiettivo è uno strumento universale per qualsiasi progetto. Siti statici, WordPress, PHP, Laravel, Node, Next.js, Django, Go, Rust, Java, un semplice `Dockerfile` o un file Compose. I tuoi strumenti, progetti costruiti con l'AI e il software open source trovato in rete. Vedi i [tipi di progetto](docs/07-supported-projects/project-types.md) e, se vuoi essere sicuro prima, chiedi al tuo assistente di ispezionare il repository.
</details>

<details>
<summary><b>È gratis? Cosa pago?</b></summary>

PanelAlpha Engine è gratuito e open source con licenza Apache 2.0. Paghi il server e l'abbonamento AI, e li hai già entrambi. Il debug di un deploy fallito gira sull'abbonamento del tuo assistente, non su token API.
</details>

<details>
<summary><b>Posso limitare cosa può fare il mio assistente?</b></summary>

Sì, e conviene farlo prima di incollare un token. Puoi dargli un token in sola lettura, permettere le modifiche ma non le cancellazioni, esporre solo alcune aree o togliere singoli strumenti come `project_delete`. [Decidi cosa può fare l'assistente](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>Cosa viene segnalato se un deploy fallisce?</b></summary>

Un riassunto anonimo: il tipo di applicazione, la fase che si è rotta, la coda ripulita del log e i nomi pubblici dei siti. Mai il codice sorgente, i nomi dei progetti, le credenziali o l'identità del server. Puoi stampare un rapporto in coda con `pae telemetry:show` e spegnere tutto. Tutti i dettagli: [Telemetria](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>Posso usarlo per hosting condiviso?</b></summary>

Sì. Ogni progetto è un account separato con i propri domini, database, file e limiti. I provider di hosting gestiscono migliaia di siti in produzione su questo motore.
</details>

<details>
<summary><b>Il motore ha capito male il mio progetto. E adesso?</b></summary>

Manda un rapporto di bug e raccoglie le prove da solo. Chiedilo al tuo assistente, oppure esegui sul server `pae telemetry:bug-report <progetto>`. Con `--dry-run` vedi prima esattamente cosa partirebbe.
</details>

---

## Parla con noi su Discord

**Discord è il posto principale per raggiungerci.** Fai una domanda, mostraci cosa hai messo online, condividi un'idea, o passa e vedi su cosa stiamo lavorando. Siamo in mezzo a quelle conversazioni, e quello che sollevi lì può diventare la prossima cosa che costruiamo, sistemiamo o ripensiamo.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="Parla con noi su Discord, il posto principale per raggiungere il team di PanelAlpha" width="760">
</a>
</div><br>

Non sei un tipo da Discord? [Il nostro forum](https://community.panelalpha.com/) è altrettanto aperto.

## Il tuo server è a un solo comando di distanza

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## Sicurezza

Una macchina che ospita il motore serve a quello e basta. L'installer sostituisce il resolver e il firewall, quindi trattala di conseguenza.

Hai trovato una vulnerabilità? Segnalala **in privato**, mai in una issue pubblica. Vedi [`SECURITY.md`](SECURITY.md), oppure usa [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## Licenza

PanelAlpha Engine è open source con licenza Apache 2.0.

## Vieni a costruire con noi

Vuoi partecipare? [`CONTRIBUTING.md`](CONTRIBUTING.md) ti mette in marcia. La documentazione per chi gestisce il server è in [`docs/`](docs/README.md).
