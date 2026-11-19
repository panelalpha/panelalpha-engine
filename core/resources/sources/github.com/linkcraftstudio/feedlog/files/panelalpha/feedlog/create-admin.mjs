// Signs up the owner of the workspace with the login the engine generated
// (FEEDLOG_ADMIN_EMAIL / FEEDLOG_ADMIN_PASSWORD from
// ~/.panelalpha/app-credentials.env), through FeedLog's own sign-up endpoint
// (better-auth's POST /api/auth/sign-up/email), so the app hashes the
// password and runs its own bootstrap hook: an email in SYSTEM_ADMIN_EMAILS
// (start.sh sets it to FEEDLOG_ADMIN_EMAIL) joins the default workspace as its
// owner and gets the onboarding content.
//
// Runs in the one-shot `admin-login` service on every `up`. It only ever
// creates the account: when one with that email exists already (made by an
// earlier deploy, or by whoever signed up first on an install from before
// this recipe), FeedLog answers USER_ALREADY_EXISTS and it is left alone, so a
// password changed in the app is never reset.

const base = 'http://app:3000'
const email = process.env.FEEDLOG_ADMIN_EMAIL ?? ''
const password = process.env.FEEDLOG_ADMIN_PASSWORD ?? ''
const say = message => console.error(`[panelalpha] feedlog: ${message}`)

if (!email || !password) {
  say('FEEDLOG_ADMIN_EMAIL or FEEDLOG_ADMIN_PASSWORD is not in ~/.panelalpha/app-credentials.env; no owner created')
  process.exit(1)
}

// The app runs its migrations before it listens; on a first deploy that is
// a while.
let up = false
for (let i = 0; i < 150 && !up; i++) {
  try {
    up = (await fetch(`${base}/health`)).ok
  } catch {}
  if (!up) await new Promise(resolve => setTimeout(resolve, 2000))
}
if (!up) {
  say(`${base}/health did not answer in 5 minutes; no owner created`)
  process.exit(1)
}

// better-auth trusts the request's own Host as an origin (defaultTrustedOrigins).
const response = await fetch(`${base}/api/auth/sign-up/email`, {
  method: 'POST',
  headers: { 'content-type': 'application/json', origin: base },
  body: JSON.stringify({ email, password, name: 'Admin' }),
})
const body = await response.json().catch(() => ({}))

if (response.ok) {
  say(`signed up ${email} as the owner of the workspace`)
  process.exit(0)
}
if (body?.code === 'USER_ALREADY_EXISTS' || body?.code === 'USER_ALREADY_EXISTS_USE_ANOTHER_EMAIL') {
  say(`${email} exists already; left alone`)
  process.exit(0)
}
say(`sign-up answered ${response.status}: ${JSON.stringify(body)}`)
process.exit(1)
