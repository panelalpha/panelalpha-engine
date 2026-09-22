<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>Tu agente de IA ejecutando<br>tu servidor. Bajo control.</h1>

<h3>
Open source. Self-hosted. Fácil para cualquiera, no solo para sysadmins.
</h3>

<h3>
<a href="#tres-sencillos-pasos-para-configurarlo"><b>Empezar</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>Documentación</b></a> ·
<a href="#habla-con-nosotros-en-discord"><b>Discord</b></a>
</h3>

<p>
<a href="#lo-que-puedes-pedir-sin-más">Control de IA</a> ·
<a href="#todo-lo-que-necesitas-para-producción-incluido-de-serie">Capacidades</a> ·
<a href="#telemetría">Telemetría</a> ·
<a href="#faq">FAQ</a>
</p>

<p>
<a href="README.md">English</a>
· <a href="README.pl.md">Polski</a>
· <a href="README.de.md">Deutsch</a>
· <a href="README.nl.md">Nederlands</a>
· <b>Español</b>
· <a href="README.fr.md">Français</a>
· <a href="README.it.md">Italiano</a>
· <a href="README.pt-BR.md">Português</a>
· <a href="README.uk.md">Українська</a>
· <a href="README.ar.md">العربية</a>
· <a href="README.zh-CN.md">简体中文</a>
</p>

<p>
<a href="#paso-1-instala-engine-en-tu-vps"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="Instalación con un comando"></a>
<a href="#paso-1-instala-engine-en-tu-vps"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="Sistemas soportados"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-182_tools-6f42c1" alt="182 herramientas MCP"></a>
<a href="#licencia"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="Únete a Discord"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="Desplegar un proyecto pidiéndoselo al asistente" width="760"></p>

</div>

---

## Qué es PanelAlpha Engine

PanelAlpha Engine es software que instalas en tu VPS para alojar proyectos creados con IA y vibe-coded, sitios web y apps open source que encontraste por ahí. Una vez instalado, conectas tu propio agente de IA directamente a PanelAlpha Engine y dejas que se encargue de los despliegues, el mantenimiento y la gestión del servidor. Tu servidor se mantiene organizado y bajo control.

**Pero lo más importante: gestionar tu propio servidor/VPS se vuelve realmente sencillo**. ¡Ya no hace falta ser sysadmin para hacer self-hosting!

Desde el primer momento, PanelAlpha Engine te da a ti y a tu IA todo lo que necesitáis para llevar proyectos reales a producción:

- Despliega cualquier stack desde Git o desde archivos
- URLs de vista previa al instante, con protección por contraseña opcional
- Flujos de staging y Git, con entornos live y staging separados
- Copias de seguridad automáticas y restauración
- Monitorización externa y estadísticas de visitas integradas
- Aislamiento de proyectos en contenedores Docker separados
- Cortafuegos y protección OWASP/WAF con reglas de acceso por proyecto
- Dominios, SSL, cron, FTP/SFTP, bases de datos, logs
- Integración sencilla con Cloudflare para DNS, túneles y caché

## Por qué esto tiene que existir

La IA ha sacado la creación de software de sus límites de siempre. Más gente puede convertir una idea en un producto que funciona, los equipos pequeños construyen mucho más que antes, y el open source está lleno de proyectos que merecen ser tuyos. Lo que casi no ha cambiado al mismo ritmo es el trabajo necesario para ejecutarlo tú mismo. La mayoría de las herramientas self-hosted todavía esperan que entiendas y gestiones Docker, un servidor web, certificados, bases de datos, copias de seguridad, un cortafuegos y las actualizaciones que vienen después.

**PanelAlpha Engine** hace que gestionar software en producción sea tan fácil como la IA hizo construirlo.

- **Un comando, una vez.** Después hablas con el asistente de IA que ya usas.
- **Pides con palabras normales.** El motor construye el proyecto, lo arranca, le da HTTPS y lo mantiene respaldado.
- **La IA nunca obtiene root.** Trabaja a través del motor, dentro de las reglas que tú defines.

[**Únete a Discord**](https://discord.gg/9twHWR7xGX). Es el lugar principal para preguntar, mostrar lo que has desplegado y hablar con la gente que construye esto.

---

## Tres sencillos pasos para configurarlo

### Paso 1: Instala Engine en tu VPS

Necesitas un servidor **nuevo** con Debian 12/13 o Ubuntu 22.04/24.04/26.04, con al menos 2 GB de RAM y 1 CPU, y entras como `root` por SSH:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

Ya está. Tu servidor está listo. Nombre propio, tu propio certificado TLS o un servidor detrás de NAT: [opciones de instalación](docs/02-getting-started/install.md).

### Paso 2: Conecta tu agente de IA

Al final de la instalación, el motor te pregunta qué asistente usas e imprime el comando exacto para ejecutar en tu ordenador. Para conectar otro más tarde, ejecuta esto en el servidor:

```bash
pae connect
```

`pae connect` se ejecuta en el servidor del motor, no en tu portátil. La línea que imprime es la que ejecutas en tu ordenador.

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

¿Usas otra cosa? Funciona cualquier asistente que hable MCP: [conectar otros asistentes](docs/04-connecting-your-ai/other-mcp-clients.md).

Un token con permisos por defecto puede borrar un proyecto entero. Puedes dar uno de solo lectura o quitar herramientas concretas: [decide qué puede hacer el asistente](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### Paso 3: Listo. Pide lo que quieras

Abre el chat de tu asistente y dilo como se lo dirías a una persona:

```text
despliega github.com/anna/invoicer en mi servidor
```

> Hecho. Disponible en `invoicer.panelalpha.online`

Cada proyecto recibe una dirección `panelalpha.online` gratuita en cuanto existe. Cuando quieras, pides tu propio dominio y el certificado viene con él.

¿Prefieres hacerlo desde el servidor? [Desplegar directamente desde git](docs/README.md#3-put-your-project-online).

---

## Lo que puedes pedir sin más

No hay comandos que aprender, ni frases mágicas. Esto solo muestra el nivel de detalle que conviene dar:

| Tú dices | Qué pasa |
|:---|:---|
| `Despliega github.com/org/app en este motor.` | Se crea un proyecto, se detecta el stack, la app se construye, arranca y recibe una dirección con HTTPS. |
| `Añade shop.example.com a este proyecto y pide un certificado.` | El dominio queda enganchado y Let's Encrypt emite un certificado que se renueva solo. |
| `Este sitio no abre. Lee el log del despliegue y arregla lo que puedas.` | Tu asistente lee el log, mira qué sirve realmente el sitio, cambia tu código y vuelve a desplegar. |
| `Pásalo primero a staging.` | Una copia enlazada con su propia dirección. La pasas a producción cuando te convenza, en ambos sentidos. |
| `Instala WordPress aquí, admin anna.` | WordPress instalado y listo, con WP-CLI disponible para todo lo demás. |
| `Crea una base de datos MySQL para este proyecto y un usuario.` | Base de datos, usuario y permisos, sin que toques SQL. |
| `Restaura la copia de ayer.` | La copia se restaura, y el estado actual se guarda antes, por si acaso. |
| `Deja que solo nuestra oficina llegue a esta herramienta interna.` | Una regla de cortafuegos que limita ese proyecto a las direcciones que digas. |
| `Monta un túnel de Cloudflare para n8n.mydomain.com.` | DNS y túnel configurados, que además es como sirves desde un servidor detrás de NAT. |
| `¿Cuánto tráfico recibimos la semana pasada?` | Uso, logs y límites de ese proyecto, y del servidor entero. |

Más ejemplos trabajados: [qué pedir](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). La lista completa de lo que tu asistente puede alcanzar: [182 herramientas](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">Un VPS. Varios proyectos. Funciona con cualquier cosa.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
Tus propias herramientas, proyectos creados con IA o software open source que encontraste por ahí. Da igual: PanelAlpha Engine simplemente lo ejecuta. Unas pocas aplicaciones reciben trato especial porque el enfoque general las trataría mal: WordPress, Matomo, phpBB, Magento y Passbolt. Mira la <a href="docs/07-supported-projects/project-types.md">lista completa</a>.
</p>

---

## No dejes que la IA convierta tu servidor en un caos

Dar acceso root completo a la IA significa puertos abiertos, ajustes al azar y un proyecto afectando a otro. PanelAlpha Engine pone las reglas:

- **Menos margen para errores.** La IA trabaja a través del motor, no directamente en el servidor.
- **Una estructura clara.** Los proyectos, las cuentas y los dominios se mantienen organizados.
- **Aislamiento de proyectos.** Cada uno se ejecuta en su propio contenedor Docker, con sus propios límites de disco, memoria y CPU, y solo los puertos que necesita.

Tú decides qué puede hacer el asistente antes de conectarlo. [Decide qué puede hacer el asistente](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [Seguridad](docs/05-capabilities/security.md)

---

## Todo lo que necesitas para producción, incluido de serie

El despliegue es solo el principio. La gestión continua está ahí desde la primera instalación:

- **Míralo online de inmediato.** Un nombre `panelalpha.online` gratuito en cada proyecto, más tus propios dominios cuando quieras. Los certificados vienen de Let's Encrypt y se renuevan solos. [Dominios y HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **Construye en staging. Pasa a producción cuando esté listo.** Una copia enlazada para romperla a gusto y pasarla a producción cuando funcione. También puedes hacerlo al revés para refrescar la copia de pruebas con datos reales. [Proyectos](docs/05-capabilities/projects.md#staging)
- **Monitorización integrada.** Métricas del servidor, logs por sitio, informes de Lighthouse cuando los pidas y una comprobación cada seis horas de que cada sitio sigue sirviéndose a sí mismo y no una página en blanco. [Monitorización y logs](docs/05-capabilities/monitoring-and-logs.md)
- **Lo esencial, cubierto.** Copias de seguridad en el servidor y fuera de él, SSL, dominios, bases de datos, cuentas FTP y SFTP, tareas cron y logs. [Copias de seguridad](docs/05-capabilities/backups.md) · [Bases de datos](docs/05-capabilities/databases.md) · [Archivos y accesos](docs/05-capabilities/files-and-access.md)
- **Un espacio seguro por proyecto.** Aislamiento con Docker, un cortafuegos y ModSecurity con las reglas OWASP. Una app rota no puede tumbar las demás. [Seguridad](docs/05-capabilities/security.md)
- **Cloudflare en una conexión.** DNS, túneles y caché en tus proyectos, también para servir un sitio desde un servidor detrás de NAT. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## Cuando un despliegue falla, y cómo el motor aprende de ello

La mayoría de los primeros despliegues funcionan. Los que no, suelen tener un problema en el repositorio. La mayoría de herramientas te deja con una traza. Aquí el camino es este:

- **Recibes una frase, no una traza.** "Este proyecto necesita PHP 8.2, pero se construyó con PHP 8.1." "La construcción se quedó sin memoria." La salida completa sigue estando debajo, por si alguien la quiere.
- **A partir de ahí toma el relevo tu asistente.** Lee el log, mira qué sirve realmente el sitio, arregla en tu código lo que se pueda arreglar y vuelve a desplegar. Ese trabajo corre sobre la suscripción de IA que ya pagas, no sobre tokens de API.
- **El motor aprende de ello.** Un informe anónimo llega a PanelAlpha. Un fallo en tu servidor puede ser cosa de tu repositorio. El mismo fallo en treinta servidores es un error en la detección o en una receta de framework, y eso se convierte en un arreglo en la siguiente actualización.
- **Cuando el que se equivocó fue el motor**, dilo y él reúne las pruebas: pídeselo a tu asistente, o ejecuta `pae telemetry:bug-report <proyecto>`.

El procedimiento completo: [cuando un despliegue falla](docs/02-getting-started/what-happens.md) · [qué significa el error](docs/02-getting-started/reading-errors.md).

---

## Telemetría

La telemetría está activada tras la instalación. Informes anónimos sobre despliegues y comprobaciones de salud posteriores salen del servidor salvo que la apagues. Incluyen los nombres públicos de tus sitios.

- **Enviado:** qué tipo de aplicación era, cuánto tardó, los límites del proyecto y, si falló, la etapa que se rompió más el final del log con los secretos quitados. Un informe de salud solo cuando un sitio deja más tarde de servirse a sí mismo. Un informe de error solo cuando lo envías tú.
- **Nunca enviado:** tu código fuente, nombres de proyectos, tokens, contraseñas, la IP o el nombre de tu servidor, nombres de repositorios privados, ni nada sobre quienes visitan tus sitios.
- **Mira un informe antes de decidir:** `pae telemetry:status` y `pae telemetry:show`.

Para dejar de enviar:

```bash
pae telemetry:disable
```

Qué se recoge, qué no, y todas las formas de apagarlo: [qué se recoge](docs/02-getting-started/what-is-collected.md) · [cómo apagarlo](docs/02-getting-started/how-to-turn-it-off.md).

---

## FAQ

<details>
<summary><b>¿Por qué necesito PanelAlpha Engine?</b></summary>

Porque una aplicación no está terminada cuando el código está terminado. Tu proyecto sigue necesitando un sitio donde ejecutarse, por no hablar de todo lo necesario para mantenerlo sano, alcanzable y seguro. PanelAlpha Engine da a tu asistente de IA una forma de verdad de encargarse de toda esa capa por ti. Pides el resultado que quieres, y el motor lo convierte en operaciones controladas en el servidor, sin dar a la IA acceso sin restricciones a la máquina.

Si ya conoces Docker, los proxies inversos, los cortafuegos y los logs del servidor, ese conocimiento sigue importando. PanelAlpha no intenta esconderte la infraestructura ni dejarte fuera. Te da una forma más limpia de operarla, automatizar lo repetitivo y dejar que la IA asuma trabajo real sin ceder el control. Puedes bajar tan profundo como quieras cuando algo merezca tu atención, y saltarte la rutina cuando no.
</details>

<details>
<summary><b>¿Necesito mi propio servidor?</b></summary>

Sí. PanelAlpha Engine es una herramienta para que los agentes de IA gestionen *tu* servidor. Necesitas un VPS nuevo con Debian o Ubuntu, con al menos 2 GB de RAM y 1 CPU, al que entres como `root` por SSH. Empiezas ejecutando tú mismo la instalación de Engine en ese VPS. [Qué necesitas](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>¿Qué agentes de IA funcionan con PanelAlpha Engine?</b></summary>

Cualquiera que pueda conectarse a un servidor MCP con un token. Hay páginas paso a paso para [Claude Code](docs/04-connecting-your-ai/claude-code.md), [Cursor](docs/04-connecting-your-ai/cursor.md), [Codex](docs/04-connecting-your-ai/codex.md), [Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md), [VS Code y Copilot](docs/04-connecting-your-ai/vs-code-copilot.md), [Grok](docs/04-connecting-your-ai/grok.md), [OpenCode](docs/04-connecting-your-ai/opencode.md), [Windsurf](docs/04-connecting-your-ai/windsurf.md), [Pi](docs/04-connecting-your-ai/pi.md), [Hermes](docs/04-connecting-your-ai/hermes.md) y [OpenClaw](docs/04-connecting-your-ai/openclaw.md), además de [cualquier otro que hable MCP](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>¿Qué proyectos puedo desplegar con PanelAlpha Engine?</b></summary>

El objetivo es una herramienta universal para cualquier proyecto. Sitios estáticos, WordPress, PHP, Laravel, Node, Next.js, Django, Go, Rust, Java, un `Dockerfile` normal o un archivo de Compose. Tus propias herramientas, los que construiste con IA y software open source que encontraste por ahí. Mira los [tipos de proyecto](docs/07-supported-projects/project-types.md) y, si quieres comprobarlo antes, pide a tu asistente que inspeccione el repositorio primero.
</details>

<details>
<summary><b>¿Es gratis? ¿Qué pago?</b></summary>

PanelAlpha Engine es gratuito y open source bajo licencia Apache 2.0. Pagas el servidor y la suscripción de IA, y las dos ya las tienes. Depurar un despliegue fallido corre a cuenta de la suscripción de tu asistente, no de tokens de API.
</details>

<details>
<summary><b>¿Puedo limitar lo que hace mi asistente?</b></summary>

Sí, y vale la pena antes de pegar un token. Puedes darle un token de solo lectura, permitir cambios pero no borrados, exponer solo algunas áreas o quitarle herramientas concretas como `project_delete`. [Decide qué puede hacer el asistente](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>¿Qué se informa si un despliegue falla?</b></summary>

Un resumen anónimo: el tipo de aplicación, la etapa que se rompió, el final depurado del log y los nombres públicos de los sitios. Nunca tu código fuente, los nombres de tus proyectos, tus credenciales ni la identidad del servidor. Puedes imprimir un informe en cola con `pae telemetry:show` y apagarlo todo. El detalle: [Telemetría](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>¿Puedo usar PanelAlpha Engine para hosting compartido?</b></summary>

Sí. Cada proyecto es una cuenta aparte con sus dominios, bases de datos, archivos y límites. Hay proveedores de hosting que ejecutan miles de sitios con él en producción.
</details>

<details>
<summary><b>El motor entendió mal mi proyecto. ¿Y ahora qué?</b></summary>

Envía un informe de error y él reúne las pruebas solo. Pídeselo a tu asistente, o ejecuta en el servidor `pae telemetry:bug-report <proyecto>`. Usa `--dry-run` primero si quieres ver exactamente qué se enviaría.
</details>

---

## Habla con nosotros en Discord

**Discord es el lugar principal para contactarnos.** Haz una pregunta, muéstranos lo que has desplegado, comparte una idea, o pásate y mira en qué estamos trabajando. Estamos en medio de esas conversaciones, y lo que plantees allí puede convertirse en lo siguiente que construyamos, arreglemos o replanteemos.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="Habla con nosotros en Discord, el lugar principal para contactar con el equipo de PanelAlpha" width="760">
</a>
</div><br>

¿No te va Discord? [Nuestro foro](https://community.panelalpha.com/) está igual de abierto.

## Tu servidor está a un comando

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## Seguridad

Un host del motor es una máquina de un solo propósito. El instalador sustituye el resolver y el cortafuegos, así que trátalo como tal.

¿Has encontrado una vulnerabilidad? Repórtala **en privado**, nunca en una incidencia pública. Mira [`SECURITY.md`](SECURITY.md), o usa [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## Licencia

PanelAlpha Engine es open source bajo la licencia Apache 2.0.

## Ven a construir con nosotros

¿Quieres participar? [`CONTRIBUTING.md`](CONTRIBUTING.md) te pone en marcha. La documentación para quien opera el servidor está en [`docs/`](docs/README.md).
