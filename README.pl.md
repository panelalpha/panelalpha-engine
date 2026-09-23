<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>Twój agent AI prowadzi<br>Twój serwer. Pod kontrolą.</h1>

<h3>
Open source. Self-hosted. Łatwy dla każdego, nie tylko dla sysadminów.
</h3>

<h3>
<a href="#trzy-proste-kroki-żeby-to-ustawić"><b>Zacznij</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>Dokumentacja</b></a> ·
<a href="#porozmawiaj-z-nami-na-discordzie"><b>Discord</b></a>
</h3>

<p>
<a href="#o-co-możesz-po-prostu-poprosić">Kontrola AI</a> ·
<a href="#wszystko-czego-potrzebujesz-na-produkcji-od-razu">Możliwości</a> ·
<a href="#telemetria">Telemetria</a> ·
<a href="#faq">FAQ</a>
</p>

<p>
<a href="README.md">English</a>
· <b>Polski</b>
· <a href="README.de.md">Deutsch</a>
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
<a href="#krok-1-zainstaluj-engine-na-swoim-vps-ie"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="Instalacja jedną komendą"></a>
<a href="#krok-1-zainstaluj-engine-na-swoim-vps-ie"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="Wspierane systemy"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-199_tools-6f42c1" alt="199 narzędzi MCP"></a>
<a href="#licencja"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="Dołącz do Discorda"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="Wdrożenie projektu przez rozmowę z asystentem" width="760"></p>

</div>

---

## Czym jest PanelAlpha Engine?

PanelAlpha Engine to oprogramowanie, które instalujesz na swoim VPS-ie, żeby hostować projekty zbudowane z AI i vibe-coded, strony i aplikacje open source znalezione w sieci. Po instalacji podłączasz własnego agenta AI wprost do PanelAlpha Engine i pozwalasz mu zająć się wdrożeniami, utrzymaniem i zarządzaniem serwerem. Twój serwer zostaje poukładany i pod kontrolą.

**Ale najważniejsze: zarządzanie własnym serwerem/VPS-em staje się naprawdę proste**. Nie musisz już być sysadminem, żeby self-hostować!

Od razu po instalacji PanelAlpha Engine daje Tobie i Twojemu AI wszystko, czego potrzebujecie, żeby prowadzić prawdziwe projekty na produkcji:

- Wdrażaj dowolny stos z Gita albo z plików
- Natychmiastowe adresy podglądu, z opcjonalnym hasłem
- Staging i przepływy Git, z osobnym środowiskiem live i staging
- Automatyczne kopie zapasowe i przywracanie
- Monitoring zewnętrzny i wbudowane statystyki odwiedzin
- Izolacja projektów w osobnych kontenerach Dockera
- Zapora i ochrona OWASP/WAF z regułami dostępu per projekt
- Domeny, SSL, cron, FTP/SFTP, bazy danych, logi
- Prosta integracja z Cloudflare: DNS, Tunele i cache

## Po co to musi istnieć

AI wyłamało tworzenie oprogramowania ze starych ograniczeń. Więcej ludzi potrafi zamienić pomysł w działający produkt, małe zespoły budują dużo więcej niż wcześniej, a open source kwitnie projektami, które warto wziąć na własne. To, co prawie nie przyspieszyło, to praca potrzebna, żeby to samemu prowadzić. Większość narzędzi self-hosted nadal oczekuje, że ogarniesz Dockera, serwer WWW, certyfikaty, bazy, kopie zapasowe, zaporę i aktualizacje, które potem chodzą za Tobą.

**PanelAlpha Engine** sprawia, że zarządzanie oprogramowaniem na produkcji jest tak samo łatwe, jak AI uczyniło jego budowanie.

- **Jedna komenda, raz.** Potem rozmawiasz z asystentem AI, którego i tak używasz.
- **Prosisz zwykłym językiem.** Silnik buduje projekt, uruchamia go, daje mu HTTPS i pilnuje kopii zapasowych.
- **AI nie dostaje roota.** Działa przez silnik, w ramach zasad, które ustawiasz.

[**Dołącz do Discorda**](https://discord.gg/9twHWR7xGX). To główne miejsce, żeby zapytać, pokazać co wdrożyłeś i pogadać z ludźmi, którzy to budują.

---

## Trzy proste kroki, żeby to ustawić

### Krok 1: Zainstaluj Engine na swoim VPS-ie

Potrzebujesz **świeżego** serwera z Debianem 12/13 albo Ubuntu 22.04/24.04/26.04, z minimum 2 GB RAM i 1 CPU, i logujesz się jako `root` przez SSH:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

To wszystko. Serwer jest gotowy. Własna nazwa, własny certyfikat TLS albo serwer za NAT-em: [opcje instalacji](docs/02-getting-started/install.md).

### Krok 2: Podłącz swojego agenta AI

Na koniec instalacji silnik pyta, którego asystenta używasz, i wypisuje gotową komendę do uruchomienia na Twoim komputerze. Żeby podłączyć kolejnego później, uruchom na serwerze:

```bash
pae connect
```

`pae connect` uruchamiasz na serwerze silnika, nie na laptopie. Linia, którą wypisze, to ta, którą odpalasz na swoim komputerze.

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

Używasz czegoś innego? Zadziała każdy asystent, który mówi po MCP: [podłączanie innych asystentów](docs/04-connecting-your-ai/other-mcp-clients.md).

Token z domyślnymi uprawnieniami potrafi skasować cały projekt. Możesz wydać token tylko do odczytu albo odebrać pojedyncze narzędzia: [zdecyduj, na co pozwalasz asystentowi](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### Krok 3: Gotowe. Poproś o to, czego chcesz

Otwórz czat asystenta i powiedz to tak, jak powiedziałbyś człowiekowi:

```text
wdróż github.com/anna/invoicer na moim serwerze
```

> Gotowe. Dostępne pod `invoicer.panelalpha.online`

Każdy projekt dostaje darmowy adres `panelalpha.online` w momencie powstania. Gdy będziesz gotowy, poproś o własną domenę, a certyfikat przychodzi razem z nią.

Wolisz zrobić to sam z poziomu serwera? [Wdróż wprost z gita](docs/README.md#3-put-your-project-online).

---

## O co możesz po prostu poprosić

Nie ma komend do nauczenia się i nie ma magicznych formułek. To przykłady poziomu szczegółowości, który warto dać:

| Mówisz | Co się dzieje |
|:---|:---|
| `Wdróż github.com/org/app na tym silniku.` | Powstaje projekt, silnik wykrywa stos, buduje i uruchamia aplikację i daje jej adres z HTTPS. |
| `Dodaj shop.example.com do tego projektu i weź certyfikat.` | Domena zostaje podpięta, a Let's Encrypt wystawia certyfikat, który sam się odnawia. |
| `Ta strona się nie otwiera. Przeczytaj log wdrożenia i napraw, co możesz.` | Asystent czyta log, sprawdza, co strona naprawdę serwuje, zmienia kod i próbuje jeszcze raz. |
| `Najpierw wypchnij to na staging.` | Powiązana kopia z własnym adresem. Na live, gdy będziesz zadowolony, w obie strony. |
| `Zainstaluj tu WordPressa, admin anna.` | WordPress gotowy, z WP-CLI do wszystkiego potem. |
| `Załóż bazę MySQL dla tego projektu i użytkownika do niej.` | Baza, użytkownik i uprawnienia, bez ruszania SQL. |
| `Przywróć wczorajszą kopię zapasową.` | Kopia wraca, a obecny stan jest najpierw zapisany, na wszelki wypadek. |
| `Niech do tego narzędzia dochodzi tylko biuro.` | Reguła zapory ograniczająca ten projekt do podanych adresów. |
| `Zrób tunel Cloudflare dla n8n.mydomain.com.` | DNS i tunel, czyli też sposób, żeby serwować stronę z serwera za NAT-em. |
| `Ile ruchu mieliśmy w zeszłym tygodniu?` | Użycie, logi i limity tego projektu, i serwera jako całości. |

Więcej przykładów: [o co poprosić](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). Pełna lista tego, do czego asystent ma dostęp: [199 narzędzi](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">Jeden VPS. Wiele projektów. Działa z wszystkim.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
Twoje narzędzia, projekty zbudowane z AI albo oprogramowanie open source znalezione w sieci. Nie ma znaczenia: PanelAlpha Engine po prostu to uruchamia. Kilka aplikacji dostaje dodatkową opiekę, bo ogólne podejście by je źle obsłużyło: WordPress, Matomo, phpBB, Magento i Passbolt. Zobacz <a href="docs/07-supported-projects/project-types.md">pełną listę</a>.
</p>

---

## Nie pozwól, żeby AI zrobiło bałagan na serwerze

Pełny root dla AI to otwarte porty, przypadkowe ustawienia i jeden projekt wpływający na drugi. PanelAlpha Engine ustala zasady:

- **Mniej miejsca na błędy.** AI działa przez silnik, nie bezpośrednio na serwerze.
- **Jasna struktura.** Projekty, konta i domeny zostają poukładane.
- **Izolacja projektów.** Każdy w swoim kontenerze Dockera, z własnymi limitami dysku, pamięci i CPU, i tylko z portami, których potrzebuje.

Na co asystent ma pozwolenie, decydujesz zanim go podłączysz. [Zdecyduj, na co pozwalasz asystentowi](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [Bezpieczeństwo](docs/05-capabilities/security.md)

---

## Wszystko, czego potrzebujesz na produkcji, od razu

Wdrożenie to dopiero początek. Bieżące zarządzanie jest od pierwszej instalacji:

- **Od razu online.** Darmowy hostname `panelalpha.online` na każdy projekt, plus własne domeny, kiedy ich chcesz. Certyfikaty z Let's Encrypt, odnawiają się same. [Domeny i HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **Buduj na stagingu. Na live, gdy gotowe.** Powiązana kopia, którą możesz psuć, potem wypchnięcie na live. W drugą stronę też, żeby odświeżyć kopię testową prawdziwymi danymi. [Projekty](docs/05-capabilities/projects.md#staging)
- **Wbudowany monitoring.** Metryki serwera, logi per strona, raporty Lighthouse na żądanie i co sześć godzin sprawdzenie, czy każda strona nadal serwuje siebie, a nie pustą stronę. [Monitoring i logi](docs/05-capabilities/monitoring-and-logs.md)
- **Wszystkie podstawy są pokryte.** Kopie na serwerze i poza nim, SSL, domeny, bazy, logowania FTP i SFTP, cron i logi. [Kopie zapasowe](docs/05-capabilities/backups.md) · [Bazy danych](docs/05-capabilities/databases.md) · [Pliki i dostęp](docs/05-capabilities/files-and-access.md)
- **Własna bezpieczna przestrzeń na projekt.** Izolacja Dockera, zapora i ModSecurity z regułami OWASP. Jedna zepsuta aplikacja nie bierze pozostałych ze sobą. [Bezpieczeństwo](docs/05-capabilities/security.md)
- **Cloudflare jednym podłączeniem.** DNS, tunele i cache między projektami, w tym strona z serwera za NAT-em. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## Gdy wdrożenie się nie udaje, i jak silnik się z tego uczy

Większość pierwszych wdrożeń działa. Te, które nie, to zwykle problem w repozytorium. Większość narzędzi zostawia Cię ze stack trace. Tutaj ścieżka jest taka:

- **Dostajesz zdanie, nie ślad.** „Ten projekt potrzebuje PHP 8.2, a został zbudowany z PHP 8.1.” „Buildowi skończyła się pamięć.” Pełny output nadal jest pod spodem, jeśli ktoś go chce.
- **Od tego miejsca przejmuje asystent.** Czyta log, patrzy, co strona naprawdę serwuje, naprawia w kodzie to, co da się naprawić, i wdraża jeszcze raz. Ta praca idzie na abonament AI, który i tak płacisz, nie na tokeny API.
- **Silnik się z tego uczy.** Anonimowy raport wraca do PanelAlpha. Jedna porażka na Twoim serwerze może być Twoim repozytorium. Ta sama na trzydziestu serwerach to błąd w detekcji albo w przepisie na framework, i staje się poprawką w następnej aktualizacji.
- **Gdy to silnik się pomylił**, powiedz o tym, a zbierze dowody: poproś asystenta, albo uruchom `pae telemetry:bug-report <projekt>`.

Cała procedura: [gdy wdrożenie się nie udaje](docs/02-getting-started/what-happens.md) · [co oznacza błąd](docs/02-getting-started/reading-errors.md).

---

## Telemetria

Telemetria jest włączona po instalacji. Anonimowe raporty o wdrożeniach i późniejszych sprawdzeniach zdrowia opuszczają serwer, dopóki ich nie wyłączysz. Zawierają publiczne nazwy Twoich stron.

- **Wysyłane:** jaki to był rodzaj aplikacji, ile trwało, limity projektu, a przy porażce etap, który padł, plus ocenzurowany koniec logu. Raport zdrowia tylko gdy strona później przestanie serwować siebie. Raport błędu tylko gdy sam go złożysz.
- **Nigdy:** kod źródłowy, nazwy projektów, tokeny, hasła, IP albo hostname serwera, nazwy prywatnych repozytoriów ani nic o ludziach, którzy odwiedzają Twoje strony.
- **Zobacz raport, zanim zdecydujesz:** `pae telemetry:status` i `pae telemetry:show`.

Żeby przestać wysyłać:

```bash
pae telemetry:disable
```

Co jest zbierane, co nie, i wszystkie sposoby wyłączenia: [co jest zbierane](docs/02-getting-started/what-is-collected.md) · [jak to wyłączyć](docs/02-getting-started/how-to-turn-it-off.md).

---

## FAQ

<details>
<summary><b>Po co mi w ogóle PanelAlpha Engine?</b></summary>

Bo aplikacja nie jest skończona, gdy skończony jest kod. Projekt nadal potrzebuje miejsca, w którym ma działać, nie mówiąc o wszystkim, co trzeba, żeby był zdrowy, osiągalny i bezpieczny. PanelAlpha Engine daje Twojemu asystentowi AI właściwy sposób, żeby zajął się całą tą warstwą za Ciebie. Prosisz o efekt, którego chcesz, a silnik zamienia to w kontrolowane operacje na serwerze, bez dawania AI nieograniczonego dostępu do maszyny.

Jeśli już znasz Dockera, reverse proxy, zapory i logi serwera, ta wiedza nadal ma znaczenie. PanelAlpha nie chowa przed Tobą infrastruktury i nie zamyka Cię poza nią. Daje czystszy sposób, żeby nią operować, zautomatyzować powtarzalne części i pozwolić AI wziąć na siebie prawdziwą pracę bez oddawania kontroli. Możesz zejść tak głęboko, jak chcesz, gdy coś zasługuje na Twoją uwagę, i pominąć rutynę, gdy nie zasługuje.
</details>

<details>
<summary><b>Czy potrzebuję własnego serwera?</b></summary>

Tak. PanelAlpha Engine to narzędzie, którym agenci AI zarządzają *Twoim* serwerem. Potrzebujesz świeżego VPS-a z Debianem albo Ubuntu, z minimum 2 GB RAM i 1 CPU, na który logujesz się jako `root` przez SSH. Zaczynasz od samodzielnej instalacji Engine na tym VPS-ie. [Czego potrzebujesz](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>Które agenty AI działają z PanelAlpha Engine?</b></summary>

Każdy asystent, który potrafi podłączyć się do serwera MCP tokenem. Są strony krok po kroku dla [Claude Code](docs/04-connecting-your-ai/claude-code.md), [Cursor](docs/04-connecting-your-ai/cursor.md), [Codex](docs/04-connecting-your-ai/codex.md), [Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md), [VS Code i Copilot](docs/04-connecting-your-ai/vs-code-copilot.md), [Grok](docs/04-connecting-your-ai/grok.md), [OpenCode](docs/04-connecting-your-ai/opencode.md), [Windsurf](docs/04-connecting-your-ai/windsurf.md), [Pi](docs/04-connecting-your-ai/pi.md), [Hermes](docs/04-connecting-your-ai/hermes.md) i [OpenClaw](docs/04-connecting-your-ai/openclaw.md), plus [wszystko inne, co mówi po MCP](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>Jakie projekty mogę wdrażać?</b></summary>

Cel to uniwersalne narzędzie na dowolny projekt. Strony statyczne, WordPress, PHP, Laravel, Node, Next.js, Django, Go, Rust, Java, zwykły `Dockerfile` albo plik Compose. Twoje narzędzia, projekty zbudowane z AI i oprogramowanie open source znalezione w sieci. Zobacz [typy projektów](docs/07-supported-projects/project-types.md), a jeśli chcesz sprawdzić przed decyzją, poproś asystenta, żeby najpierw obejrzał repozytorium.
</details>

<details>
<summary><b>Czy to darmowe? Za co płacę?</b></summary>

PanelAlpha Engine jest darmowy i open source na licencji Apache 2.0. Płacisz za serwer i za abonament AI, jedno i drugie już masz. Debugowanie nieudanego wdrożenia idzie na abonament asystenta, nie na tokeny API.
</details>

<details>
<summary><b>Czy mogę ograniczyć, co wolno asystentowi?</b></summary>

Tak, i warto to zrobić, zanim wkleisz token. Możesz dać token tylko do odczytu, pozwolić na zmiany bez kasowania, odsłonić tylko niektóre obszary albo zabrać pojedyncze narzędzia, na przykład `project_delete`. [Zdecyduj, na co pozwalasz asystentowi](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>Co jest zgłaszane, gdy wdrożenie się nie uda?</b></summary>

Anonimowe podsumowanie: rodzaj aplikacji, etap, który padł, ocenzurowany koniec logu i publiczne nazwy stron. Nigdy kod, nazwy projektów, dane logowania ani tożsamość serwera. Kolejkowy raport możesz wypisać przez `pae telemetry:show` i całość wyłączyć. Szczegóły: [Telemetria](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>Czy mogę używać PanelAlpha Engine do shared hostingu?</b></summary>

Tak. Każdy projekt to osobne konto z własnymi domenami, bazami, plikami i limitami. Dostawcy hostingu prowadzą na tym tysiące stron na produkcji.
</details>

<details>
<summary><b>Silnik źle zrozumiał mój projekt. Co teraz?</b></summary>

Złóż zgłoszenie błędu, a sam zbierze dowody. Poproś asystenta albo uruchom na serwerze `pae telemetry:bug-report <projekt>`. Najpierw `--dry-run`, jeśli chcesz zobaczyć dokładnie, co zostałoby wysłane.
</details>

---

## Porozmawiaj z nami na Discordzie

**Discord to główne miejsce, w którym do nas trafisz.** Zadaj pytanie, pokaż nam, co wdrożyłeś, wrzuć pomysł albo po prostu wpadnij i zobacz, nad czym pracujemy. Jesteśmy tam w środku tych rozmów, a to, co tam podnosisz, może stać się następną rzeczą, którą zbudujemy, naprawimy albo przemyślimy od nowa.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="Porozmawiaj z nami na Discordzie, to główne miejsce kontaktu z zespołem PanelAlpha" width="760">
</a>
</div><br>

Nie przepadasz za Discordem? [Nasze forum](https://community.panelalpha.com/) jest równie otwarte.

## Twój serwer jest o jedną komendę stąd

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## Bezpieczeństwo

Host silnika to maszyna do jednego celu. Instalator podmienia resolver i zaporę, więc traktuj go w ten sposób.

Znalazłeś lukę? Zgłoś ją **prywatnie**, nigdy w publicznym zgłoszeniu. Zobacz [`SECURITY.md`](SECURITY.md) albo [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## Licencja

PanelAlpha Engine jest open source na licencji Apache 2.0.

## Buduj to razem z nami

Chcesz się zaangażować? [`CONTRIBUTING.md`](CONTRIBUTING.md) Cię wprowadzi. Dokumentacja dla operatora jest w [`docs/`](docs/README.md).
