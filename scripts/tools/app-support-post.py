#!/usr/bin/env python3
"""Post batch test results to GitLab: an evidence note plus a verdict label.

Companion to scripts/tools/app-support-batch.py. That script deliberately writes
nothing to GitLab — it only produces /tmp/app-support/<slug>/result.json — so
this one is the only thing that closes the loop: it reads those result records
and, for every issue not already carrying a verdict label, posts the evidence
and adds Supported or Unsupported. An issue still labelled Awaiting Fixes is
exactly what this is meant to act on, so it is not skipped.

Label rules:
  supported    -> Supported
  unsupported  -> Unsupported
  rejected     -> Rejected   (proven unfixable from the engine's side; terminal)
  infra-failed -> no label, "retest needed" note (webserver restart-loop window)
  unclear      -> no label, evidence note only

Supported and Unsupported are provisional -- a later retest replaces one with
the other. Rejected is not: it is the triage's final answer for an app that
cannot work here, and only --force or --relabel moves it.

Runs through the supervisor MCP proxy (GitLabDeveloper-* tools) so it acts with
the same GitLab identity/permissions the assistant already has, and keeps a
resume file so a re-run never double-posts.

The target list may be given three ways:
  positional FILE   a JSON list of {iid, category, slug, verdict}
  --iids 8,50,338   explicit issues, category/verdict read from result.json
  (neither)         every supported/unsupported target in the classification

Usage:
  python3 scripts/tools/app-support-post.py [targets.json] [--iids=8,50] [--dry-run]
"""

import argparse
import json
import os
import re
import subprocess
import sys
import time

MCP_URL = os.environ.get("MCP_URL", "")
# No default: this is a GitLab-writing credential, and one committed here would
# be readable by anyone who clones the branch. Unset is a hard stop, not a
# fallback -- a silent 401 halfway through a batch is worse than not starting.
MCP_AUTH = os.environ.get("MCP_AUTH", "")
PROJECT = os.environ.get("GITLAB_PROJECT", "")

OUT = os.environ.get("APP_SUPPORT_OUT", "/tmp/app-support")
CLASSIFICATION = os.environ.get(
    "APP_SUPPORT_CLASSIFICATION", "/tmp/app-support-classification.json")
STATE = os.environ.get("APP_SUPPORT_STATE", "/tmp/app-support-post-state.json")

LABEL_SUPPORTED = "Supported"
LABEL_UNSUPPORTED = "Unsupported"
# A third verdict, and the one the triage ends on. "Unsupported" means the app
# did not deploy *when it was tested* -- the honest state for a run against a
# defect that has since been fixed, so those get retested. "Rejected" is the
# final answer for an app proven unfixable from the engine's side: it needs a
# JDK the image does not ship, a system library the build cannot install, or it
# is a library/workspace with no server to run. Label 46817 already exists on
# the project; it is deliberately NOT in OPPOSITE_VERDICT, because a rejected
# app is not relabelled by a later retest without a human saying so.
LABEL_REJECTED = "Rejected"
# Workflow labels a verdict note supersedes. An issue that was labelled
# Awaiting Fixes while a defect was open keeps that label forever unless it is
# removed when the fix lands -- several issues ended up claiming both
# "Awaiting Fixes" and the verdict, which reads as work that is still waiting.
SUPERSEDED_LABELS = {"Awaiting Fixes": 46810, "Support In Progress": 46868}
# The two verdicts are mutually exclusive, and adding one must remove the
# other. A relabelled issue otherwise keeps claiming both -- one came back
# from a retest as Supported and still carried the Unsupported its first
# (pre-fix) run posted, which reads as a contradiction to anyone filtering by
# label. Only ever removed in favour of the opposite verdict, never on its own.
OPPOSITE_VERDICT = {LABEL_SUPPORTED: LABEL_UNSUPPORTED,
                    LABEL_UNSUPPORTED: LABEL_SUPPORTED}
LABEL_IDS = {LABEL_SUPPORTED: 46866, LABEL_UNSUPPORTED: 46867,
             LABEL_REJECTED: 46817, **SUPERSEDED_LABELS}
# A retest settles an issue that carried either provisional verdict. A Rejected
# one is terminal: it means somebody decided the app cannot work here, and a
# later run must not quietly overwrite that with a verdict of its own.
PROVISIONAL_LABELS = {LABEL_SUPPORTED, LABEL_UNSUPPORTED}

VERDICT_SUMMARY = {
    "deploy-ok": "deployed and answered",
    "deploy-ok-probe-fail": "deployed; engine domain check saw the app answer",
    "serving-placeholder": "container answers but serves the engine placeholder page, not the app",
    "serving-missing_entry": "deployed but the webserver has no front page to serve (library-style repo)",
    "serving-error_page": "application is up but answers an error page",
    "serving-php_error": "PHP fatal error on the front page",
    "serving-database_error": "database error page",
    "serving-directory_listing": "serves a directory listing instead of an app",
    "serving-unknown": "application keeps restarting / never answered on any port",
    "deploy-failed": "deploy task failed",
    "deploy-timeout": "deploy did not finish within the 1800s limit",
    "inspect-failed": "source inspect failed (repository not cloneable)",
    "create-failed": "project creation failed (username validation)",
    "script-error": "batch runner error",
    "health-fail": "health check failed",
}


# ---------------------------------------------------------------- MCP plumbing
_session = None


def auth_config():
    """The Authorization header, as a curl config file read from stdin.

    Not `-H "Authorization: ..."`: an argv is world-readable in `ps` for as
    long as the process lives, so every call would show the token to any local
    user. `-K -` keeps it on a pipe instead.
    """
    if not MCP_URL or not PROJECT:
        sys.exit("MCP_URL and GITLAB_PROJECT must be set. Export them first.")
    if not MCP_AUTH:
        sys.exit("MCP_AUTH is not set. Export it first, e.g.\n"
                 "  export MCP_AUTH='Bearer <token>'")
    return f'header = "Authorization: {MCP_AUTH}"\n'


def mcp_session():
    global _session
    if _session:
        return _session
    out = subprocess.run(
        ["curl", "-s", "-K", "-", "-D", "-", MCP_URL,
         "-H", "Content-Type: application/json",
         "-H", "Accept: application/json, text/event-stream",
         "-X", "POST",
         "-d", json.dumps({"jsonrpc": "2.0", "id": 1, "method": "initialize",
                           "params": {"protocolVersion": "2025-03-26",
                                      "capabilities": {},
                                      "clientInfo": {"name": "app-support-poster",
                                                     "version": "1.0"}}})],
        input=auth_config(), capture_output=True, text=True, timeout=120).stdout
    m = re.search(r"(?im)^mcp-session-id:\s*(\S+)", out)
    if not m:
        raise RuntimeError("no session id from MCP initialize:\n" + out[:500])
    _session = m.group(1)
    subprocess.run(
        ["curl", "-s", "-K", "-", "-o", "/dev/null", MCP_URL,
         "-H", f"Mcp-Session-Id: {_session}",
         "-H", "Content-Type: application/json",
         "-H", "Accept: application/json, text/event-stream",
         "-X", "POST",
         "-d", json.dumps({"jsonrpc": "2.0",
                           "method": "notifications/initialized", "params": {}})],
        input=auth_config(), text=True, timeout=120)
    return _session


def mcp_call(tool, args, expect_content=True):
    payload = {"jsonrpc": "2.0", "id": 2, "method": "tools/call",
               "params": {"name": tool, "arguments": args}}
    for _ in range(3):
        r = subprocess.run(
            ["curl", "-s", "-K", "-", "--max-time", "180", MCP_URL,
             "-H", f"Mcp-Session-Id: {mcp_session()}",
             "-H", "Content-Type: application/json",
             "-H", "Accept: application/json, text/event-stream",
             "-X", "POST",
             "-d", json.dumps(payload)],
            input=auth_config(), capture_output=True, text=True, timeout=200)
        m = re.search(r"(?m)^data: (.*)$", r.stdout, re.S)
        if not m:
            if "session" in r.stdout.lower():
                global _session
                _session = None
                continue
            raise RuntimeError(f"no SSE data frame from MCP: {r.stdout[:500]}")
        data = json.loads(m.group(1))
        if "error" in data:
            raise RuntimeError(f"{tool}: {data['error']}")
        res = data["result"]
        if expect_content and res.get("isError"):
            raise RuntimeError(f"{tool}: {res.get('content')}")
        return json.loads(res["content"][0]["text"]) if expect_content else res
    raise RuntimeError("unreachable")


# --------------------------------------------------------------- GitLab helpers
def fetch_all_issue_labels():
    """iid -> list of label titles, all opened issues, via GraphQL pages."""
    labels = {}
    query = """query($after: String) {
  project(fullPath: "%s") {
    issues(state: opened, first: 100, after: $after) {
      pageInfo { hasNextPage endCursor }
      nodes { iid labels { nodes { title } } }
    }
  }
}""" % PROJECT
    after = None
    while True:
        data = mcp_call("GitLabDeveloper-execute_graphql",
                        {"query": query, "variables": {"after": after} if after else {}})
        node = data["data"]["project"]["issues"]
        for n in node["nodes"]:
            labels[n["iid"]] = [l["title"] for l in n["labels"]["nodes"]]
        if not node["pageInfo"]["hasNextPage"]:
            break
        after = node["pageInfo"]["endCursor"]
    return labels


def set_label(iid, label, remove=()):
    """Add the verdict label and drop the labels it supersedes.

    The caller passes the workflow labels the issue currently carries; the
    opposite verdict is added here, so no caller can forget it. An issue can
    only ever hold one of the two.
    """
    # What the label being added replaces. Supported and Unsupported drop each
    # other. Rejected drops *both* -- it is a decision that the app cannot work
    # here, so leaving the provisional verdict beside it reads as a
    # contradiction (an issue claiming both Unsupported and Rejected), which is
    # exactly what the first posting of this label did. Asking for a label with
    # no opposite must not KeyError either.
    # Rejected is narrow -- the repo is wrong, it ships no installer, or the
    # installer cannot work -- so finding a way to support such an app later
    # means moving it back off Rejected, and a provisional verdict is the way
    # that happens. It supersedes both provisional labels; they supersede it.
    if label == LABEL_REJECTED:
        remove = set(remove) | PROVISIONAL_LABELS
    elif label in PROVISIONAL_LABELS:
        remove = set(remove) | (PROVISIONAL_LABELS - {label}) | {LABEL_REJECTED}
    elif label in OPPOSITE_VERDICT:
        remove = set(remove) | {OPPOSITE_VERDICT[label]}
    # Never ask to remove the label being added: GitLab applies the removal
    # last, so an id in both lists leaves the issue with *no* verdict -- which
    # is what a --relabel re-run did, clearing Rejected off all nine issues it
    # had just labelled.
    remove = {l for l in remove if l != label}
    add = "".join(f'"gid://gitlab/ProjectLabel/{LABEL_IDS[label]}"')
    rm = ", ".join(f'"gid://gitlab/ProjectLabel/{LABEL_IDS[l]}"' for l in remove if l in LABEL_IDS)
    q = ('mutation { updateIssue(input: {projectPath: "%s", iid: "%s", '
         'addLabelIds: [%s]%s}) { issue { iid } errors } }')
    data = mcp_call("GitLabDeveloper-execute_graphql",
                    {"query": q % (PROJECT, iid, add,
                                   f", removeLabelIds: [{rm}]" if rm else "")})
    return data["data"]["updateIssue"]["errors"]


def post_note(iid, body):
    return mcp_call("GitLabDeveloper-create_issue_note",
                    {"project_id": PROJECT, "issue_iid": iid, "body": body})


# ------------------------------------------------------------------- evidence
def result_for(slug):
    return json.load(open(os.path.join(OUT, slug, "result.json")))


def evidence(rec):
    ins = rec.get("inspect") or {}
    cre = rec.get("create") or {}
    dep = rec.get("deploy") or {}
    health = rec.get("health") or {}
    hdata = ((health.get("body") or {}).get("data")
             if isinstance(health.get("body"), dict) else None) or {}
    http = rec.get("http") or {}
    # The timings block is what the batch runner saved from the engine's
    # deploy-log. It lives under `deploy_log`, NOT at the top level — reading
    # `rec["timings"]` silently produced no phase line for all 807 successful
    # deploys, so every posted note was missing the build breakdown.
    tim = (rec.get("deploy_log") or {}).get("timings") or rec.get("timings") or {}
    lines = [f"- Detection: HTTP {ins.get('status')}, branch `{ins.get('branch')}` "
             f"@ `{str(ins.get('commit'))[:8]}`, strategy `{ins.get('strategy')}` "
             f"(platform `{ins.get('platform')}`), runtime `{ins.get('runtime')}` "
             f"({ins.get('seconds')}s)"]
    if cre:
        lines.append(f"- Create: HTTP {cre.get('status')}, account `{cre.get('username')}`"
                     + (f" (normalized `{cre.get('actual_username')}`)"
                        if cre.get("actual_username") else "")
                     + f", domain `{rec.get('domain') or cre.get('domain')}`")
    if dep:
        lines.append(f"- Deploy task {dep.get('task_id')}: **{dep.get('status')}** "
                     f"in {dep.get('seconds')}s"
                     + (" (timeout after 1800s)" if dep.get("timed_out") else ""))
    if hdata:
        ports = hdata.get("ports") or []
        ps = ", ".join(f"{p.get('port')}: HTTP {p.get('http_code')}" for p in ports[:3])
        lines.append(f"- Health check: HTTP {health.get('status')}, "
                     f"`healthy={hdata.get('healthy')}`, `serving={hdata.get('serving')}`"
                     + (", ports: " + ps if ps else ""))
        dom = hdata.get("domain") or {}
        lines.append(f"- Engine domain check: `{dom.get('verdict')}` "
                     f"HTTP {dom.get('http_code')} ({str(dom.get('detail'))[:100]})")
    if http:
        lines.append(f"- External probe: HTTP {http.get('code')}, "
                     f"title: '{str(http.get('title'))[:80]}'")
    if tim:
        phases = tim.get("phases") or []
        ph = ", ".join(f"{p['name']} {p['seconds']}s" for p in phases[:6])
        build = tim.get("build") or {}
        line = f"- Deploy phases: {ph}" if ph else "- Deploy phases: (none recorded)"
        if build.get("cache_hit_ratio") is not None:
            line += (f", cache hit ratio {build.get('cache_hit_ratio')} "
                     f"({build.get('cached_steps')}/{build.get('step_count')} layers cached)")
        lines.append(line)
        for step in (build.get("slowest") or [])[:5]:
            lines.append(f"  - layer {step.get('seconds')}s "
                         f"{'(cached)' if step.get('cached') else ''}: "
                         f"`{str(step.get('command'))[:90]}`")
    fail_line = None
    lp = os.path.join(OUT, rec["slug"], "deploy.log")
    if os.path.exists(lp):
        txt = open(lp, errors="replace").read()
        # Where the stored summary is a decoy -- a Maven entrypoint's
        # `mkdir /root` or apt's permission error, both of which print to
        # stderr ahead of the real cause and are harmless -- the deploy log
        # already holds the truth, and the engine's explainer can name it.
        # Prefer that over quoting the decoy a second time onto the issue.
        if re.search(r"mkdir: cannot create directory|List directory /var/lib/apt/lists", txt):
            # The engine's own explainer, so the issue text and the panel agree
            # word for word rather than the poster inventing a second opinion.
            # The autoloader is looked up next to this script first -- the
            # poster runs from a checkout -- and falls back to the path inside
            # the engine container.
            root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
            for autoload in (os.path.join(root, "core", "vendor", "autoload.php"),
                             "/var/www/html/vendor/autoload.php"):
                if not os.path.exists(autoload):
                    continue
                try:
                    import subprocess
                    php = (f"require '{autoload}';"
                           "use App\\Lib\\Deploy\\DeployLog\\DeployFailureExplainer;"
                           "echo (string) DeployFailureExplainer::explain(file_get_contents($argv[1]));")
                    out = subprocess.run(["php", "-r", php, lp], capture_output=True,
                                         text=True, timeout=60).stdout.strip()
                except Exception:  # noqa: BLE001 - a nicer summary is optional
                    continue
                if out:
                    fail_line = out[:300]
                    break
        m = re.findall(r"Deploy failed: (.+)", txt) if not fail_line else None
        if m:
            fail_line = m[0][:220]
        elif fail_line:
            pass
        else:
            m = re.search(r"Check failed: (.+)", txt)
            if m:
                fail_line = "Health: " + m.group(1)[:220]
    return lines, fail_line


def note_body(rec, lines, fail_line, extra=None):
    v = rec["verdict"]
    body = [f"## Automated batch test — verdict: `{v}`",
            f"{VERDICT_SUMMARY.get(v, v)}.",
            "",
            "### Test evidence",
            *lines]
    if fail_line:
        body += ["", "### Failure detail", "```", fail_line, "```"]
    if extra:
        body += ["", extra]
    body += ["", "---",
             "Tested by the automated app-support batch.",
             f"Full per-app artifacts: `{OUT}/{rec['slug']}/` "
             "(REPORT.md, deploy.log, deploy-log.json, result.json)."]
    return "\n".join(body)


# ------------------------------------------------------------------------ main
def load_targets(args):
    if args.targets:
        targets = json.load(open(args.targets))
    elif args.iids:
        targets = []
        for iid in [s.strip() for s in args.iids.split(",") if s.strip()]:
            targets.append({"iid": iid})
    else:
        cls = json.load(open(CLASSIFICATION))
        targets = [{"iid": iid_s, "category": v["category"],
                    "slug": v["slug"], "verdict": v["verdict"]}
                   for iid_s, v in cls.items()
                   if v["category"] in ("supported", "unsupported")]
        targets.sort(key=lambda t: int(t["iid"]), reverse=True)
    # Fill in whatever the caller left out from the result record itself.
    for t in targets:
        rec = None
        if t.get("slug"):
            rec = result_for(t["slug"])
        else:
            for d in os.listdir(OUT):
                p = os.path.join(OUT, d, "result.json")
                if os.path.isfile(p):
                    r = json.load(open(p))
                    if str(r.get("iid")) == str(t["iid"]):
                        rec = r
                        break
        if rec is None:
            raise SystemExit(f"#{t['iid']}: no result.json in {OUT}")
        t["slug"] = rec["slug"]
        t["verdict"] = rec["verdict"]
        t.setdefault("category", "supported" if rec["verdict"] == "deploy-ok"
                     or rec["verdict"].startswith("deploy-ok") else "unsupported")
    return targets


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("targets", nargs="?", help="JSON list of targets (optional)")
    ap.add_argument("--iids", default="", help="comma-separated issue iids")
    ap.add_argument("--dry-run", action="store_true",
                    help="print what would be posted, change nothing")
    ap.add_argument("--force", action="store_true",
                    help="post even though the issue already carries a verdict label")
    ap.add_argument("--relabel", action="store_true",
                    help="already-posted issues only: still drop the workflow "
                         "labels the verdict supersedes (no second note)")
    args = ap.parse_args()

    targets = load_targets(args)
    live = fetch_all_issue_labels()
    state = json.load(open(STATE)) if os.path.exists(STATE) else {}

    done = skipped = failed = already = 0
    for t in targets:
        iid = str(t["iid"])
        key = f"{iid}:{t['category']}"
        if state.get(key):
            # Already posted. --relabel still tidies the labels: those runs
            # happened before superseded workflow labels were removed, so the
            # issue is sitting on both "Awaiting Fixes" and its verdict.
            if not args.relabel:
                already += 1
                continue
        cur = live.get(iid)
        if cur is None:
            print(f"#{iid}: issue not found among opened issues — skip")
            skipped += 1
            continue
        # Only a verdict label means the issue is settled. Any other label
        # (Awaiting Fixes, Support In Progress, ...) is precisely the state a
        # retest note is meant to change, so skipping on "has any label at
        # all" would refuse to post the result to every issue still in the
        # workflow — including all five Awaiting Fixes apps.
        if set(cur) & {LABEL_SUPPORTED, LABEL_UNSUPPORTED, LABEL_REJECTED} and not (args.force or args.relabel):
            skipped += 1
            state[key] = "skipped-labeled"
            continue
        rec = result_for(t["slug"])
        lines, fail_line = evidence(rec)
        label = {"supported": LABEL_SUPPORTED,
                 "rejected": LABEL_REJECTED}.get(t["category"], LABEL_UNSUPPORTED)
        body = note_body(rec, lines, fail_line, t.get("extra"))
        if args.dry_run:
            print(f"\n{'='*72}\n#{iid} {t['slug']} -> {label}\n{body}")
            continue
        try:
            # Note first: if labelling fails the evidence is still on the
            # issue, and the resume key stays unset so a re-run retries both.
            # --relabel is the tidy-up path: the note is already there, so it
            # only moves the labels.
            if not (args.relabel and state.get(key)):
                post_note(iid, body)
            superseded = sorted(set(cur) & (set(SUPERSEDED_LABELS) | {LABEL_REJECTED}))
            err = set_label(iid, label, remove=superseded)
            if err:
                raise RuntimeError(str(err))
            state[key] = "posted"
            done += 1
            print(f"#{iid} {t['slug']}: {label} + note"
                  + (f" (dropped {', '.join(superseded)})" if superseded else ""))
        except Exception as e:  # noqa: BLE001
            print(f"#{iid} {t['slug']}: FAILED — {e}")
            failed += 1
        json.dump(state, open(STATE, "w"), indent=1)
        time.sleep(0.4)

    print(f"\ndone: {done} posted, {skipped} skipped, {failed} failed, {already} already done")


if __name__ == "__main__":
    main()
