<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>Votre agent IA fait tourner<br>votre serveur. Sous contrôle.</h1>

<h3>
Open source. Self-hosted. Simple pour tout le monde, pas seulement pour les sysadmins.
</h3>

<h3>
<a href="#trois-étapes-simples-pour-linstaller"><b>Démarrer</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>Documentation</b></a> ·
<a href="#parlez-nous-sur-discord"><b>Discord</b></a>
</h3>

<p>
<a href="#ce-que-vous-pouvez-simplement-demander">Contrôle IA</a> ·
<a href="#tout-ce-quil-vous-faut-pour-la-production-prêt-à-lemploi">Capacités</a> ·
<a href="#télémétrie">Télémétrie</a> ·
<a href="#faq">FAQ</a>
</p>

<p>
<a href="README.md">English</a>
· <a href="README.pl.md">Polski</a>
· <a href="README.de.md">Deutsch</a>
· <a href="README.nl.md">Nederlands</a>
· <a href="README.es.md">Español</a>
· <b>Français</b>
· <a href="README.it.md">Italiano</a>
· <a href="README.pt-BR.md">Português</a>
· <a href="README.uk.md">Українська</a>
· <a href="README.ar.md">العربية</a>
· <a href="README.zh-CN.md">简体中文</a>
</p>

<p>
<a href="#étape-1--installez-engine-sur-votre-vps"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="Installation en une commande"></a>
<a href="#étape-1--installez-engine-sur-votre-vps"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="Systèmes pris en charge"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-182_tools-6f42c1" alt="182 outils MCP"></a>
<a href="#licence"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="Rejoindre Discord"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="Déployer un projet en le demandant à son assistant" width="760"></p>

</div>

---

## Qu'est-ce que PanelAlpha Engine ?

PanelAlpha Engine est un logiciel que vous installez sur votre VPS pour héberger des projets construits avec l'IA et vibe-coded, des sites et des apps open source trouvés en ligne. Une fois installé, vous connectez votre propre agent IA directement à PanelAlpha Engine et le laissez gérer les déploiements, la maintenance et l'administration du serveur. Votre serveur reste organisé et sous contrôle.

**Mais surtout, gérer votre propre serveur/VPS devient vraiment simple**. Vous n'avez plus besoin d'être sysadmin pour faire du self-hosting !

Dès l'installation, PanelAlpha Engine vous donne, à vous et à votre IA, tout ce qu'il faut pour faire tourner de vrais projets en production :

- Déployez n'importe quelle stack depuis Git ou des fichiers
- Des URL de prévisualisation immédiates, avec protection par mot de passe en option
- Workflows staging et Git, avec des environnements live et staging séparés
- Sauvegardes automatiques et restauration
- Supervision externe et statistiques de visite intégrées
- Isolation des projets dans des conteneurs Docker séparés
- Pare-feu et protection OWASP/WAF avec des règles d'accès par projet
- Domaines, SSL, cron, FTP/SFTP, bases de données, logs
- Intégration Cloudflare simple pour le DNS, les tunnels et le cache

## Pourquoi ça doit exister

L'IA a fait sortir la création logicielle de ses anciennes limites. Plus de gens peuvent transformer une idée en produit qui marche, les petites équipes construisent bien plus qu'avant, et l'open source foisonne de projets qui valent d'être faits siens. Ce qui n'a presque pas suivi le même rythme, c'est le travail nécessaire pour le faire tourner soi-même. La plupart des outils self-hosted attendent encore que vous compreniez et gériez Docker, un serveur web, des certificats, des bases de données, des sauvegardes, un pare-feu et les mises à jour qui suivent.

**PanelAlpha Engine** rend la gestion logicielle en production aussi simple que l'IA a rendu sa construction.

- **Une commande, une seule fois.** Ensuite vous parlez à l'assistant IA que vous utilisez déjà.
- **Vous demandez avec des mots simples.** Le moteur construit le projet, le démarre, lui donne HTTPS et le garde sauvegardé.
- **L'IA n'obtient jamais root.** Elle travaille à travers le moteur, dans le cadre des règles que vous définissez.

[**Rejoindre Discord**](https://discord.gg/9twHWR7xGX). C'est le lieu principal pour poser une question, montrer ce que vous avez déployé et parler aux personnes qui construisent ceci.

---

## Trois étapes simples pour l'installer

### Étape 1 : Installez Engine sur votre VPS

Il vous faut un serveur **neuf** sous Debian 12/13 ou Ubuntu 22.04/24.04/26.04, avec au moins 2 Go de RAM et 1 CPU, et vous vous connectez en `root` par SSH :

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

C'est tout. Votre serveur est prêt. Un nom à vous, votre propre certificat TLS, ou un serveur derrière du NAT : [options d'installation](docs/02-getting-started/install.md).

### Étape 2 : Connectez votre agent IA

À la fin de l'installation, le moteur demande quel assistant vous utilisez et affiche la commande exacte à lancer sur votre ordinateur. Pour en connecter un autre plus tard, lancez ceci sur le serveur :

```bash
pae connect
```

Lancez `pae connect` sur le serveur du moteur, pas sur votre ordinateur portable. La ligne qu'il affiche est celle à lancer sur votre ordinateur.

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

Vous utilisez autre chose ? N'importe quel assistant qui parle MCP fonctionne : [connecter d'autres assistants](docs/04-connecting-your-ai/other-mcp-clients.md).

Un jeton avec les permissions par défaut peut supprimer un projet entier. Vous pouvez en délivrer un en lecture seule, ou retirer certains outils : [décider ce que l'assistant a le droit de faire](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### Étape 3 : C'est fait. Demandez ce que vous voulez

Ouvrez le chat de votre assistant et dites-le comme vous le diriez à quelqu'un :

```text
déploie github.com/anna/invoicer sur mon serveur
```

> C'est fait. Disponible sur `invoicer.panelalpha.online`

Chaque projet reçoit une adresse `panelalpha.online` gratuite dès sa création. Quand vous êtes prêt, vous demandez votre propre domaine, et le certificat vient avec.

Vous préférez le faire depuis le serveur ? [Déployer directement depuis git](docs/README.md#3-put-your-project-online).

---

## Ce que vous pouvez simplement demander

Aucune commande à apprendre, et aucune formule magique non plus. Ces exemples montrent surtout le niveau de détail qui vaut la peine d'être donné :

| Vous dites | Ce qui se passe |
|:---|:---|
| `Déploie github.com/org/app sur ce moteur.` | Un projet est créé, la stack est détectée, l'app est construite, démarrée et reçoit une adresse en HTTPS. |
| `Ajoute shop.example.com à ce projet et prends un certificat.` | Le domaine est rattaché et Let's Encrypt délivre un certificat qui se renouvelle tout seul. |
| `Ce site ne s'ouvre pas. Lis le log de déploiement et répare ce que tu peux.` | Votre assistant lit le log, regarde ce que le site renvoie vraiment, corrige votre code et redéploie. |
| `Pousse-le d'abord sur le staging.` | Une copie liée avec sa propre adresse. Vous la passez en production quand elle vous convient, dans les deux sens. |
| `Installe WordPress ici, admin anna.` | WordPress installé et prêt, avec WP-CLI pour tout ce qui suit. |
| `Crée une base MySQL pour ce projet, avec un utilisateur.` | Base, utilisateur et droits, sans que vous touchiez au SQL. |
| `Restaure la sauvegarde d'hier.` | La sauvegarde est restaurée, et l'état actuel est mis de côté avant, au cas où. |
| `Ne laisse que notre bureau accéder à cet outil interne.` | Une règle de pare-feu qui limite ce projet aux adresses que vous indiquez. |
| `Monte un tunnel Cloudflare pour n8n.mydomain.com.` | DNS et tunnel configurés, ce qui est aussi la façon de servir depuis un serveur derrière du NAT. |
| `Combien de trafic avons-nous eu la semaine dernière ?` | L'utilisation, les logs et les limites de ce projet, et ceux du serveur entier. |

Plus d'exemples détaillés : [quoi demander](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). La liste complète de ce que votre assistant peut atteindre : [182 outils](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">Un VPS. Plusieurs projets. Fonctionne avec tout.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
Vos propres outils, des projets construits avec l'IA ou des logiciels open source trouvés en ligne. Peu importe : PanelAlpha Engine les fait simplement tourner. Quelques applications reçoivent un traitement particulier parce que l'approche générale les traiterait mal : WordPress, Matomo, phpBB, Magento et Passbolt. Voir la <a href="docs/07-supported-projects/project-types.md">liste complète</a>.
</p>

---

## Ne laissez pas l'IA transformer votre serveur en désordre

Donner un accès root complet à l'IA entraîne des ports ouverts, des réglages aléatoires et un projet qui en affecte un autre. PanelAlpha Engine fixe les règles :

- **Moins de place pour les erreurs.** L'IA travaille à travers le moteur, pas directement sur le serveur.
- **Une structure claire.** Les projets, les comptes et les domaines restent organisés.
- **Isolation des projets.** Chacun tourne dans son propre conteneur Docker, avec ses propres limites de disque, de mémoire et de CPU, et uniquement les ports dont il a besoin.

Vous décidez de ce que l'assistant peut faire avant de le connecter. [Décider ce que l'assistant a le droit de faire](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [Sécurité](docs/05-capabilities/security.md)

---

## Tout ce qu'il vous faut pour la production, prêt à l'emploi

Le déploiement n'est que le début. La gestion continue est là dès la première installation :

- **Voyez-le en ligne immédiatement.** Un nom `panelalpha.online` gratuit sur chaque projet, plus vos propres domaines quand vous le souhaitez. Les certificats viennent de Let's Encrypt et se renouvellent seuls. [Domaines et HTTPS](docs/05-capabilities/domains-and-ssl.md)
- **Construisez sur le staging. Passez en production quand tout est prêt.** Une copie liée, à casser librement, puis à passer en production. L'inverse fonctionne aussi pour rafraîchir votre copie de test avec des données réelles. [Projets](docs/05-capabilities/projects.md#staging)
- **Supervision intégrée.** Métriques du serveur, logs par site, rapports Lighthouse à la demande et, toutes les six heures, une vérification que chaque site se sert toujours lui-même plutôt qu'une page vide. [Supervision et logs](docs/05-capabilities/monitoring-and-logs.md)
- **L'essentiel est couvert.** Sauvegardes sur le serveur et en dehors, SSL, domaines, bases de données, comptes FTP et SFTP, tâches cron et logs. [Sauvegardes](docs/05-capabilities/backups.md) · [Bases de données](docs/05-capabilities/databases.md) · [Fichiers et accès](docs/05-capabilities/files-and-access.md)
- **Un espace sûr par projet.** Isolation Docker, pare-feu et ModSecurity avec les règles OWASP. Une app défaillante ne peut pas faire tomber les autres. [Sécurité](docs/05-capabilities/security.md)
- **Cloudflare en une connexion.** DNS, tunnels et cache pour vos projets, y compris un site sur un serveur derrière du NAT. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## Quand un déploiement échoue, et comment le moteur s'améliore

La plupart des premiers déploiements passent. Ceux qui échouent viennent généralement du dépôt. La plupart des outils vous laissent avec une trace d'exécution. Ici, le chemin est le suivant :

- **Vous obtenez une phrase, pas une trace.** « Ce projet a besoin de PHP 8.2, mais il a été construit avec PHP 8.1. » « Le build a manqué de mémoire. » La sortie technique complète reste dessous, si quelqu'un la veut.
- **Ensuite, votre assistant prend le relais.** Il lit le log, regarde ce que le site renvoie vraiment, corrige dans votre code ce qui peut l'être, et redéploie. Ce travail tourne sur l'abonnement IA que vous payez déjà, pas sur des jetons d'API.
- **Le moteur en tire des leçons.** Un rapport anonyme part chez PanelAlpha. Une panne sur votre serveur, cela peut être votre dépôt. La même panne sur trente serveurs, c'est un bug dans la détection ou dans une recette de framework, et cela devient un correctif dans la mise à jour suivante.
- **Quand c'est le moteur qui s'est trompé**, dites-le et il rassemble les preuves : demandez à votre assistant de le faire, ou lancez `pae telemetry:bug-report <projet>`.

Toute la procédure : [quand un déploiement échoue](docs/02-getting-started/what-happens.md) · [ce que veut dire l'erreur](docs/02-getting-started/reading-errors.md).

---

## Télémétrie

La télémétrie est active après l'installation. Des rapports anonymes sur les déploiements et les vérifications de santé ultérieures quittent le serveur tant que vous ne la coupez pas. Ils incluent les noms publics de vos sites.

- **Envoyé :** quel genre d'application c'était, combien de temps cela a pris, les limites du projet, et en cas d'échec l'étape qui a cassé plus la fin du log, secrets retirés. Un rapport de santé uniquement quand un site cesse plus tard de se servir lui-même. Un rapport de bug uniquement quand vous en déposez un.
- **Jamais envoyé :** votre code source, les noms de projets, les jetons, les mots de passe, l'adresse IP ou le nom de votre serveur, les noms de dépôts privés, ni quoi que ce soit sur les personnes qui visitent vos sites.
- **Voyez un rapport avant de décider :** `pae telemetry:status` et `pae telemetry:show`.

Pour arrêter l'envoi :

```bash
pae telemetry:disable
```

Ce qui est collecté, ce qui ne l'est pas, et toutes les façons de le couper : [ce qui est collecté](docs/02-getting-started/what-is-collected.md) · [comment le couper](docs/02-getting-started/how-to-turn-it-off.md).

---

## FAQ

<details>
<summary><b>Pourquoi ai-je besoin de PanelAlpha Engine ?</b></summary>

Parce qu'une application n'est pas terminée quand le code est terminé. Votre projet a encore besoin d'un endroit où tourner, sans parler de tout ce qu'il faut pour le garder en bonne santé, joignable et sûr. PanelAlpha Engine donne à votre assistant IA un vrai moyen de prendre en charge toute cette couche pour vous. Vous demandez le résultat que vous voulez, et le moteur le transforme en opérations serveur contrôlées, sans donner à l'IA un accès sans restriction à la machine.

Si vous connaissez déjà Docker, les reverse proxies, les pare-feu et les logs serveur, ce savoir compte encore. PanelAlpha n'essaie pas de vous cacher l'infrastructure ni de vous en fermer l'accès. Il vous donne une façon plus propre de l'opérer, d'automatiser les parties répétitives et de laisser l'IA prendre un vrai travail sans abandonner le contrôle. Vous pouvez aller aussi loin que vous voulez quand quelque chose mérite votre attention, et sauter la routine quand ce n'est pas le cas.
</details>

<details>
<summary><b>Ai-je besoin de mon propre serveur ?</b></summary>

Oui. PanelAlpha Engine est un outil qui permet aux agents IA de gérer *votre* serveur. Il vous faut un VPS Debian ou Ubuntu neuf, avec au moins 2 Go de RAM et 1 CPU, sur lequel vous vous connectez en `root` par SSH. Vous commencez en lançant vous-même l'installation d'Engine sur ce VPS. [Ce qu'il vous faut](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>Quels agents IA fonctionnent avec PanelAlpha Engine ?</b></summary>

Tout assistant capable de se connecter à un serveur MCP avec un jeton. Il y a des pages pas à pas pour [Claude Code](docs/04-connecting-your-ai/claude-code.md), [Cursor](docs/04-connecting-your-ai/cursor.md), [Codex](docs/04-connecting-your-ai/codex.md), [Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md), [VS Code et Copilot](docs/04-connecting-your-ai/vs-code-copilot.md), [Grok](docs/04-connecting-your-ai/grok.md), [OpenCode](docs/04-connecting-your-ai/opencode.md), [Windsurf](docs/04-connecting-your-ai/windsurf.md), [Pi](docs/04-connecting-your-ai/pi.md), [Hermes](docs/04-connecting-your-ai/hermes.md) et [OpenClaw](docs/04-connecting-your-ai/openclaw.md), plus [tout ce qui parle MCP](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>Quels projets puis-je déployer avec PanelAlpha Engine ?</b></summary>

L'objectif est un outil universel pour n'importe quel projet. Sites statiques, WordPress, PHP, Laravel, Node, Next.js, Django, Go, Rust, Java, un simple `Dockerfile` ou un fichier Compose. Vos propres outils, ceux construits avec l'IA, et les logiciels open source trouvés en ligne. Voir les [types de projets](docs/07-supported-projects/project-types.md) et, si vous voulez vérifier avant, demandez à votre assistant d'inspecter d'abord le dépôt.
</details>

<details>
<summary><b>Est-ce gratuit ? Qu'est-ce que je paie ?</b></summary>

PanelAlpha Engine est gratuit et open source sous licence Apache 2.0. Vous payez le serveur et l'abonnement IA, et vous avez déjà les deux. Le débogage d'un déploiement raté tourne sur l'abonnement de votre assistant, pas sur des jetons d'API.
</details>

<details>
<summary><b>Puis-je limiter ce que mon assistant a le droit de faire ?</b></summary>

Oui, et cela vaut le coup avant de coller un jeton. Vous pouvez lui donner un jeton en lecture seule, autoriser les modifications mais pas les suppressions, n'exposer que certains domaines, ou retirer des outils précis comme `project_delete`. [Décider ce que l'assistant a le droit de faire](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>Qu'est-ce qui est remonté quand un déploiement échoue ?</b></summary>

Un résumé anonyme : le type d'application, l'étape qui a cassé, la fin expurgée du log et les noms publics des sites. Jamais votre code source, les noms de vos projets, vos identifiants ni l'identité de votre serveur. Vous pouvez afficher un rapport en attente avec `pae telemetry:show` et tout désactiver. Le détail : [Télémétrie](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>Puis-je utiliser PanelAlpha Engine pour de l'hébergement mutualisé ?</b></summary>

Oui. Chaque projet est un compte séparé avec ses domaines, ses bases, ses fichiers et ses limites. Des hébergeurs font tourner des milliers de sites avec lui en production.
</details>

<details>
<summary><b>Le moteur s'est trompé sur mon projet. Et maintenant ?</b></summary>

Déposez un rapport de bug et il rassemble les preuves lui-même. Demandez-le à votre assistant, ou lancez `pae telemetry:bug-report <projet>` sur le serveur. Avec `--dry-run`, vous voyez d'abord exactement ce qui partirait.
</details>

---

## Parlez-nous sur Discord

**Discord est le lieu principal pour nous joindre.** Posez une question, montrez-nous ce que vous avez déployé, partagez une idée, ou passez simplement voir sur quoi nous travaillons. Nous sommes au milieu de ces conversations, et ce que vous y soulevez peut devenir la prochaine chose que nous construisons, corrigeons ou reconsidérons.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="Parlez-nous sur Discord, le lieu principal pour joindre l'équipe PanelAlpha" width="760">
</a>
</div><br>

Pas vraiment Discord ? [Notre forum](https://community.panelalpha.com/) est tout aussi ouvert.

## Votre serveur est à une commande

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## Sécurité

Une machine qui héberge le moteur ne sert qu'à cela. L'installeur remplace le résolveur et le pare-feu, traitez-la en conséquence.

Vous avez trouvé une faille ? Signalez-la **en privé**, jamais dans un ticket public. Voir [`SECURITY.md`](SECURITY.md), ou utilisez [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## Licence

PanelAlpha Engine est open source sous licence Apache 2.0.

## Venez construire avec nous

Envie de participer ? [`CONTRIBUTING.md`](CONTRIBUTING.md) vous met en route. La documentation pour la personne qui exploite le serveur est dans [`docs/`](docs/README.md).
