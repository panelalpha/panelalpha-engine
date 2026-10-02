export const Timeouts = {
  /** Default test timeout (3 minutes). */
  default: 180_000,

  /** Expect/assertion timeout (10 seconds). */
  expect: 10_000,

  /** Setup project timeout — WordPress installation can be slow (10 minutes). */
  setup: 600_000,

  /** Webserver change poll deadline — backup, install, reconfigure (30 minutes). */
  webserverChange: 1_800_000,

  /** Headroom for Playwright project timeout above WEBSERVER_CHANGE_TIMEOUT poll deadline. */
  webserverChangePlaywrightBuffer: 300_000,

  /** Individual API call timeout — log fetches etc. can be slow (60 seconds). */
  apiCall: 60_000,

  /** Git clone + image build + health probe for a live DinD deploy. */
  deploy: 600_000,

  /** Poll budget for one supported-app deploy. */
  supportedAppDeploy: 1_800_000,

  /**
   * Whole supported-app test: inspect retries, then the deploy poll, then the
   * health check and the external probe.
   */
  supportedApp: 2_700_000,
} as const;
