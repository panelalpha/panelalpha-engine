<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>Seu agente de IA rodando<br>o seu servidor. Sob controle.</h1>

<h3>
Open source. Self-hosted. Fácil para qualquer um, não só para sysadmins.
</h3>

<h3>
<a href="#três-passos-simples-para-configurar"><b>Começar</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>Documentação</b></a> ·
<a href="#fale-conosco-no-discord"><b>Discord</b></a>
</h3>

<p>
<a href="#o-que-dá-para-simplesmente-pedir">Controle de IA</a> ·
<a href="#tudo-o-que-você-precisa-para-produção-pronto-para-usar">Recursos</a> ·
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
· <a href="README.it.md">Italiano</a>
· <b>Português</b>
· <a href="README.uk.md">Українська</a>
· <a href="README.ar.md">العربية</a>
· <a href="README.zh-CN.md">简体中文</a>
</p>

<p>
<a href="#passo-1-instale-no-seu-vps"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="Instalação com um comando"></a>
<a href="#passo-1-instale-no-seu-vps"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="Sistemas suportados"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-199_tools-6f42c1" alt="199 ferramentas MCP"></a>
<a href="#licença"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="Entre no Discord"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="Publicando um projeto pedindo ao assistente" width="760"></p>

</div>

---

## O que é o PanelAlpha Engine?

O PanelAlpha Engine é um software que você instala no seu VPS para hospedar projetos feitos com IA e vibe-coded, sites e apps open source que você achou online. Depois de instalado, você conecta o seu próprio agente de IA direto no PanelAlpha Engine e deixa ele cuidar de deploys, manutenção e gestão do servidor. Seu servidor fica organizado e sob controle.

**Mas o mais importante: gerenciar o seu próprio servidor/VPS fica realmente simples**. Você não precisa mais ser sysadmin para fazer self-hosting!

Já na instalação, o PanelAlpha Engine dá a você e à sua IA tudo o que precisam para rodar projetos de verdade em produção:

- Publique qualquer stack a partir do Git ou de arquivos
- URLs de preview na hora, com proteção por senha opcional
- Fluxos de staging e Git, com ambientes live e staging separados
- Backups automáticos e restauração
- Monitoramento externo e estatísticas de visitantes embutidas
- Isolamento de projetos em contêineres Docker separados
- Firewall e proteção OWASP/WAF com regras de acesso por projeto
- Domínios, SSL, cron, FTP/SFTP, bancos de dados, logs
- Integração fácil com Cloudflare para DNS, túneis e cache

## Por que isso precisa existir

A IA tirou a criação de software dos limites de antes. Mais gente consegue transformar uma ideia em um produto que funciona, times pequenos constroem bem mais do que antes, e o open source está cheio de projetos que valem a pena tornar seus. O que quase não acompanhou o mesmo ritmo é o trabalho necessário para rodar isso sozinho. A maioria das ferramentas self-hosted ainda espera que você entenda e gerencie Docker, servidor web, certificados, bancos de dados, backups, firewall e as atualizações que vêm depois.

**PanelAlpha Engine** deixa gerenciar software em produção tão fácil quanto a IA deixou construir.

- **Um comando, uma vez.** Depois você fala com o assistente de IA que já usa.
- **Você pede com palavras comuns.** O motor constrói o projeto, sobe, dá HTTPS e mantém o backup.
- **A IA nunca recebe root.** Trabalha através do motor, dentro das regras que você define.

[**Entre no Discord**](https://discord.gg/9twHWR7xGX). É o principal lugar para perguntar, mostrar o que você publicou e falar com quem constrói isto.

---

## Três passos simples para configurar

### Passo 1: Instale no seu VPS

Você precisa de um servidor **novo** com Debian 12/13 ou Ubuntu 22.04/24.04/26.04, com pelo menos 2 GB de RAM e 1 CPU, e entra como `root` por SSH:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

É isso. Seu servidor está pronto. Um nome próprio, seu próprio certificado TLS ou um servidor atrás de NAT: [opções de instalação](docs/02-getting-started/install.md).

### Passo 2: Conecte seu agente de IA

No fim da instalação o motor pergunta qual assistente você usa e imprime o comando exato para rodar no seu computador. Para conectar outro depois, rode no servidor:

```bash
pae connect
```

O `pae connect` roda no servidor do motor, não no seu notebook. A linha que ele imprime é a que você roda no seu computador.

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

Usa outra coisa? Qualquer assistente que fale MCP funciona: [conectar outros assistentes](docs/04-connecting-your-ai/other-mcp-clients.md).

Um token com as permissões padrão consegue apagar um projeto inteiro. Dá para emitir um só de leitura, ou tirar ferramentas específicas: [decida o que o assistente pode fazer](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### Passo 3: Pronto. Peça o que você quer

Abra o chat do seu assistente e fale como falaria com uma pessoa:

```text
publica github.com/anna/invoicer no meu servidor
```

> Pronto. Disponível em `invoicer.panelalpha.online`

Todo projeto ganha um endereço `panelalpha.online` gratuito assim que nasce. Quando quiser, peça seu próprio domínio, e o certificado vem junto.

Prefere fazer pelo servidor? [Publicar direto do git](docs/README.md#3-put-your-project-online).

---

## O que dá para simplesmente pedir

Não há comandos para decorar, nem frases mágicas. Isto mostra o nível de detalhe que vale a pena dar:

| Você diz | O que acontece |
|:---|:---|
| `Publica github.com/org/app neste motor.` | Um projeto é criado, a stack é detectada, o app é construído, sobe e ganha um endereço com HTTPS. |
| `Adiciona shop.example.com neste projeto e pega um certificado.` | O domínio é ligado e o Let's Encrypt emite um certificado que se renova sozinho. |
| `Este site não abre. Lê o log do deploy e conserta o que der.` | Seu assistente lê o log, vê o que o site realmente entrega, muda seu código e publica de novo. |
| `Manda primeiro para staging.` | Uma cópia ligada com endereço próprio. Você manda para produção quando gostar, nos dois sentidos. |
| `Instala WordPress aqui, admin anna.` | WordPress instalado e pronto, com WP-CLI para tudo o que vier depois. |
| `Cria um banco MySQL para este projeto e um usuário.` | Banco, usuário e permissões, sem você tocar em SQL. |
| `Restaura o backup de ontem.` | O backup é restaurado, e o estado atual é guardado antes, por garantia. |
| `Deixa só o nosso escritório acessar esta ferramenta interna.` | Uma regra de firewall limitando aquele projeto aos endereços que você indicar. |
| `Configura um túnel Cloudflare para n8n.mydomain.com.` | DNS e túnel prontos, que é também como você serve de um servidor atrás de NAT. |
| `Quanto tráfego tivemos na semana passada?` | Uso, logs e limites daquele projeto, e do servidor inteiro. |

Mais exemplos prontos: [o que pedir](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). A lista completa do que seu assistente alcança: [199 ferramentas](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">Um VPS. Vários projetos. Funciona com qualquer coisa.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
Suas próprias ferramentas, projetos feitos com IA ou software open source que você achou online. Não importa: o PanelAlpha Engine simplesmente roda. Algumas aplicações recebem cuidado extra porque o caminho geral quebraria elas: WordPress, Matomo, phpBB, Magento e Passbolt. Veja a <a href="docs/07-supported-projects/project-types.md">lista completa</a>.
</p>

---

## Não deixe a IA transformar seu servidor numa bagunça

Dar acesso root completo à IA significa portas abertas, configurações aleatórias e um projeto afetando outro. O PanelAlpha Engine define as regras:

- **Menos espaço para erros.** A IA trabalha através do motor, não diretamente no servidor.
- **Uma estrutura clara.** Projetos, contas e domínios ficam organizados.
- **Isolamento dos projetos.** Cada um roda no próprio contêiner Docker, com seus limites de disco, memória e CPU, e apenas as portas necessárias abertas.

Você decide o que o assistente pode fazer antes de conectá-lo. [Decida o que o assistente pode fazer](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [Segurança](docs/05-capabilities/security.md)

---

## Tudo o que você precisa para produção, pronto para usar

O deploy é só o começo. A gestão contínua está lá desde a primeira instalação:

- **Veja online na hora.** Um hostname `panelalpha.online` gratuito em todo projeto, mais os seus próprios domínios quando quiser. Os certificados vêm do Let's Encrypt e se renovam sozinhos. [Domínios e HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **Construa em staging. Publique quando estiver pronto.** Uma cópia ligada para quebrar à vontade e mandar para produção quando funcionar. Também dá para fazer o caminho inverso e atualizar a cópia de teste com dados reais. [Projetos](docs/05-capabilities/projects.md#staging)
- **Monitoramento integrado.** Métricas do servidor, logs por site, relatórios Lighthouse quando você pedir e, a cada seis horas, uma checagem de que cada site ainda entrega a si mesmo, não uma página em branco. [Monitoramento e logs](docs/05-capabilities/monitoring-and-logs.md)
- **O essencial está coberto.** Backups no servidor e fora dele, SSL, domínios, bancos de dados, acessos FTP e SFTP, cron e logs. [Backups](docs/05-capabilities/backups.md) · [Bancos de dados](docs/05-capabilities/databases.md) · [Arquivos e acessos](docs/05-capabilities/files-and-access.md)
- **Cada projeto ganha seu próprio espaço seguro.** Isolamento Docker, firewall e ModSecurity com as regras da OWASP. Um app quebrado não derruba os outros. [Segurança](docs/05-capabilities/security.md)
- **Cloudflare numa só conexão.** DNS, túneis e cache em todos os seus projetos, inclusive para servir um site de um servidor atrás de NAT. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## Quando um deploy falha, e como o motor aprende com isso

A maioria dos primeiros deploys dá certo. Os que não dão costumam ser problema do repositório. A maioria das ferramentas te deixa com um stack trace. Aqui o caminho é:

- **Você recebe uma frase, não um stack trace.** "Este projeto precisa de PHP 8.2, mas foi construído com PHP 8.1." "A build ficou sem memória." A saída técnica completa continua logo abaixo, se alguém quiser.
- **Daí em diante quem assume é seu assistente.** Ele lê o log, olha o que o site realmente entrega, conserta no seu código o que dá para consertar e publica de novo. Esse trabalho roda na assinatura de IA que você já paga, não em tokens de API.
- **O motor aprende com isso.** Um relatório anônimo vai para a PanelAlpha. Uma falha no seu servidor pode ser o seu repositório. A mesma falha em trinta servidores é um bug na detecção ou na receita de um framework, e isso vira correção na próxima atualização.
- **E quando quem errou foi o motor**, diga isso e ele junta as evidências: peça ao seu assistente, ou rode `pae telemetry:bug-report <projeto>`.

O procedimento inteiro: [quando um deploy falha](docs/02-getting-started/what-happens.md) · [o que o erro quer dizer](docs/02-getting-started/reading-errors.md).

---

## Telemetria

A telemetria fica ligada depois da instalação. Relatórios anônimos sobre deploys e checagens de saúde posteriores saem do servidor enquanto você não desligar. Eles incluem os nomes públicos dos seus sites.

- **Enviado:** que tipo de aplicação era, quanto tempo levou, os limites do projeto e, em caso de falha, a etapa que quebrou mais o fim do log com os segredos removidos. Um relatório de saúde só quando um site depois para de entregar a si mesmo. Um relatório de bug só quando você mesmo envia.
- **Nunca enviado:** seu código-fonte, nomes dos projetos, tokens, senhas, o IP ou o hostname do seu servidor, nomes de repositórios privados, ou qualquer coisa sobre quem visita seus sites.
- **Veja um relatório antes de decidir:** `pae telemetry:status` e `pae telemetry:show`.

Para parar de enviar:

```bash
pae telemetry:disable
```

O que é coletado, o que não é, e todas as formas de desligar: [o que é coletado](docs/02-getting-started/what-is-collected.md) · [como desligar](docs/02-getting-started/how-to-turn-it-off.md).

---

## FAQ

<details>
<summary><b>Por que eu preciso mesmo do PanelAlpha Engine?</b></summary>

Porque uma aplicação não está pronta quando o código está pronto. Seu projeto ainda precisa de um lugar para rodar, sem falar de tudo o que é preciso para mantê-lo saudável, acessível e seguro. O PanelAlpha Engine dá ao seu assistente de IA um jeito de verdade de cuidar dessa camada inteira por você. Você pede o resultado que quer, e o motor transforma isso em operações controladas no servidor, sem dar à IA acesso irrestrito à máquina.

Se você já conhece Docker, proxies reversos, firewalls e logs de servidor, esse conhecimento continua importando. A PanelAlpha não tenta esconder a infraestrutura de você nem te trancar fora dela. Ela te dá um jeito mais limpo de operar, automatizar as partes repetitivas e deixar a IA assumir trabalho de verdade sem entregar o controle. Você pode ir tão fundo quanto quiser quando algo merece sua atenção, e pular a rotina quando não merece.
</details>

<details>
<summary><b>Preciso do meu próprio servidor?</b></summary>

Sim. O PanelAlpha Engine é uma ferramenta para agentes de IA gerenciarem *o seu* servidor. Você precisa de um VPS novo com Debian ou Ubuntu, pelo menos 2 GB de RAM e 1 CPU, no qual entra como `root` por SSH. Você começa rodando você mesmo a instalação do Engine nesse VPS. [O que você precisa](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>Quais assistentes de IA funcionam com ele?</b></summary>

Qualquer um que consiga se conectar a um servidor MCP com um token. Há páginas passo a passo para [Claude Code](docs/04-connecting-your-ai/claude-code.md), [Cursor](docs/04-connecting-your-ai/cursor.md), [Codex](docs/04-connecting-your-ai/codex.md), [Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md), [VS Code e Copilot](docs/04-connecting-your-ai/vs-code-copilot.md), [Grok](docs/04-connecting-your-ai/grok.md), [OpenCode](docs/04-connecting-your-ai/opencode.md), [Windsurf](docs/04-connecting-your-ai/windsurf.md), [Pi](docs/04-connecting-your-ai/pi.md), [Hermes](docs/04-connecting-your-ai/hermes.md) e [OpenClaw](docs/04-connecting-your-ai/openclaw.md), além de [qualquer outro que fale MCP](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>O que eu posso publicar?</b></summary>

O objetivo é uma ferramenta universal para qualquer projeto. Sites estáticos, WordPress, PHP, Laravel, Node, Next.js, Django, Go, Rust, Java, um `Dockerfile` simples ou um arquivo Compose. Suas próprias ferramentas, os projetos que você fez com IA, e software open source que achou por aí. Veja os [tipos de projeto](docs/07-supported-projects/project-types.md) e, se quiser conferir antes, peça ao seu assistente para inspecionar o repositório primeiro.
</details>

<details>
<summary><b>É grátis? Pelo que eu pago?</b></summary>

O PanelAlpha Engine é gratuito e open source sob a licença Apache 2.0. Você paga o servidor e a assinatura de IA, e já tem os dois. Depurar um deploy que falhou roda na assinatura do seu assistente, não em tokens de API.
</details>

<details>
<summary><b>Posso limitar o que meu assistente faz?</b></summary>

Pode, e vale a pena antes de colar um token. Dá para dar um token só de leitura, permitir mudanças mas não exclusões, expor apenas algumas áreas, ou tirar ferramentas específicas como `project_delete`. [Decida o que o assistente pode fazer](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>O que é reportado se um deploy falha?</b></summary>

Um resumo anônimo: o tipo de aplicação, a etapa que quebrou, o fim do log já limpo e os nomes públicos dos sites. Nunca o código-fonte, os nomes dos projetos, as credenciais ou a identidade do servidor. Você mesmo imprime um relatório na fila com `pae telemetry:show` e pode desligar tudo. Todos os detalhes: [Telemetria](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>Dá para usar em hospedagem compartilhada?</b></summary>

Dá. Cada projeto é uma conta separada com seus domínios, bancos, arquivos e limites. Provedores de hospedagem rodam milhares de sites em produção neste motor.
</details>

<details>
<summary><b>O motor entendeu meu projeto errado. E agora?</b></summary>

Envie um relatório de bug e ele junta as evidências sozinho. Peça ao seu assistente, ou rode no servidor `pae telemetry:bug-report <projeto>`. Use `--dry-run` antes se quiser ver exatamente o que seria enviado.
</details>

---

## Fale conosco no Discord

**O Discord é o principal lugar para falar conosco.** Faça uma pergunta, mostre o que você publicou, compartilhe uma ideia, ou apareça e veja no que estamos trabalhando. Estamos no meio dessas conversas, e o que você levantar ali pode virar a próxima coisa que a gente constrói, conserta ou repensa.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="Fale conosco no Discord, o principal lugar para falar com a equipe da PanelAlpha" width="760">
</a>
</div><br>

Não curte Discord? [Nosso fórum](https://community.panelalpha.com/) é igualmente aberto.

## Seu servidor está a um comando de distância

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## Segurança

Uma máquina que hospeda o motor serve só para isso. O instalador troca o resolvedor e o firewall, então trate ela assim.

Encontrou uma vulnerabilidade? Reporte **em particular**, nunca numa issue pública. Veja [`SECURITY.md`](SECURITY.md), ou use [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## Licença

O PanelAlpha Engine é open source sob a licença Apache 2.0.

## Venha construir com a gente

Quer participar? [`CONTRIBUTING.md`](CONTRIBUTING.md) te coloca no caminho. A documentação para quem opera o servidor está em [`docs/`](docs/README.md).
