// Sets the engine's password as WebObsidian's login while it still has none of its
// own (no userPasswordHash: the built-in default 123456 signs in). Run from /app
// before the server starts, with WebObsidian's own settings store and password
// hashing, so it is what Settings > change password would write.
import { hasCustomPassword, setUserPassword } from './server/dist/services/auth.js';

const say = (message) => console.log(`[panelalpha] webobsidian: ${message}`);

if (await hasCustomPassword()) {
  // A password chosen in WebObsidian (or seeded before): left as it is.
  process.exit(0);
}
await setUserPassword(process.env.WEBOBSIDIAN_LOGIN_PASSWORD);
say('the login is the password the engine generated; 123456 no longer signs in');
process.exit(0);
