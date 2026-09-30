// Creates Questarr's admin from ~/.panelalpha/app-credentials.env, only while
// setup is open. Questarr runs here on loopback with no published port.
import { spawn } from "node:child_process";

const { QUESTARR_ADMIN_USER: user, QUESTARR_ADMIN_PASSWORD: password } = process.env;
if (!user || !password) throw new Error("app-credentials.env is missing QUESTARR_ADMIN_USER/PASSWORD");
const api = "http://127.0.0.1:5000/api";

const app = spawn("node", ["dist/server/index.js"], { stdio: "inherit" });
let exited = false;
app.on("exit", () => { exited = true; });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function hasUsers() {
  const res = await fetch(`${api}/auth/status`);
  if (!res.ok) throw new Error(`auth/status answered ${res.status}`);
  return (await res.json()).hasUsers === true;
}

try {
  for (let i = 0; ; i++) {
    if (exited) throw new Error("app exited during seeding");
    if (i >= 120) throw new Error("app did not answer within 120s");
    try { if ((await fetch(`${api}/health`)).ok) break; } catch {}
    await sleep(1000);
  }
  if (await hasUsers()) {
    console.log("questarr: user present, nothing to seed");
  } else {
    const res = await fetch(`${api}/auth/setup`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ username: user, password }),
    });
    if (!res.ok) throw new Error(`auth/setup answered ${res.status}: ${await res.text()}`);
    console.log(`questarr: created user '${user}'`);
  }
  if (!(await hasUsers())) throw new Error("setup is still open");
} finally {
  app.kill("SIGTERM");
  setTimeout(() => app.kill("SIGKILL"), 10000).unref();
}
