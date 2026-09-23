<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>让你的 AI 智能体跑你的服务器。<br>尽在掌控。</h1>

<h3>
开源。自托管。谁都能上手，不只是运维。
</h3>

<h3>
<a href="#三个简单步骤完成设置"><b>开始使用</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>文档</b></a> ·
<a href="#在-discord-上找我们"><b>Discord</b></a>
</h3>

<p>
<a href="#你可以直接开口要的事">AI 操控</a> ·
<a href="#生产环境需要的一切开箱即用">能力</a> ·
<a href="#遥测">遥测</a> ·
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
· <a href="README.uk.md">Українська</a>
· <a href="README.ar.md">العربية</a>
· <b>简体中文</b>
</p>

<p>
<a href="#第-1-步在自己的-vps-上安装-engine"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="一行命令安装"></a>
<a href="#第-1-步在自己的-vps-上安装-engine"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="支持的系统"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-199_tools-6f42c1" alt="199 个 MCP 工具"></a>
<a href="#许可证"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="加入 Discord"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="用一句话让助手部署项目" width="760"></p>

</div>

---

## 什么是 PanelAlpha Engine？

PanelAlpha Engine 是你装在 VPS 上的软件，用来托管用 AI 构建和 vibe-coded 的项目、网站，以及你在网上找到的开源应用。装好之后，把你自己的 AI 智能体直接连到 PanelAlpha Engine，让它帮你处理部署、维护和服务器管理。你的服务器保持有序、尽在掌控。

**但最重要的是，管理自己的服务器/VPS 变得超级简单。** 你不必再当运维才能自托管！

装好之后，PanelAlpha Engine 就给你和你的 AI 运行真实生产项目所需的一切：

- 从 Git 或文件部署任意技术栈
- 即时预览地址，可选密码保护
- Staging 与 Git 工作流，线上和测试环境分开
- 自动备份与恢复
- 外部监控和内置访客统计
- 项目隔离在各自的 Docker 容器中
- 防火墙和 OWASP/WAF 防护，按项目设置访问规则
- 域名、SSL、定时任务、FTP/SFTP、数据库、日志
- 轻松接入 Cloudflare，用于 DNS、隧道和缓存

## 为什么需要这个

AI 已经把软件创造从旧的限制里解放出来。更多人能把想法变成能跑的产品，小团队能做出比以前多得多的东西，开源也涌现出大量值得拿来用的项目。几乎没跟上同样速度的，是自己跑起来所需的那份工作。大多数自托管工具仍然要求你理解并管理 Docker、Web 服务器、证书、数据库、备份、防火墙，以及之后的更新。

**PanelAlpha Engine** 让在生产环境管理软件，变得和 AI 让构建软件一样容易。

- **一条命令，装一次。** 然后和你本来就在用的 AI 助手说话。
- **用普通话语提要求。** 引擎构建项目、启动它、给它 HTTPS，并持续备份。
- **AI 拿不到 root。** 它通过引擎工作，在你设定的规则内操作。

[**加入 Discord**](https://discord.gg/9twHWR7xGX)。这里是提问、展示你部署了什么、以及和构建它的人交流的主要地方。

---

## 三个简单步骤完成设置

### 第 1 步：在自己的 VPS 上安装 Engine

你需要一台**全新**的服务器，系统为 Debian 12/13 或 Ubuntu 22.04/24.04/26.04，至少 2 GB 内存和 1 核 CPU，并以 `root` 通过 SSH 登录：

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

就这样，服务器已经就绪。自定义名称、自备 TLS 证书，或者服务器在 NAT 后面：[安装选项](docs/02-getting-started/install.md)。

### 第 2 步：连接你的 AI 智能体

安装结束时，引擎会问你用哪个助手，并打印出要在你自己电脑上执行的确切命令。以后想再接一个，在服务器上运行：

```bash
pae connect
```

`pae connect` 在引擎所在的服务器上运行，不要在你的笔记本上运行。它打印出来的那一行，才是你在自己电脑上执行的。

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

用的是别的？任何会说 MCP 的助手都可以：[连接其他助手](docs/04-connecting-your-ai/other-mcp-clients.md)。

默认权限的令牌可以删掉整个项目。你可以只发一个只读令牌，也可以单独收回某些工具：[决定助手能做什么](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)。

### 第 3 步：完成。直接说你想要什么

打开助手的聊天窗口，像对人说话那样说：

```text
把 github.com/anna/invoicer 部署到我的服务器上
```

> 好了。可以在 `invoicer.panelalpha.online` 打开。

每个项目一创建就会拿到一个免费的 `panelalpha.online` 地址。等你准备好了，再要自己的域名，证书会一起配好。

更想自己在服务器上做？[直接从 git 部署](docs/README.md#3-put-your-project-online)。

---

## 你可以直接开口要的事

没有命令需要背，也没有什么咒语。下面这些主要是让你看到，请求说到什么程度比较合适：

| 你说 | 会发生什么 |
|:---|:---|
| `把 github.com/org/app 部署到这个引擎上。` | 创建项目、识别技术栈、构建并启动应用，然后给它一个带 HTTPS 的地址。 |
| `把 shop.example.com 加到这个项目上，并申请证书。` | 域名挂上去，Let's Encrypt 签发一张会自动续期的证书。 |
| `这个站点打不开。读一下部署日志，能修的就修。` | 助手读日志、看站点实际返回了什么、改你的代码，然后重新部署。 |
| `先把它推到 staging。` | 一个有独立地址的关联副本。满意了就推上线，两个方向都能推。 |
| `在这里装个 WordPress，管理员 anna。` | WordPress 装好待用，之后的事情都有 WP-CLI 可用。 |
| `给这个项目建一个 MySQL 数据库，再建个用户。` | 数据库、用户和权限都办好，你不用碰 SQL。 |
| `把昨天的备份恢复回来。` | 备份恢复完成，并且在那之前先把当前状态存了一份，以防万一。 |
| `这个内部工具只让我们办公室访问。` | 一条防火墙规则，把该项目限制到你指定的地址。 |
| `给 n8n.mydomain.com 配一个 Cloudflare 隧道。` | DNS 和隧道都配好，这也是从 NAT 后面的服务器对外提供服务的办法。 |
| `上周我们有多少流量？` | 该项目以及整台服务器的用量、日志和限额。 |

更多现成的例子：[该怎么问](docs/04-connecting-your-ai/your-assistant.md#what-to-ask)。助手能够触达的完整清单：[199 个工具](docs/04-connecting-your-ai/your-assistant.md)。

---

<h2 align="center">一台 VPS。多个项目。什么都能跑。</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
你自己的工具、用 AI 构建的项目，或者从网上找到的开源软件。来源并不重要，PanelAlpha Engine 都能把它跑起来。少数几个应用会被特别对待，因为通用做法会把它们弄坏：WordPress、Matomo、phpBB、Magento 和 Passbolt。见<a href="docs/07-supported-projects/project-types.md">完整列表</a>。
</p>

---

## 别让 AI 把你的服务器弄得一团糟

给 AI 完整的 root 权限，意味着开放的端口、随意的设置，以及一个项目影响另一个项目。PanelAlpha Engine 会定下规则：

- **减少出错空间。** AI 通过引擎工作，而不是直接操作服务器。
- **结构清晰。** 项目、账号和域名始终井然有序。
- **项目隔离。** 每个项目都在自己的 Docker 容器中独立运行，有各自的磁盘、内存和 CPU 限额，只开放所需的端口。

连接之前，由你决定助手可以做什么。[决定助手能做什么](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [安全](docs/05-capabilities/security.md)

---

## 生产环境需要的一切，开箱即用

部署只是开始。持续管理从第一次安装起就已就绪：

- **立即在线查看。** 每个项目都会获得一个免费的 `panelalpha.online` 主机名，自己的域名也可以随时添加。证书来自 Let's Encrypt，会自动续期。[域名与 HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **在 staging 上构建，准备好再上线。** 一个关联副本，尽管折腾，正常后再推上线。也能反向推送，用真实数据刷新测试副本。[项目](docs/05-capabilities/projects.md#staging)
- **内置监控。** 服务器指标、按站点的日志、随时可要的 Lighthouse 报告，以及每六小时一次的检查，确认每个站点仍在提供自身内容，而不是空白页。[监控与日志](docs/05-capabilities/monitoring-and-logs.md)
- **基础能力都已覆盖。** 服务器上和服务器外的备份、SSL、域名、数据库、FTP 与 SFTP 登录、定时任务和日志。[备份](docs/05-capabilities/backups.md) · [数据库](docs/05-capabilities/databases.md) · [文件与访问](docs/05-capabilities/files-and-access.md)
- **每个项目都有自己的安全空间。** Docker 隔离、防火墙，以及采用 OWASP 规则的 ModSecurity。一个应用坏掉，不会拖垮其他应用。[安全](docs/05-capabilities/security.md)
- **一次连接即可使用 Cloudflare。** 在所有项目中使用 DNS、隧道和缓存，也能让 NAT 后面的服务器对外提供站点。[Cloudflare](docs/05-capabilities/cloudflare.md)

---

## 部署失败时，引擎如何从中变好

大多数第一次部署都能成。跑不起来的那些，通常是仓库本身的问题。多数工具丢给你一段调用栈。这里的路径是：

- **你得到的是一句话，不是调用栈。** "这个项目需要 PHP 8.2，但它是用 PHP 8.1 构建的。""构建时内存不够了。"完整的技术输出仍然在下面，谁需要谁看。
- **接下来由你的助手接手。** 它读日志，看站点实际返回了什么，把代码里能修的修掉，然后重新部署。这些工作跑在你本来就付费的 AI 订阅上，而不是 API 令牌上。
- **引擎会从中学习。** 一份匿名报告会送回 PanelAlpha。一台服务器上的一次故障，可能只是你的仓库。同一个故障出现在三十台服务器上，那就是识别逻辑或某个框架配方里的 bug，它会变成下一次更新里的修复。
- **如果错的是引擎本身**，说出来，它会自己把证据收集齐：让助手提交，或运行 `pae telemetry:bug-report <项目>`。

完整流程：[部署失败时](docs/02-getting-started/what-happens.md) · [这个报错是什么意思](docs/02-getting-started/reading-errors.md)。

---

## 遥测

安装之后遥测是开着的。关于部署和之后健康检查的匿名报告会离开服务器，除非你关掉。它们包含站点的公开名称。

- **会发送：** 应用属于哪一类、耗时多久、项目的限额，失败时还包括出错的阶段和脱敏后的日志末尾。健康报告仅在站点后来不再提供自身内容时发送。错误报告仅在你自己提交时才有。
- **永远不会发送：** 你的源代码、项目名、令牌、密码、服务器的 IP 或主机名、私有仓库名，以及关于访问你站点的那些人的任何信息。
- **先看看报告再决定：** `pae telemetry:status` 和 `pae telemetry:show`。

要停止发送：

```bash
pae telemetry:disable
```

收集了什么、没收集什么，以及所有关闭方式：[收集了什么](docs/02-getting-started/what-is-collected.md) · [如何关闭](docs/02-getting-started/how-to-turn-it-off.md)。

---

## FAQ

<details>
<summary><b>我为什么需要 PanelAlpha Engine？</b></summary>

因为代码写完，应用并没有完成。你的项目还需要一个能跑起来的地方，更不用说让它保持健康、可访问、安全所需的一切。PanelAlpha Engine 给你的 AI 助手一条正经的路，去替你处理这一整层。你提出想要的结果，引擎把它变成受控的服务器操作，而不会把机器的无限权限交给 AI。

如果你已经懂 Docker、反向代理、防火墙和服务器日志，这些知识仍然有用。PanelAlpha 并不想对你隐藏基础设施，也不想把你关在外面。它给你一种更干净的操作方式：把重复的部分自动化，让 AI 承担真正的工作，同时不交出控制权。当某件事值得你亲自过问时，你可以下到任意深度；不值得时，就跳过那些例行公事。
</details>

<details>
<summary><b>我需要自己的服务器吗？</b></summary>

需要。PanelAlpha Engine 是让 AI 智能体管理*你的*服务器的工具。你需要一台全新的 Debian 或 Ubuntu VPS，至少 2 GB 内存和 1 核 CPU，并以 `root` 通过 SSH 登录。你自己先在那台 VPS 上运行 Engine 安装。[你需要准备什么](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>哪些 AI 助手能用？</b></summary>

任何能用令牌连接 MCP 服务器的助手。我们为 [Claude Code](docs/04-connecting-your-ai/claude-code.md)、[Cursor](docs/04-connecting-your-ai/cursor.md)、[Codex](docs/04-connecting-your-ai/codex.md)、[Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md)、[VS Code 与 Copilot](docs/04-connecting-your-ai/vs-code-copilot.md)、[Grok](docs/04-connecting-your-ai/grok.md)、[OpenCode](docs/04-connecting-your-ai/opencode.md)、[Windsurf](docs/04-connecting-your-ai/windsurf.md)、[Pi](docs/04-connecting-your-ai/pi.md)、[Hermes](docs/04-connecting-your-ai/hermes.md) 和 [OpenClaw](docs/04-connecting-your-ai/openclaw.md) 都写了分步说明，另外还有[其他任何会说 MCP 的客户端](docs/04-connecting-your-ai/other-mcp-clients.md)。
</details>

<details>
<summary><b>我可以用 PanelAlpha Engine 部署什么？</b></summary>

目标是通用工具，什么项目都行。静态站点、WordPress、PHP、Laravel、Node、Next.js、Django、Go、Rust、Java、一个普通的 `Dockerfile` 或者一个 Compose 文件。你自己的工具、你用 AI 做的项目，以及你在网上找到的开源软件。见[项目类型](docs/07-supported-projects/project-types.md)；想先确认的话，让助手先检查一下仓库。
</details>

<details>
<summary><b>它免费吗？我要付什么钱？</b></summary>

PanelAlpha Engine 是免费开源的，采用 Apache 2.0 许可证。你付的是服务器和 AI 订阅，而这两样你已经有了。排查失败的部署走的是你助手的订阅，而不是 API 令牌。
</details>

<details>
<summary><b>我能限制助手的权限吗？</b></summary>

能，而且值得在粘贴令牌之前就做。你可以给只读令牌，允许修改但不允许删除，只开放部分领域，或者单独收回像 `project_delete` 这样的工具。[决定助手能做什么](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>部署失败时会上报什么？</b></summary>

一份匿名摘要：应用类型、出错的阶段、脱敏后的日志末尾，以及站点的公开名称。绝不包括源代码、项目名、凭据或服务器身份。你可以用 `pae telemetry:show` 打印队列里的报告，也可以把整套遥测关掉。完整细节：[遥测](docs/02-getting-started/what-is-collected.md)。
</details>

<details>
<summary><b>可以用它做虚拟主机（共享主机）吗？</b></summary>

可以。每个项目都是独立账号，有自己的域名、数据库、文件和限额，托管服务商一直用它在生产环境运行数千个站点。
</details>

<details>
<summary><b>引擎把我的项目认错了，怎么办？</b></summary>

提交一份错误报告，它会自己把证据收齐。让助手提交，或者在服务器上运行 `pae telemetry:bug-report <项目>`。想先看清会发送什么，加上 `--dry-run`。
</details>

---

## 在 Discord 上找我们

**Discord 是联系我们的主要渠道。** 提问、把你部署的东西秀给我们看、分享想法，或者过来看看我们在做什么。我们就在那些对话中间，你在那里提出的事，可能变成我们接下来要构建、修复或重新思考的内容。

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="在 Discord 上找我们，这是联系 PanelAlpha 团队的主要渠道" width="760">
</a>
</div><br>

不太喜欢 Discord？[我们的论坛](https://community.panelalpha.com/)同样开放。

## 你的服务器只差一条命令

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## 安全

跑引擎的主机是一台单一用途的机器。安装程序会替换解析器和防火墙，请按这个前提对待它。

发现了漏洞？请**私下**披露，不要发在公开 issue 里。见 [`SECURITY.md`](SECURITY.md)，或使用 [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact)。

## 许可证

PanelAlpha Engine 是开源软件，采用 Apache 2.0 许可证。

## 一起来构建

想参与？[`CONTRIBUTING.md`](CONTRIBUTING.md) 会带你起步。给运维人员看的文档在 [`docs/`](docs/README.md)。
