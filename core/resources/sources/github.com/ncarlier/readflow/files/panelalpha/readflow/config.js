// readflow UI runtime configuration -- PanelAlpha override.
//
// This is readflow's own upstream default config.js, bind-mounted read-only over
// /var/local/html/config.js. readflow regenerates this file on boot from
// http.public_url, but that value is forced to the built-in default
// http://localhost:8080 (the config merge cannot leave it empty) and the
// per-account public domain is unknown at deploy time -- a generated config
// would point the SPA at localhost:8080. With apiBaseUrl empty, the SPA calls
// the API on document.location.origin (see ui/src/helpers/fetchAPI.ts), so it
// works on whatever domain the account ends up with. authority empty selects
// basic-auth mode (the UI resolves it to `none`), matching READFLOW_AUTHN_METHOD.
const __READFLOW_CONFIG__ = {
    apiBaseUrl: '',
    authority: '',
    client_id: '',
}
