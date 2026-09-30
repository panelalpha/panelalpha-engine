// Node-RED settings for a hosted instance, mounted read-only as /data/settings.js.
// Differs from the image default: the editor and admin API need a login, and
// credentials are encrypted with a fixed secret from ~/.panelalpha/nodered. The
// login comes from the engine (~/.panelalpha/app-credentials.env).
const bcrypt = require('bcryptjs');

const user = process.env.NODE_RED_ADMIN_USER;
const pass = process.env.NODE_RED_ADMIN_PASSWORD;
const secret = process.env.NODE_RED_CREDENTIAL_SECRET;
if (!user || !pass || !secret) {
    // Refuse to start an unauthenticated editor.
    throw new Error('NODE_RED_ADMIN_USER, NODE_RED_ADMIN_PASSWORD and NODE_RED_CREDENTIAL_SECRET must be set');
}
// Keep the plaintext out of env.get() in function nodes and child processes.
delete process.env.NODE_RED_ADMIN_PASSWORD;
delete process.env.NODE_RED_CREDENTIAL_SECRET;

module.exports = {
    flowFile: 'flows.json',
    flowFilePretty: true,
    credentialSecret: secret,

    uiPort: 1880,

    // Editor and admin API (/flows, /nodes, /settings, /diagnostics, ...).
    adminAuth: {
        type: 'credentials',
        users: [{ username: user, password: bcrypt.hashSync(pass, 10), permissions: '*' }],
    },

    // HTTP In endpoints are the user's own public API, so they stay open like any
    // site's pages. To put them behind basic auth set, for example:
    // httpNodeAuth: { user: 'api', pass: '<bcrypt hash>' },

    diagnostics: { enabled: true, ui: true },
    runtimeState: { enabled: false, ui: false },

    logging: {
        console: { level: 'info', metrics: false, audit: false },
    },

    exportGlobalContextKeys: false,
    externalModules: {},

    editorTheme: {
        projects: { enabled: false },
        codeEditor: { lib: 'monaco' },
    },

    functionExternalModules: true,
    functionTimeout: 0,
    functionGlobalContext: {},
    debugMaxLength: 1000,
    mqttReconnectTime: 15000,
    serialReconnectTime: 15000,
};
