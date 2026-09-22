<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>Ваш ШІ-агент керує<br>вашим сервером. Під контролем.</h1>

<h3>
Open source. Self-hosted. Просто для будь-кого, не лише для сисадмінів.
</h3>

<h3>
<a href="#три-прості-кроки-для-налаштування"><b>Почати</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>Документація</b></a> ·
<a href="#поговоріть-з-нами-в-discord"><b>Discord</b></a>
</h3>

<p>
<a href="#про-що-можна-просто-попросити">Керування ШІ</a> ·
<a href="#усе-необхідне-для-продакшну-одразу-після-встановлення">Можливості</a> ·
<a href="#телеметрія">Телеметрія</a> ·
<a href="#faq">FAQ</a>
</p>

<p>
<a href="README.md">English</a>
· <a href="README.pl.md">Polski</a>
· <a href="README.de.md">Deutsch</a>
· <a href="README.nl.md">Nederlands</a>
· <a href="README.es.md">Español</a>
· <a href="README.fr.md">Français</a>
· <a href="README.it.md">Italiano</a>
· <a href="README.pt-BR.md">Português</a>
· <b>Українська</b>
· <a href="README.ar.md">العربية</a>
· <a href="README.zh-CN.md">简体中文</a>
</p>

<p>
<a href="#крок-1-встановіть-engine-на-своєму-vps"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="Встановлення однією командою"></a>
<a href="#крок-1-встановіть-engine-на-своєму-vps"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="Підтримувані системи"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-182_tools-6f42c1" alt="182 інструментів MCP"></a>
<a href="#ліцензія"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="Приєднуйтесь до Discord"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="Розгортання проєкту через прохання до асистента" width="760"></p>

</div>

---

## Що таке PanelAlpha Engine?

PanelAlpha Engine - це програмне забезпечення, яке ви встановлюєте на своєму VPS, щоб хостити проєкти, зібрані з ШІ та vibe-coded, сайти й open source застосунки, знайдені в мережі. Після встановлення ви підключаєте власного ШІ-агента безпосередньо до PanelAlpha Engine і даєте йому займатися розгортанням, підтримкою та керуванням сервером. Ваш сервер лишається впорядкованим і під контролем.

**Але найважливіше: керувати власним сервером/VPS стає по-справжньому просто**. Вам більше не треба бути сисадміном, щоб робити self-hosting!

Одразу після встановлення PanelAlpha Engine дає вам і вашому ШІ все потрібне, щоб вести реальні проєкти в продакшні:

- Розгортайте будь-який стек із Git або з файлів
- Миттєві URL попереднього перегляду, за бажанням із паролем
- Staging і Git-процеси з окремими live- і staging-середовищами
- Автоматичні резервні копії та відновлення
- Зовнішній моніторинг і вбудована статистика відвідувань
- Ізоляція проєктів в окремих контейнерах Docker
- Фаєрвол і захист OWASP/WAF з правилами доступу для кожного проєкту
- Домени, SSL, cron, FTP/SFTP, бази даних, логи
- Просте підключення Cloudflare для DNS, тунелів і кешування

## Чому це має існувати

ШІ вивів створення програм зі старих меж. Більше людей можуть перетворити ідею на робочий продукт, невеликі команди будують значно більше, ніж раніше, а open source повний проєктів, які варто зробити своїми. Те, що майже не прискорилося так само, - це робота, потрібна, щоб вести це самостійно. Більшість self-hosted інструментів досі очікує, що ви розумієте і керуєте Docker, вебсервером, сертифікатами, базами даних, резервними копіями, фаєрволом і оновленнями, які потім ідуть за цим.

**PanelAlpha Engine** робить керування програмним забезпеченням у продакшні таким самим простим, яким ШІ зробив його створення.

- **Одна команда, один раз.** Далі ви спілкуєтеся з ШІ-асистентом, яким і так користуєтеся.
- **Просите звичайними словами.** Рушій збирає проєкт, запускає його, дає HTTPS і тримає з резервними копіями.
- **ШІ ніколи не отримує root.** Він працює через рушій, у межах правил, які ви встановлюєте.

[**Приєднуйтесь до Discord**](https://discord.gg/9twHWR7xGX). Це головне місце, щоб запитати, показати, що ви розгорнули, і поговорити з людьми, які це будують.

---

## Три прості кроки для налаштування

### Крок 1: Встановіть Engine на своєму VPS

Вам потрібен **чистий** сервер із Debian 12/13 або Ubuntu 22.04/24.04/26.04, щонайменше 2 ГБ RAM і 1 CPU, і ви заходите як `root` через SSH:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

Це все. Сервер готовий. Власна назва, власний сертифікат TLS чи сервер за NAT: [опції встановлення](docs/02-getting-started/install.md).

### Крок 2: Підключіть свого ШІ-агента

Наприкінці встановлення рушій питає, яким асистентом ви користуєтеся, і друкує точну команду для вашого комп'ютера. Щоб підключити ще одного пізніше, виконайте на сервері:

```bash
pae connect
```

`pae connect` виконуйте на сервері рушія, не на ноутбуці. Рядок, який він друкує, запускаєте на своєму комп'ютері.

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

Користуєтеся чимось іншим? Підійде будь-який асистент, що говорить по MCP: [підключення інших асистентів](docs/04-connecting-your-ai/other-mcp-clients.md).

Токен зі стандартними правами може видалити цілий проєкт. Можна видати токен лише для читання або забрати окремі інструменти: [вирішіть, що дозволено асистентові](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### Крок 3: Готово. Просто попросіть про те, що вам потрібно

Відкрийте чат асистента і скажіть так, як сказали б людині:

```text
розгорни github.com/anna/invoicer на моєму сервері
```

> Готово. Доступно за `invoicer.panelalpha.online`

Кожен проєкт одразу отримує безкоштовну адресу `panelalpha.online`. Коли будете готові, попросіть власний домен, і сертифікат прийде разом із ним.

Волієте зробити це із сервера самі? [Розгортання прямо з git](docs/README.md#3-put-your-project-online).

---

## Про що можна просто попросити

Немає команд, які треба вивчити, і немає магічних формулювань. Це приклади того, наскільки детально варто просити:

| Ви кажете | Що відбувається |
|:---|:---|
| `Розгорни github.com/org/app на цьому рушії.` | Створюється проєкт, визначається стек, застосунок збирається, запускається й отримує адресу з HTTPS. |
| `Додай shop.example.com до цього проєкту і візьми сертифікат.` | Домен під'єднано, а Let's Encrypt видає сертифікат, що поновлюється сам. |
| `Цей сайт не відкривається. Прочитай лог розгортання і виправ, що зможеш.` | Асистент читає лог, дивиться, що сайт реально віддає, змінює ваш код і розгортає знову. |
| `Спочатку відправ це на staging.` | Пов'язана копія з власною адресою. Виштовхуєте її на продакшн, коли все добре, в обидва боки. |
| `Постав тут WordPress, адмін anna.` | WordPress встановлено й готово, а для всього далі є WP-CLI. |
| `Створи базу MySQL для цього проєкту і користувача до неї.` | База, користувач і права, без того, щоб ви торкалися SQL. |
| `Віднови вчорашню резервну копію.` | Копію відновлено, а поточний стан перед тим збережено, про всяк випадок. |
| `Пусти до цього внутрішнього інструменту лише наш офіс.` | Правило фаєрвола, що обмежує проєкт адресами, які ви назвете. |
| `Налаштуй тунель Cloudflare для n8n.mydomain.com.` | DNS і тунель налаштовано, і це ж спосіб віддавати сайт із сервера за NAT. |
| `Скільки трафіку ми отримали минулого тижня?` | Використання, логи й ліміти цього проєкту, а також усього сервера. |

Більше готових прикладів: [про що просити](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). Повний перелік того, до чого має доступ ваш асистент: [182 інструментів](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">Один VPS. Багато проєктів. Працює з чим завгодно.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
Ваші власні інструменти, проєкти, створені з ШІ, або open source програми, знайдені в мережі. Це не має значення: PanelAlpha Engine просто запускає їх. Кілька застосунків мають окреме поводження, бо загальний підхід їх зламав би: WordPress, Matomo, phpBB, Magento і Passbolt. Дивіться <a href="docs/07-supported-projects/project-types.md">повний перелік</a>.
</p>

---

## Не дозволяйте ШІ перетворити ваш сервер на безлад

Повний root-доступ для ШІ означає відкриті порти, випадкові налаштування й один проєкт, що впливає на інший. PanelAlpha Engine встановлює правила:

- **Менше простору для помилок.** ШІ працює через рушій, а не безпосередньо на сервері.
- **Чітка структура.** Проєкти, облікові записи й домени залишаються впорядкованими.
- **Ізоляція проєктів.** Кожен працює у власному Docker-контейнері, із власними лімітами на диск, пам'ять і CPU, та лише з потрібними відкритими портами.

Що саме дозволено асистентові, ви вирішуєте до підключення. [Вирішіть, що дозволено асистентові](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [Безпека](docs/05-capabilities/security.md)

---

## Усе необхідне для продакшну, одразу після встановлення

Розгортання лише початок. Постійне керування є від першого встановлення:

- **Одразу дивіться онлайн.** Безкоштовне ім'я `panelalpha.online` на кожен проєкт, плюс власні домени, коли захочете. Сертифікати надає Let's Encrypt, і вони поновлюються самі. [Домени і HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **Розробляйте на staging. Публікуйте, коли все готово.** Пов'язана копія, яку можна вільно ламати, а потім виштовхнути на продакшн. Можна й у зворотний бік, щоб оновити тестову копію реальними даними. [Проєкти](docs/05-capabilities/projects.md#staging)
- **Вбудований моніторинг.** Метрики сервера, логи кожного сайту, звіти Lighthouse на запит і перевірка кожні шість годин, чи кожен сайт досі віддає себе, а не порожню сторінку. [Моніторинг і логи](docs/05-capabilities/monitoring-and-logs.md)
- **Усі основи покриті.** Резервні копії на сервері й поза ним, SSL, домени, бази даних, доступи FTP і SFTP, cron і логи. [Резервні копії](docs/05-capabilities/backups.md) · [Бази даних](docs/05-capabilities/databases.md) · [Файли і доступи](docs/05-capabilities/files-and-access.md)
- **Кожен проєкт отримує власний безпечний простір.** Ізоляція Docker, фаєрвол і ModSecurity із правилами OWASP. Один зламаний застосунок не може зупинити інші. [Безпека](docs/05-capabilities/security.md)
- **Просте підключення Cloudflare.** DNS, тунелі й кешування в усіх своїх проєктах, зокрема для сайту на сервері за NAT. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## Коли розгортання не вдається, і як рушій на цьому вчиться

Більшість перших розгортань спрацьовує. Ті, що ні, зазвичай мають проблему в репозиторії. Більшість інструментів лишає вас зі стектрейсом. Тут шлях такий:

- **Ви отримуєте речення, а не стектрейс.** "Цьому проєкту потрібен PHP 8.2, а зібрано його з PHP 8.1." "Збірці забракло пам'яті." Повний технічний вивід і далі лежить нижче, якщо комусь потрібен.
- **Далі справу перебирає ваш асистент.** Він читає лог, дивиться, що сайт насправді віддає, виправляє у вашому коді те, що можна виправити, і розгортає знову. Ця робота йде з тієї ШІ-підписки, за яку ви вже платите, а не з API-токенів.
- **Рушій на цьому вчиться.** Анонімний звіт іде до PanelAlpha. Одна поломка на вашому сервері може бути вашим репозиторієм. Та сама поломка на тридцяти серверах - це помилка у визначенні стека або в рецепті для фреймворку, і вона стає виправленням у наступному оновленні.
- **А коли помилився саме рушій**, скажіть про це, і він збере докази: попросіть асистента це зробити або виконайте `pae telemetry:bug-report <проєкт>`.

Уся процедура: [коли розгортання не вдається](docs/02-getting-started/what-happens.md) · [що означає помилка](docs/02-getting-started/reading-errors.md).

---

## Телеметрія

Телеметрія увімкнена після встановлення. Анонімні звіти про розгортання і подальші перевірки стану залишають сервер, поки ви її не вимкнете. Вони містять публічні назви ваших сайтів.

- **Надсилається:** якого типу був застосунок, скільки часу це зайняло, ліміти проєкту, а при невдачі етап, що зламався, плюс кінець логу з вирізаними секретами. Звіт про стан лише коли сайт пізніше перестає віддавати себе. Звіт про помилку лише коли ви надсилаєте його самі.
- **Ніколи не надсилається:** ваш вихідний код, назви проєктів, токени, паролі, IP-адреса чи ім'я вашого сервера, назви приватних репозиторіїв або щось про людей, які відвідують ваші сайти.
- **Подивіться звіт, перш ніж вирішувати:** `pae telemetry:status` і `pae telemetry:show`.

Щоб зупинити надсилання:

```bash
pae telemetry:disable
```

Що збирається, що ні, і всі способи вимкнути: [що збирається](docs/02-getting-started/what-is-collected.md) · [як це вимкнути](docs/02-getting-started/how-to-turn-it-off.md).

---

## FAQ

<details>
<summary><b>Навіщо мені взагалі PanelAlpha Engine?</b></summary>

Бо застосунок не закінчений, коли закінчений код. Проєкту все одно потрібне місце, де він працюватиме, не кажучи вже про все, що треба, щоб він лишався здоровим, доступним і безпечним. PanelAlpha Engine дає вашому ШІ-асистентові справжній спосіб узяти на себе весь цей шар за вас. Ви просите потрібний результат, а рушій перетворює його на контрольовані операції на сервері, не даючи ШІ необмеженого доступу до машини.

Якщо ви вже знаєте Docker, зворотні проксі, фаєрволи і логи сервера, ці знання й далі мають значення. PanelAlpha не намагається сховати інфраструктуру від вас і не замикає вас поза нею. Він дає чистіший спосіб нею керувати, автоматизувати повторювані частини й дати ШІ взяти на себе справжню роботу без відмови від контролю. Ви можете заглибитися настільки, наскільки хочете, коли щось заслуговує на вашу увагу, і пропустити рутину, коли ні.
</details>

<details>
<summary><b>Чи потрібен мені власний сервер?</b></summary>

Так. PanelAlpha Engine є інструментом, за допомогою якого ШІ-агенти керують *вашим* сервером. Потрібен чистий VPS із Debian або Ubuntu, щонайменше 2 ГБ RAM і 1 CPU, на який ви заходите як `root` через SSH. Ви починаєте з того, що самі запускаєте встановлення Engine на цьому VPS. [Що потрібно](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>Які ШІ-асистенти з ним працюють?</b></summary>

Будь-який, що вміє під'єднатися до MCP-сервера з токеном. Є покрокові сторінки для [Claude Code](docs/04-connecting-your-ai/claude-code.md), [Cursor](docs/04-connecting-your-ai/cursor.md), [Codex](docs/04-connecting-your-ai/codex.md), [Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md), [VS Code і Copilot](docs/04-connecting-your-ai/vs-code-copilot.md), [Grok](docs/04-connecting-your-ai/grok.md), [OpenCode](docs/04-connecting-your-ai/opencode.md), [Windsurf](docs/04-connecting-your-ai/windsurf.md), [Pi](docs/04-connecting-your-ai/pi.md), [Hermes](docs/04-connecting-your-ai/hermes.md) та [OpenClaw](docs/04-connecting-your-ai/openclaw.md), а також [усього іншого, що говорить по MCP](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>Що я можу розгорнути з PanelAlpha Engine?</b></summary>

Мета - універсальний інструмент для будь-якого проєкту. Статичні сайти, WordPress, PHP, Laravel, Node, Next.js, Django, Go, Rust, Java, звичайний `Dockerfile` чи файл Compose. Ваші власні інструменти, проєкти, зроблені з ШІ, і open source, знайдений у мережі. Дивіться [типи проєктів](docs/07-supported-projects/project-types.md), а якщо хочете перевірити заздалегідь, попросіть асистента спершу оглянути репозиторій.
</details>

<details>
<summary><b>Це безкоштовно? За що я плачу?</b></summary>

PanelAlpha Engine безкоштовний і з відкритим кодом за ліцензією Apache 2.0. Ви платите за сервер і за ШІ-підписку, і те, й інше ви вже маєте. Налагодження невдалого розгортання йде з підписки вашого асистента, а не з API-токенів.
</details>

<details>
<summary><b>Чи можу я обмежити те, що робить асистент?</b></summary>

Так, і це варто зробити ще до того, як вставите токен. Можна дати токен лише для читання, дозволити зміни без видалення, відкрити лише окремі напрямки або забрати конкретні інструменти, як-от `project_delete`. [Вирішіть, що дозволено асистентові](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>Що повідомляється, якщо розгортання не вдалося?</b></summary>

Анонімне резюме: тип застосунку, етап, що зламався, відредагований кінець логу і публічні назви сайтів. Ніколи ваш код, назви проєктів, облікові дані чи ідентичність сервера. Звіт із черги можна надрукувати через `pae telemetry:show`, а все це вимкнути. Подробиці: [Телеметрія](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>Чи можна використати це для шаред-хостингу?</b></summary>

Так. Кожен проєкт є окремим обліковим записом із власними доменами, базами, файлами й лімітами. Хостинг-провайдери тримають на ньому тисячі сайтів у продакшні.
</details>

<details>
<summary><b>Рушій неправильно зрозумів мій проєкт. Що тепер?</b></summary>

Надішліть звіт про помилку, і він сам збере докази. Попросіть про це асистента або виконайте на сервері `pae telemetry:bug-report <проєкт>`. Якщо хочете спершу побачити, що саме піде, скористайтеся `--dry-run`.
</details>

---

## Поговоріть з нами в Discord

**Discord - це головне місце, щоб до нас звернутися.** Запитайте, покажіть нам, що розгорнули, поділіться ідеєю або просто заходьте й дивіться, над чим ми працюємо. Ми саме в центрі цих розмов, і те, що ви там піднімаєте, може стати наступним, що ми збудуємо, виправимо чи переосмислимо.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="Поговоріть з нами в Discord, головне місце контакту з командою PanelAlpha" width="760">
</a>
</div><br>

Не дуже про Discord? [Наш форум](https://community.panelalpha.com/) такий самий відкритий.

## Ваш сервер на відстані однієї команди

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## Безпека

Машина з рушієм існує для одного завдання. Інсталятор замінює резолвер і фаєрвол, тож ставтеся до неї відповідно.

Знайшли вразливість? Повідомте про неї **приватно**, ніколи в публічному issue. Дивіться [`SECURITY.md`](SECURITY.md) або скористайтеся [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## Ліцензія

PanelAlpha Engine має відкритий код за ліцензією Apache 2.0.

## Будуйте це разом з нами

Хочете долучитися? [`CONTRIBUTING.md`](CONTRIBUTING.md) вас запустить. Документація для оператора є в [`docs/`](docs/README.md).
