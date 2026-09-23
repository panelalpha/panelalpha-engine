<div align="center" dir="rtl">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="docs/assets/panelalpha-engine.svg"><img src="docs/assets/panelalpha-engine-light.svg" alt="PanelAlpha Engine" width="360"></picture></p>

<h1>وكيلك الذكي يشغّل<br>خادمك. تحت السيطرة.</h1>

<h3>
مفتوح المصدر. مستضاف ذاتياً. سهل لأي شخص، لا للمسؤولين التقنيين وحدهم.
</h3>

<h3>
<a href="#ثلاث-خطوات-بسيطة-لإعداده"><b>ابدأ</b></a> ·
<a href="https://www.panelalpha.com/documentation/panelalpha-engine/"><b>التوثيق</b></a> ·
<a href="#تحدث-معنا-على-discord"><b>Discord</b></a>
</h3>

<p>
<a href="#ما-يمكنك-ببساطة-أن-تطلبه">التحكم بالذكاء الاصطناعي</a> ·
<a href="#كل-ما-تحتاجه-للإنتاج-جاهزاً-من-البداية">الإمكانات</a> ·
<a href="#القياس-عن-بعد">القياس عن بعد</a> ·
<a href="#الأسئلة-الشائعة">الأسئلة الشائعة</a>
</p>

<p dir="ltr">
<a href="README.md">English</a>
· <a href="README.pl.md">Polski</a>
· <a href="README.de.md">Deutsch</a>
· <a href="README.nl.md">Nederlands</a>
· <a href="README.es.md">Español</a>
· <a href="README.fr.md">Français</a>
· <a href="README.it.md">Italiano</a>
· <a href="README.pt-BR.md">Português</a>
· <a href="README.uk.md">Українська</a>
· <b>العربية</b>
· <a href="README.zh-CN.md">简体中文</a>
</p>

<p>
<a href="#الخطوة-1-ثبّت-engine-على-خادمك-vps"><img src="https://img.shields.io/badge/install-one--liner-2f8f46" alt="تثبيت بسطر واحد"></a>
<a href="#الخطوة-1-ثبّت-engine-على-خادمك-vps"><img src="https://img.shields.io/badge/Debian_12%2F13-Ubuntu_22.04%2F24.04%2F26.04-a80030" alt="أنظمة التشغيل المدعومة"></a>
<a href="docs/04-connecting-your-ai/your-assistant.md"><img src="https://img.shields.io/badge/MCP-199_tools-6f42c1" alt="199 أداة MCP"></a>
<a href="#الترخيص"><img src="https://img.shields.io/badge/license-Apache_2.0-0b7285" alt="Apache 2.0"></a>
<a href="https://discord.gg/9twHWR7xGX"><img src="https://img.shields.io/badge/Discord-join-5865F2?logo=discord&logoColor=white" alt="انضم إلى Discord"></a>
</p>

<p align="center"><img src="docs/assets/panelalpha-engine.gif" alt="نشر مشروع بمجرد طلبه من المساعد" width="760"></p>

</div>

<div dir="rtl">

---

## ما هو PanelAlpha Engine؟

PanelAlpha Engine برنامج تثبّته على خادم VPS لاستضافة مشاريع مبنية بالذكاء الاصطناعي وvibe-coded، ومواقع وتطبيقات مفتوحة المصدر وجدتها على الإنترنت. بعد التثبيت تربط وكيلك الذكي مباشرة بـ PanelAlpha Engine وتتركه يتولى النشر والصيانة وإدارة الخادم. يبقى خادمك منظماً وتحت السيطرة.

**لكن الأهم أن إدارة خادمك/VPS تصبح في غاية البساطة**. لم تعد بحاجة لأن تكون مسؤولاً تقنياً كي تستضيف بنفسك!

من أول تثبيت يعطي PanelAlpha Engine لك ولذكائك الاصطناعي كل ما تحتاجانه لتشغيل مشاريع حقيقية في الإنتاج:

- انشر أي تقنيات من Git أو من ملفات
- عناوين معاينة فورية، مع حماية اختيارية بكلمة مرور
- مسارات staging وGit، ببيئتي إنتاج وتجربة منفصلتين
- نسخ احتياطي واستعادة تلقائيان
- مراقبة خارجية وإحصاءات زوار مدمجة
- عزل المشاريع في حاويات دوكر منفصلة
- جدار حماية وحماية OWASP/WAF بقواعد وصول لكل مشروع
- نطاقات وSSL وcron وFTP/SFTP وقواعد بيانات وسجلات
- تكامل سهل مع Cloudflare للـ DNS والأنفاق والتخزين المؤقت

## لماذا يجب أن يوجد هذا

الذكاء الاصطناعي أخرج إنشاء البرمجيات من حدوده القديمة. مزيد من الناس يستطيعون تحويل فكرة إلى منتج يعمل، والفرق الصغيرة تبني أكثر بكثير مما قبل، والمصدر المفتوح يزدهر بمشاريع تستحق أن تجعلها ملكك. ما لم يتغير بالسرعة نفسها تقريباً هو العمل المطلوب لتشغيل ذلك بنفسك. معظم أدوات الاستضافة الذاتية ما زالت تتوقع أن تفهم وتدير دوكر وخادم ويب وشهادات وقواعد بيانات ونسخاً احتياطياً وجدار حماية ثم التحديثات التي تلي ذلك.

**PanelAlpha Engine** يجعل إدارة البرمجيات في الإنتاج سهلة بقدر ما جعل الذكاء الاصطناعي بناءها سهلاً.

- **أمر واحد، مرة واحدة.** بعدها تتحدث مع المساعد الذكي الذي تستعمله أصلاً.
- **تطلب بكلمات عادية.** المحرك يبني المشروع ويشغّله ويمنحه HTTPS ويبقيه منسوخاً احتياطياً.
- **الذكاء الاصطناعي لا يحصل على صلاحية الجذر.** يعمل عبر المحرك، داخل قواعد تضعها أنت.

[**انضم إلى Discord**](https://discord.gg/9twHWR7xGX). هذا المكان الأساسي لتسأل وتعرض ما نشرته وتتحدث مع من يبنون هذا.

---

## ثلاث خطوات بسيطة لإعداده

### الخطوة 1: ثبّت Engine على خادمك VPS

تحتاج خادماً **نظيفاً** يعمل بـ Debian 12/13 أو Ubuntu 22.04/24.04/26.04، بذاكرة 2 غيغابايت ومعالج واحد على الأقل، وتدخل إليه بحساب `root` عبر SSH:

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

هذا كل شيء. خادمك جاهز. اسم خاص، أو شهادة TLS خاصة بك، أو خادم خلف NAT: [خيارات التثبيت](docs/02-getting-started/install.md).

### الخطوة 2: اربط وكيلك الذكي

في نهاية التثبيت يسألك المحرك عن المساعد الذي تستعمله، ويطبع الأمر الدقيق لتشغيله على جهازك. ولربط مساعد آخر لاحقاً، نفّذ على الخادم:

```bash
pae connect
```

نفّذ `pae connect` على خادم المحرك، لا على حاسوبك المحمول. السطر الذي يطبعه هو ما تشغّله على جهازك.

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

تستعمل شيئاً آخر؟ أي مساعد يتحدث MCP سيعمل: [ربط مساعدين آخرين](docs/04-connecting-your-ai/other-mcp-clients.md).

الرمز بالصلاحيات الافتراضية قادر على حذف مشروع كامل. يمكنك إصدار رمز للقراءة فقط، أو سحب أدوات بعينها: [قرّر ما يُسمح للمساعد به](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do).

### الخطوة 3: تم. اطلب ما تريد

افتح محادثة مساعدك وقل الأمر كما تقوله لإنسان:

```text
انشر github.com/anna/invoicer على خادمي
```

> تم. متاح على `invoicer.panelalpha.online`

كل مشروع يحصل على عنوان مجاني على `panelalpha.online` لحظة إنشائه. وحين تصبح جاهزاً، اطلب نطاقك الخاص، وتأتي الشهادة معه.

تفضّل أن تفعلها بنفسك من الخادم؟ [النشر مباشرة من git](docs/README.md#3-put-your-project-online).

---

## ما يمكنك ببساطة أن تطلبه

لا أوامر تحفظها، ولا عبارات سحرية. هذه أمثلة تُظهر مقدار التفصيل الذي يستحق أن تذكره:

| تقول | ما الذي يحدث |
|:---|:---|
| `انشر github.com/org/app على هذا المحرك.` | يُنشأ مشروع، وتُكتشف التقنيات، ويُبنى التطبيق ويُشغَّل ويحصل على عنوان مع HTTPS. |
| `أضف shop.example.com إلى هذا المشروع واطلب شهادة له.` | يُربط النطاق وتُصدر Let's Encrypt شهادة تجدّد نفسها. |
| `هذا الموقع لا يفتح. اقرأ سجل النشر وأصلح ما تستطيع.` | يقرأ مساعدك السجل، ويرى ما يقدّمه الموقع فعلاً، ويعدّل الكود ثم ينشر من جديد. |
| `ادفعه إلى staging أولاً.` | نسخة مرتبطة بعنوان خاص بها. ادفعها إلى الإنتاج حين ترضيك، وفي الاتجاهين. |
| `ثبّت ووردبريس هنا، المدير anna.` | ووردبريس مثبّت وجاهز، ومعه WP-CLI لكل ما يأتي بعد ذلك. |
| `أنشئ قاعدة MySQL لهذا المشروع ومستخدماً لها.` | قاعدة بيانات ومستخدم وصلاحيات، من دون أن تلمس SQL. |
| `ارجع إلى نسخة الأمس الاحتياطية.` | تُستعاد النسخة، ويُحفظ الوضع الحالي قبلها، تحسّباً. |
| `اسمح لمكتبنا وحده بالوصول إلى هذه الأداة الداخلية.` | قاعدة في جدار الحماية تحصر المشروع بالعناوين التي تذكرها. |
| `أعدّ نفق Cloudflare لـ n8n.mydomain.com.` | إعداد DNS والنفق، وهي أيضاً طريقة التقديم من خادم خلف NAT. |
| `كم كان الزيارات الأسبوع الماضي؟` | الاستهلاك والسجلات والحدود لذلك المشروع، وللخادم كله. |

أمثلة أوفى: [ماذا تطلب](docs/04-connecting-your-ai/your-assistant.md#what-to-ask). القائمة الكاملة لما يصل إليه مساعدك: [199 أداة](docs/04-connecting-your-ai/your-assistant.md).

---

<h2 align="center">خادم VPS واحد. مشاريع متعددة. يعمل مع أي شيء.</h2>

<p align="center">
<a href="docs/07-supported-projects/project-types.md"><img src="https://skillicons.dev/icons?i=php,wordpress,laravel,nodejs,nextjs,nuxtjs,svelte,remix,astro,angular,react,vite,nestjs,express,python,django,go,rust,java,ruby,rails,docker,html,dotnet&perline=12" alt="PHP, WordPress, Laravel, Node.js, Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, React, Vite, NestJS, Express, Python, Django, Go, Rust, Java, Ruby, Rails, Docker, HTML, .NET"></a>
<br>
أدواتك الخاصة، أو مشاريع بنيتها بالذكاء الاصطناعي، أو برمجيات مفتوحة المصدر وجدتها على الإنترنت. لا يهم: PanelAlpha Engine يشغّلها فحسب. بعض التطبيقات تُعامَل معاملة خاصة لأن الطريق العام سيخطئ معها: ووردبريس وMatomo وphpBB وMagento وPassbolt. انظر <a href="docs/07-supported-projects/project-types.md">القائمة الكاملة</a>.
</p>

---

## لا تدع الذكاء الاصطناعي يحوّل خادمك إلى فوضى

إعطاء الذكاء الاصطناعي صلاحية الجذر كاملة يعني منافذ مفتوحة وإعدادات عشوائية ومشروعاً يؤثر في آخر. PanelAlpha Engine يضع القواعد:

- **مجال أقل للأخطاء.** الذكاء الاصطناعي يعمل عبر المحرك، لا مباشرة على الخادم.
- **بنية واضحة.** المشاريع والحسابات والنطاقات تبقى منظّمة.
- **عزل المشاريع.** كل مشروع يعمل في حاوية دوكر خاصة به، بحدوده الخاصة على القرص والذاكرة والمعالج، وبالمنافذ التي يحتاجها فقط.

ما يُسمح للمساعد به تقرره أنت قبل أن تربطه. [قرّر ما يُسمح للمساعد به](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do) · [الأمان](docs/05-capabilities/security.md)

---

## كل ما تحتاجه للإنتاج، جاهزاً من البداية

النشر مجرد البداية. الإدارة المستمرة موجودة منذ أول تثبيت:

- **شاهده على الإنترنت فوراً.** اسم مجاني على `panelalpha.online` لكل مشروع، إضافة إلى نطاقاتك الخاصة متى شئت. الشهادات من Let's Encrypt وتجدّد نفسها. [النطاقات وHTTPS](docs/05-capabilities/domains-and-ssl.md)
- **ابنِ على staging. انشر حين تكون جاهزاً.** نسخة مرتبطة تكسرها كما تشاء، ثم تدفعها إلى الإنتاج. والعكس متاح أيضاً، لتحديث نسخة الاختبار ببيانات حقيقية. [المشاريع](docs/05-capabilities/projects.md#staging)
- **مراقبة مدمجة.** مقاييس الخادم، وسجلات لكل موقع، وتقارير Lighthouse عند الطلب، وفحص كل ست ساعات يتأكد أن كل موقع ما زال يقدّم نفسه لا صفحة فارغة. [المراقبة والسجلات](docs/05-capabilities/monitoring-and-logs.md)
- **الأساسيات كلها مغطاة.** نسخ احتياطية على الخادم وخارجه، وSSL، ونطاقات، وقواعد بيانات، وحسابات FTP وSFTP، ومهام cron وسجلات. [النسخ الاحتياطي](docs/05-capabilities/backups.md) · [قواعد البيانات](docs/05-capabilities/databases.md) · [الملفات والوصول](docs/05-capabilities/files-and-access.md)
- **لكل مشروع مساحته الآمنة.** عزل دوكر وجدار حماية وModSecurity بقواعد OWASP. تطبيق واحد معطّل لا يُسقط البقية. [الأمان](docs/05-capabilities/security.md)
- **Cloudflare في اتصال واحد.** DNS والأنفاق والتخزين المؤقت عبر مشاريعك، بما في ذلك موقع على خادم خلف NAT. [Cloudflare](docs/05-capabilities/cloudflare.md)

---

## حين يفشل النشر، وكيف يتحسّن المحرك من ذلك

معظم عمليات النشر الأولى تنجح. وما يفشل منها سببه عادة المستودع نفسه. معظم الأدوات تتركك أمام أثر تنفيذ. هنا المسار هو:

- **تحصل على جملة، لا على أثر تنفيذ.** "هذا المشروع يحتاج PHP 8.2، لكنه بُني بـ PHP 8.1." "نفدت الذاكرة أثناء البناء." المخرجات التقنية الكاملة تبقى تحتها إن احتاجها أحد.
- **من هناك يتولّى مساعدك الأمر.** يقرأ السجل، ويرى ما يقدّمه الموقع فعلاً، ويصلح في كودك ما يمكن إصلاحه، ثم ينشر مجدداً. هذا العمل يجري على اشتراك الذكاء الاصطناعي الذي تدفعه أصلاً، لا على رموز واجهة برمجية.
- **والمحرك يتعلّم من ذلك.** تقرير مجهول يذهب إلى PanelAlpha. عطل واحد على خادمك قد يكون مستودعك. العطل نفسه على ثلاثين خادماً هو خلل في الاكتشاف أو في وصفة إطار عمل، وهذا يتحوّل إلى إصلاح في التحديث التالي.
- **وحين يكون المحرك هو المخطئ**، قل ذلك وهو يجمع الأدلة: اطلب من مساعدك أن يرفع البلاغ، أو نفّذ `pae telemetry:bug-report <المشروع>`.

الإجراء كاملاً: [حين يفشل النشر](docs/02-getting-started/what-happens.md) · [ماذا يعني الخطأ](docs/02-getting-started/reading-errors.md).

---

## القياس عن بعد

القياس عن بعد مفعّل بعد التثبيت. تقارير مجهولة عن عمليات النشر وفحوص الحالة اللاحقة تغادر الخادم ما لم تعطّله. تتضمن الأسماء العامة لمواقعك.

- **يُرسَل:** نوع التطبيق، وكم استغرق، وحدود المشروع، وعند الفشل المرحلة التي انكسرت ونهاية السجل بعد إزالة الأسرار. تقرير حالة فقط حين يتوقف موقع لاحقاً عن تقديم نفسه. بلاغ خطأ فقط حين ترفعه أنت.
- **لا يُرسَل أبداً:** كودك المصدري، وأسماء المشاريع، والرموز، وكلمات المرور، وعنوان IP أو اسم خادمك، وأسماء المستودعات الخاصة، أو أي شيء عن زوّار مواقعك.
- **انظر تقريراً قبل أن تقرر:** `pae telemetry:status` و`pae telemetry:show`.

لإيقاف الإرسال:

```bash
pae telemetry:disable
```

ما الذي يُجمع، وما لا يُجمع، وكل طرق التعطيل: [ما الذي يُجمع](docs/02-getting-started/what-is-collected.md) · [كيف تعطّله](docs/02-getting-started/how-to-turn-it-off.md).

---

## الأسئلة الشائعة

<details>
<summary><b>لماذا أحتاج PanelAlpha Engine أصلاً؟</b></summary>

لأن التطبيق لا ينتهي حين ينتهي الكود. مشروعك ما زال يحتاج مكاناً يعمل فيه، فضلاً عن كل ما يلزم ليبقى سليماً ومتاحاً وآمناً. PanelAlpha Engine يعطي مساعدك الذكي طريقة حقيقية ليتولى تلك الطبقة كلها نيابة عنك. تطلب النتيجة التي تريدها، والمحرك يحوّلها إلى عمليات خادم مضبوطة من دون منح الذكاء الاصطناعي وصولاً غير مقيد إلى الجهاز.

إن كنت تعرف دوكر والوكلاء العكسيين وجدران الحماية وسجلات الخادم أصلاً، فهذه المعرفة ما زالت مهمة. PanelAlpha لا يحاول إخفاء البنية التحتية عنك ولا إبعادك عنها. يعطيك طريقة أنظف لتشغيلها وأتمتة الأجزاء المتكررة وترك الذكاء الاصطناعي يتولى عملاً حقيقياً من دون تسليم السيطرة. يمكنك أن تغوص بقدر ما تريد حين يستحق أمر انتباهك، وأن تتجاوز الروتين حين لا يستحقه.
</details>

<details>
<summary><b>هل أحتاج خادماً خاصاً بي؟</b></summary>

نعم. PanelAlpha Engine أداة لوكلاء الذكاء الاصطناعي كي يديروا *خادمك*. تحتاج خادم VPS نظيفاً بنظام Debian أو Ubuntu، بذاكرة 2 غيغابايت ومعالج واحد على الأقل، تدخل إليه بحساب `root` عبر SSH. تبدأ بتشغيل تثبيت Engine بنفسك على ذلك الخادم. [ما الذي تحتاجه](docs/02-getting-started/install.md#before-you-start)
</details>

<details>
<summary><b>أي المساعدين الأذكياء يعمل معه؟</b></summary>

أي مساعد يستطيع الاتصال بخادم MCP برمز. هناك صفحات خطوة بخطوة لـ [Claude Code](docs/04-connecting-your-ai/claude-code.md) و[Cursor](docs/04-connecting-your-ai/cursor.md) و[Codex](docs/04-connecting-your-ai/codex.md) و[Gemini CLI](docs/04-connecting-your-ai/gemini-cli.md) و[VS Code وCopilot](docs/04-connecting-your-ai/vs-code-copilot.md) و[Grok](docs/04-connecting-your-ai/grok.md) و[OpenCode](docs/04-connecting-your-ai/opencode.md) و[Windsurf](docs/04-connecting-your-ai/windsurf.md) و[Pi](docs/04-connecting-your-ai/pi.md) و[Hermes](docs/04-connecting-your-ai/hermes.md) و[OpenClaw](docs/04-connecting-your-ai/openclaw.md)، إضافة إلى [كل ما يتحدث MCP](docs/04-connecting-your-ai/other-mcp-clients.md).
</details>

<details>
<summary><b>أي المشاريع يمكنني نشرها على PanelAlpha Engine؟</b></summary>

الهدف أداة عامة لأي مشروع. مواقع ثابتة، ووردبريس، PHP، Laravel، Node، Next.js، Django، Go، Rust، Java، ملف `Dockerfile` عادي أو ملف Compose. أدواتك الخاصة، والمشاريع التي بنيتها بالذكاء الاصطناعي، والبرمجيات مفتوحة المصدر التي وجدتها على الإنترنت. انظر [أنواع المشاريع](docs/07-supported-projects/project-types.md)، وإن أردت التأكد مسبقاً فاطلب من مساعدك فحص المستودع أولاً.
</details>

<details>
<summary><b>هل هو مجاني؟ وماذا أدفع؟</b></summary>

PanelAlpha Engine مجاني ومفتوح المصدر برخصة Apache 2.0. أنت تدفع ثمن الخادم واشتراك الذكاء الاصطناعي، وكلاهما لديك أصلاً. وتصحيح نشر فاشل يجري على اشتراك مساعدك، لا على رموز واجهة برمجية.
</details>

<details>
<summary><b>هل يمكنني تقييد ما يفعله مساعدي؟</b></summary>

نعم، ويستحق ذلك قبل أن تلصق أي رمز. يمكنك منحه رمزاً للقراءة فقط، أو السماح بالتعديل دون الحذف، أو كشف مجالات بعينها، أو سحب أدوات محددة مثل `project_delete`. [قرّر ما يُسمح للمساعد به](docs/04-connecting-your-ai/your-assistant.md#decide-what-the-assistant-may-do)
</details>

<details>
<summary><b>ماذا يُبلَّغ عنه عند فشل النشر؟</b></summary>

ملخّص مجهول: نوع التطبيق، والمرحلة التي انكسرت، ونهاية السجل بعد تنقيحها، والأسماء العامة للمواقع. ولا شيء من كودك أو أسماء مشاريعك أو بيانات دخولك أو هوية خادمك. يمكنك طباعة تقرير منتظر عبر `pae telemetry:show`، كما يمكنك تعطيل ذلك كله. التفاصيل: [القياس عن بعد](docs/02-getting-started/what-is-collected.md).
</details>

<details>
<summary><b>هل يمكن استخدام PanelAlpha Engine للاستضافة المشتركة؟</b></summary>

نعم. كل مشروع حساب منفصل بنطاقاته وقواعد بياناته وملفاته وحدوده، ومزوّدو استضافة يشغّلون آلاف المواقع عليه في الإنتاج.
</details>

<details>
<summary><b>المحرك أخطأ في فهم مشروعي. ماذا الآن؟</b></summary>

ارفع بلاغ خطأ وهو يجمع الأدلة بنفسه. اطلب ذلك من مساعدك، أو نفّذ على الخادم `pae telemetry:bug-report <المشروع>`. واستخدم `--dry-run` أولاً إن أردت أن ترى بالضبط ما الذي سيُرسَل.
</details>

---

## تحدث معنا على Discord

**Discord هو المكان الأساسي للتواصل معنا.** اسأل سؤالاً، أرنا ما نشرته، شارك فكرة، أو مرّ وانظر ما نعمل عليه. نحن في قلب تلك المحادثات، وما تطرحه هناك يمكن أن يصير الشيء التالي الذي نبنيه أو نصلحه أو نعيد التفكير فيه.

<br><div align="center">
<a href="https://discord.gg/9twHWR7xGX">
<img src="docs/assets/discord-banner.png" alt="تحدث معنا على Discord، المكان الأساسي للتواصل مع فريق PanelAlpha" width="760">
</a>
</div><br>

لست من محبي Discord؟ [منتدانا](https://community.panelalpha.com/) مفتوح بالقدر نفسه.

## خادمك على بعد أمر واحد

```bash
curl -fsSL https://get.panelalpha.com/engine | sh
```

## الأمان

خادم المحرك آلة لغرض واحد. المثبّت يستبدل محلل الأسماء وجدار الحماية، فتعامل معه على هذا الأساس.

وجدت ثغرة؟ أبلغ عنها **سراً**، لا في بلاغ عام. انظر [`SECURITY.md`](SECURITY.md)، أو استخدم [manage.panelalpha.com/contact](https://manage.panelalpha.com/contact).

## الترخيص

PanelAlpha Engine مفتوح المصدر برخصة Apache 2.0.

## تعال ابنِ معنا

تريد المشاركة؟ [`CONTRIBUTING.md`](CONTRIBUTING.md) يضعك على الطريق. توثيق المشغّل موجود في [`docs/`](docs/README.md).

</div>
