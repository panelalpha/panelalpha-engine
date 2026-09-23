<?php

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\InstallFingerprint;
use App\Lib\Deploy\Telemetry\Telemetry;

/*
|--------------------------------------------------------------------------
| Deploy telemetry
|--------------------------------------------------------------------------
|
| Anonymous reports about deploys that failed, finished with warnings, or only
| succeeded because the pipeline recovered from something. They exist to make
| the detection rules, the framework recipes and the source app configs better: a
| failure that happens on thirty installs is a recipe bug, and without this
| nobody can tell it apart from one customer's broken repository.
|
| Reports carry no username, no repository token, no host address and no
| absolute path. There is no account and no key: installs identify themselves
| by a hash of machine facts that never leave the box.
|
| Turn the whole thing off with TELEMETRY_ENABLED=false in .env-core.
|
*/

return [

    'enabled' => (bool) env('TELEMETRY_ENABLED', true),

    /*
    | How much detail a report carries. Each tier is a superset of the one
    | below, and every tier is redacted.
    |
    |   0  metadata only — strategy, stage, failure rule, timings, plan limits
    |   1  + repository identity: the path in the clear for public repos,
    |        a salted hash for private ones
    |   2  + a redacted tail of the deploy log (the default)
    |
    | Tier 2 is what makes an unrecognised failure actionable — a rule that
    | matched nothing is exactly the case where the metadata says nothing.
    | Drop to 1 or 0 for installs whose customers will not accept build output
    | leaving the machine.
    */
    'tier' => (int) env('TELEMETRY_TIER', DeployReport::TIER_LOG),

    /*
    | The route that accepts a batch of reports, as `telemetry-ingest-spec.md`
    | declares it. The host it hangs off is config('monitoring.url') -- the
    | monitoring service, not Connect: Connect is an integration the engine
    | calls, monitoring is where it reports. Telemetry::endpoint() is the two
    | joined, and moving an install to a staging ingest is the host variable
    | alone.
    |
    | `/api/v1/events` is what the ingest actually serves. It is the shared
    | event route -- the same one the panel posts its own events to, which is
    | why it is spelled `events` rather than `reports`: a deploy report, a
    | recovered signal and a bug report all arrive there as events, told apart
    | by their `type`. The engine's earlier `/v1/reports` was never served by
    | anything and 404s, which the shipper reads as "no ingest here yet" and
    | holds its reports over -- so installs that never got this default lose
    | nothing, they just queue until they do.
    |
    | Emptying PANELALPHA_MONITORING still means "nowhere yet": the endpoint resolves
    | to '' and TelemetryShipper stops before it opens a socket, holding its
    | reports. That used to be the only safe default, because a host that does
    | not resolve made `telemetry:ship` fail DNS every five minutes and drop
    | each report after Spool::MAX_ATTEMPTS -- destroying the very reports it
    | existed to deliver. That is fixed where it belongs instead: a send that
    | never reached an ingest is deferred rather than counted against the
    | give-up limit, so pointing at an ingest before it serves costs nothing.
    */
    'reports_path' => (string) env('TELEMETRY_REPORTS_PATH', Telemetry::EVENTS_PATH),

    /*
    | Optional credential for the ingest endpoint, sent as an Authorization
    | header when set. Empty by default and simply omitted -- the engine has no
    | licence key and is not given one. Present so that an ingest which later
    | wants authentication is a config change rather than a code change.
    */
    'token' => (string) env('TELEMETRY_TOKEN', ''),

    'timeout' => (int) env('TELEMETRY_TIMEOUT', 15),

    /*
    | Reports per request. The receiving end is expected to accept a batch and
    | answer per report, so this is a size trade-off, not a semantic one.
    */
    'batch' => (int) env('TELEMETRY_BATCH', 25),

    /*
    |--------------------------------------------------------------------------
    | Source bundles
    |--------------------------------------------------------------------------
    |
    | Zip and upload the customer's application source alongside a failure
    | report. This is the most invasive thing the engine can send and it is the
    | one feature that is OFF unless an operator turns it on — a repository is
    | the customer's intellectual property, not diagnostic data, and consent to
    | send build logs is not consent to send source code.
    |
    | Modes:
    |   off          never. THE SHIPPED DEFAULT, and the only value an install
    |                should have unless someone deliberately changed it.
    |   unexplained  only failures no DeployFailureExplainer rule could name.
    |                THE SUPPORTED OPT-IN: turned on per install, for a specific
    |                investigation, with the hoster's agreement — and turned off
    |                again when that investigation is done.
    |   failed       every failed deploy. Discouraged: most failures already
    |                have a rule, so the source adds nothing to them, and this
    |                turns a diagnostic into a standing export of customer code.
    |
    | `partial` and `recovered` never produce a bundle whatever the mode.
    |
    | The archive excludes .git, dependency trees, build output, every .env
    | except the example variants, private keys, certificates and database
    | dumps — see SourceBundlePolicy. The zip is written during the deploy
    | (the account may be rolled back seconds later) and is uploaded only if
    | the ingest server explicitly asks for that report's source.
    |
    | See docs/08-telemetry/what-is-collected.md for how to switch this on for one investigation
    | and back off again.
    |
    */
    'source_bundle' => [

        'mode' => (string) env('TELEMETRY_SOURCE_BUNDLE', \App\Lib\Deploy\Telemetry\SourceBundlePolicy::MODE_OFF),

        // Busting any of these produces no bundle at all rather than a
        // truncated one: half a repository is a misleading bug report.
        'max_bytes' => (int) env('TELEMETRY_SOURCE_BUNDLE_MAX_BYTES', 25 * 1024 * 1024),
        'max_files' => (int) env('TELEMETRY_SOURCE_BUNDLE_MAX_FILES', 5000),
        'max_file_bytes' => (int) env('TELEMETRY_SOURCE_BUNDLE_MAX_FILE_BYTES', 2 * 1024 * 1024),

        'upload_timeout' => (int) env('TELEMETRY_SOURCE_BUNDLE_UPLOAD_TIMEOUT', 120),

    ],

    /*
    |--------------------------------------------------------------------------
    | Bug reports
    |--------------------------------------------------------------------------
    |
    | The half of this subsystem a person drives. Deploy reports are measured:
    | the engine watched something happen and wrote it down. A bug report is
    | told -- an operator says the engine is wrong about something, which is
    | exactly the class of problem no instrumentation notices, because the
    | engine believes it did the right thing.
    |
    | Same spool, same shipper, same batch, same `/api/v1/events` endpoint; the
    | events arrive as `support.bug_report` rather than `project.install.*`.
    |
    | Two differences from everything above are worth knowing before turning
    | this on:
    |
    |   - The body is free text somebody typed. It is redacted with the same
    |     rules as build output (tokens, credentials, home paths, emails), but
    |     it is still whatever a person chose to write, and no redactor can
    |     promise more than that about prose.
    |   - A report may carry a `contact` -- an email address the reporter typed
    |     so support can answer them. It is the only field in this whole
    |     subsystem that identifies a person, it is never filled in
    |     automatically, and it is absent unless somebody supplied one.
    |
    | Filing a report is refused outright when this or `enabled` above is off:
    | nothing would ever ship it, and accepting it would be a promise the
    | engine cannot keep.
    |
    */
    'bug_reports' => [

        'enabled' => (bool) env('TELEMETRY_BUG_REPORTS', true),

    ],

    'spool_dir' => (string) env('TELEMETRY_SPOOL_DIR', storage_path('app/telemetry/outbox')),

    /*
    | Where the derived install id is pinned. Host-side on purpose: it has to
    | survive an image rebuild and an engine update, or every update would look
    | like a brand new installation.
    */
    'pin_file' => (string) env('TELEMETRY_PIN_FILE', InstallFingerprint::PIN_FILE),

];
