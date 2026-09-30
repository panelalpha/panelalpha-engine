// One-shot before `app`: lets Posterr write its own default config/settings.json,
// then replaces the shipped default password with the generated one.
const fs = require("fs");
process.chdir("/usr/src/app");
const Settings = require("/usr/src/app/classes/core/settings.js");
const DEFAULT = require("/usr/src/app/consts.js").password;
const FILE = "config/settings.json";

(async () => {
  const pw = process.env.POSTERR_PASSWORD || "";
  if (pw.length < 16) throw new Error("POSTERR_PASSWORD missing");
  await new Settings().GetSettings();
  const s = JSON.parse(fs.readFileSync(FILE, "utf-8"));
  // A password the owner set in the UI is kept; only the default or none is replaced.
  if (s.password === undefined || s.password === "" || s.password === DEFAULT) {
    s.password = pw;
    fs.writeFileSync(FILE, JSON.stringify(s, null, 4));
    console.log("[panelalpha] posterr init: settings password set from app-credentials.env");
  } else {
    console.log("[panelalpha] posterr init: settings password already non-default; nothing to do");
  }
  process.exit(0);
})().catch((e) => { console.error("[panelalpha] posterr init:", e.message); process.exit(1); });
