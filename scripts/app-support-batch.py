#!/usr/bin/env python3
"""Batch app-support checker for the PanelAlpha engine.

Reads an app list (JSON: [{iid, title, repo, ...}], e.g. scripts/apps.json)
and for each app:
  1. POST /api/source/inspect        -> resolve default branch + strategy (also proves cloneability)
  2. POST /api/projects              -> create account + deploy (async task)
  3. GET  /api/tasks/{id}/logs       -> poll until completed/failed, stream log to per-app file
  4. GET  /api/projects/{u}/app/health + fetch https://<domain>/  -> HTTP probe
  5. GET  /api/projects/{u}/deploy-log?build_timings=1 -> phases, build layers, cache ratio
  6. DELETE /api/projects/{u}        -> remove the account

Per-app artifacts in --outdir/<slug>/:
  result.json      result record (inspect, create, deploy, health, probe, timings)
  deploy.log       full deploy log lines
  deploy-log.json  engine deploy-log summary incl. the timings block
  REPORT.md        one self-contained Markdown report
plus <outdir>/summary.json aggregating every result record.

An app whose result.json already carries a verdict is skipped (resume);
--redo forces a retest. No GitLab write-back: labels are added by hand after
reviewing the reports.

Parallelism inside one instance:
  --parallel=N  run up to N apps concurrently (default 1). Each app still
               gets its own account, artifacts, and verdict. N should be at
               most the queue worker count (QUEUE_WORKERS in .env,
               default 8) — a higher N only deepens the queue, it does
               not finish apps faster.

Multiple instances are also possible, but only with disjoint slices:
  --shard=N/M  tests only apps whose index in the list is i%M == N-1,
               so M instances together cover the list exactly once
  --email=...  defaults to app-support-test@example.com; give each instance
               a distinct address so one instance's orphan cleanup can never
               delete another instance's in-flight account. With --parallel,
               cleanup runs once per process at end/start, never mid-run,
               so concurrent workers in the same process share one email.

Usage:
  PA_API_URL=https://HOST:2011/api PA_API_TOKEN=TOKEN \
    python3 scripts/app-support-batch.py --apps=scripts/apps.json [--only=slug] \
        [--limit=N] [--skip=slug] [--keep] [--redo] [--timeout=1800] \
        [--parallel=4] [--shard=1/4 --email=app-support-shard1@example.com]
"""

import argparse
import json
import os
import random
import re
import ssl
import string
import sys
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor

BASE = os.environ.get("PA_API_URL", "").rstrip("/")
TOKEN = os.environ.get("PA_API_TOKEN", "")
SSL_CTX = ssl._create_unverified_context()
DEFAULT_EMAIL = "app-support-test@example.com"

# Console output from concurrent apps is interleaved otherwise; a lock plus a
# per-app prefix keeps the stream readable without per-app log files.
PRINT_LOCK = threading.Lock()


def say(app, msg):
    with PRINT_LOCK:
        print(f"{msg[:190]}" if not app else f"{app:>12} {msg[:190]}", flush=True)


def api(method, path, body=None, timeout=120):
    url = BASE + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", "Bearer " + TOKEN)
    req.add_header("Accept", "application/json")
    if data:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=SSL_CTX) as r:
            raw = r.read().decode()
            try:
                return r.status, json.loads(raw)
            except ValueError:
                return r.status, raw
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except ValueError:
            return e.code, raw
    except Exception as e:  # noqa: BLE001 - network errors are results, not crashes
        return 0, {"error": str(e)}


def read_result(path):
    """A result record, or None. Used for the resume check and `previous`."""
    try:
        with open(path) as f:
            return json.load(f)
    except (OSError, ValueError):
        return None


def index_outdir(outdir):
    """iid -> directory already holding that app's artifacts.

    Resume is keyed on the iid, not on the slug, and this is what makes that
    work across a slug change. Runs made before the slug stopped being capped
    at 11 characters left directories named `roundupissu`; the runner now
    derives `roundupissuetracker`, so a slug-only lookup would miss the
    completed record and silently retest an app that was already done.
    """
    found = {}
    if os.path.isdir(outdir):
        for d in os.listdir(outdir):
            rec = read_result(os.path.join(outdir, d, "result.json"))
            if rec and rec.get("iid") is not None:
                found.setdefault(str(rec["iid"]), d)
    return found


# Verdicts that state no conclusion about the app and must not be resumed on.
#
# `deploy-timeout` is written when our own poll window closed while the task
# still read `running` — a host restart, an OOM kill, a stalled worker. Nothing
# was learned about the app, so treating it as a result is how a reboot
# permanently hides an app from the batch. The others are the same kind of
# non-answer: we never got far enough to observe the application.
UNFINISHED_VERDICTS = frozenset({
    "deploy-timeout",
    # A task the engine cancelled states nothing about the app. It means the
    # work stopped without a verdict -- the worker was killed mid-deploy
    # (`docker restart` of core, a host reboot, an OOM), or a person called
    # cancel. Recording it as `deploy-failed` reads as "this app does not work"
    # and hides it from every later run.
    #
    # Measured on 2.29.1.58: a queue container restart interrupted six deploys
    # mid-clone, and all six were recorded `deploy-failed` with
    # `deploy=cancelled` -- depay, mafl, pigallery2, servas, bittorrenttracker,
    # zenkocloudserver. None of them is a fact about its app.
    "deploy-cancelled",
    "inspect-failed",
    "create-failed",
    "script-error",
    "unreachable",
})


def is_unfinished(rec):
    """True when a recorded verdict is a missing answer rather than an answer."""
    return (rec.get("verdict") or "") in UNFINISHED_VERDICTS


def slugify(title):
    """Directory name for an app: its title, letters and digits only.

    Deliberately NOT truncated, and byte-identical to fetch-apps.py's slug.
    The two scripts compare this string to decide whether an app already has
    a verdict; an 11-char cap here re-queued 151 of 1186 apps that were
    already tested, because fetch-apps.py derived the full-length slug and
    never matched the directory. Titles are distinct (1186/1186), so no
    collision has to be resolved here.
    """
    return re.sub(r"[^a-z0-9]+", "", title.lower()) or "app"


def log_lines(task_id, after_id):
    st, body = api("GET", f"/tasks/{task_id}/logs?after_id={after_id}", timeout=60)
    data = body.get("data") if isinstance(body, dict) else None
    return (data or [], (body.get("meta") or {}).get("next_after_id", after_id) if isinstance(body, dict) else after_id, st)


def probe(url, timeout=60):
    req = urllib.request.Request(url)
    req.add_header("User-Agent", "Mozilla/5.0 (app-support-batch)")
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=SSL_CTX) as r:
            html = r.read(20000).decode("utf-8", "replace")
            m = re.search(r"<title[^>]*>(.*?)</title>", html, re.I | re.S)
            return r.status, (m.group(1).strip()[:120] if m else "")
    except urllib.error.HTTPError as e:
        return e.code, ""
    except Exception as e:  # noqa: BLE001
        return 0, str(e)[:120]


def write_report_md(rec, logtext, path):
    """One self-contained Markdown report per app."""
    ins = rec.get("inspect") or {}
    cre = rec.get("create") or {}
    dep = rec.get("deploy") or {}
    hea = rec.get("health") or {}
    htp = rec.get("http") or {}
    lines = [
        f"# {rec['title']} — app support test",
        "",
        f"- **Issue:** [#{rec['iid']} — {rec['title']}]({rec.get('issue_url', '')})"
        if rec.get("issue_url") else f"- **Issue:** #{rec['iid']} (panelalpha/playground/supported-apps)",
        f"- **Repo:** <{rec['repo']}>",
        f"- **Verdict:** `{rec.get('verdict')}`",
        f"- **Tested:** {time.strftime('%Y-%m-%d %H:%M:%S')}",
        "",
        "## Detection (POST /source/inspect)",
        "",
        f"- HTTP {ins.get('status')} in {ins.get('seconds')}s",
        f"- Branch: `{ins.get('branch')}` @ `{ins.get('commit')}`",
        f"- Strategy: `{ins.get('strategy')}` (platform `{ins.get('platform')}`), runtime node {ins.get('runtime')}",
        f"- Deployable: {ins.get('deployable')}, engine issue: {ins.get('issue')}",
        "",
        "## Deploy (POST /projects, async task)",
        "",
        f"- Account: `{cre.get('username')}` (requested `{cre.get('actual_username', cre.get('username'))}`)"
        if cre.get("actual_username") else f"- Account: `{cre.get('username')}`",
        f"- Domain: {cre.get('domain') or '(none)'}",
        f"- Task: {dep.get('task_id')}, status **{dep.get('status')}**, {dep.get('seconds')}s"
        + (", TIMED OUT" if dep.get("timed_out") else ""),
        "",
        "## Health check",
        "",
        f"- HTTP {hea.get('status')}: {json.dumps(hea.get('body'))[:400]}" if hea else "- not reached",
        "",
        "## Deploy timings (engine deploy-log)",
        "",
    ]
    tim = (rec.get("deploy_log") or {}).get("timings") or {}
    if tim:
        phases = tim.get("phases") or []
        build = tim.get("build") or {}
        lines += [
            f"- Total `{tim.get('total_seconds')}`s; cache hit ratio `{build.get('cache_hit_ratio')}`"
            f" ({build.get('cached_steps')}/{build.get('step_count')} layers cached)",
            f"- Phases: " + ", ".join(f"`{p['name']}` {p['seconds']}s" for p in phases),
        ]
        slow = build.get("slowest") or []
        for s in slow[:10]:
            cmd = str(s.get("command", ""))[:100]
            lines.append(f"- Layer {s.get('seconds')}s {'(cached)' if s.get('cached') else ''}: `{cmd}`")
    else:
        lines += ["_(no engine deploy-log — the failed deploy's rollback deletes "
                  "the account and the log with it)_" if dep.get("status") != "completed"
                  else "_(deploy log timings unavailable)_"]
    lines += [
        "",
        "## External probe",
        "",
        f"- https://{rec.get('domain', '')}/ → HTTP {htp.get('code')}"
        + (f", title: {htp.get('title')!r}" if htp.get("title") else ""),
        "",
        "## Failure evidence",
        "",
    ]
    # The engine's task.error is empty on this engine (the rollback has
    # already deleted the account and its per-line log), so the only copy of
    # what went wrong is the failure line the pipeline itself logged:
    # `Deploy failed: <first line>` followed by whatever the failing command
    # printed. Take that block rather than only lines that start with a known
    # error prefix -- a Yarn `➤ YN0000:` and the Python traceback under
    # `error: subprocess-exited-with-error` are the failure, and neither
    # begins with anything worth listing.
    #
    # Cut at the tail marker. The engine appends the last 150 lines of the
    # deploy log *after* the "Deploy failed:" line, under
    # `Last 150 lines of the deploy log (the rollback deletes it):` (see
    # App\Lib\Task\DeployLogTail), so a fixed 30-line window put 30 lines of
    # Maven `[INFO]` noise under a headline that a since-fixed selector had
    # got wrong. The block ends where the engine says it ends.
    all_lines = logtext.splitlines()
    evidence = []
    if rec.get("error"):
        evidence.append(str(rec["error"]))
    for i, l in enumerate(all_lines):
        if l.startswith("Deploy failed:"):
            block = []
            for line in all_lines[i:i + 40]:
                if line.startswith("Last ") and "of the deploy log" in line:
                    break
                block.append(line)
            evidence.extend(block)
            break
    if not evidence:
        evidence = [l for l in all_lines
                    if l.startswith(("npm error", "gyp ERR", "Error:", "error:",
                                     "ERR!", "fatal:", "ERROR", "Killed"))]
    if evidence:
        seen = set()
        uniq = [e for e in evidence if not (e in seen or seen.add(e))][:30]
        lines += ["```"] + uniq + ["```", ""]
    elif rec.get("verdict") in ("deploy-ok", "deploy-ok-probe-fail", "serving-placeholder",
                                "serving-missing_entry", "serving-error_page",
                                "serving-php_error", "serving-database_error",
                                "serving-directory_listing", "serving-unknown"):
        lines += ["_(none — the deploy itself completed; see the verdict above)_", ""]
    else:
        lines += ["_(no failure text captured — see the deploy log below)_", ""]
    lines += ["## Full deploy log", "", "```", logtext.strip() or "(empty)", "```", ""]
    with open(path, "w") as f:
        f.write("\n".join(lines))


def cleanup_orphans(email, outdir, keep):
    """Remove leftover accounts with this instance's test email.

    Runs once per process — before a batch (leftovers from a crashed run) and
    after (a failed deploy's rollback sometimes happens after our per-app
    DELETE, or the account was rolled back before we ever learned its name).
    Never runs mid-batch: with --parallel, sibling apps of this process share
    --email and an in-flight account must not be deleted.
    """
    if keep:
        return
    tst, lbody = api("GET", "/projects/all", timeout=120)
    for p in (lbody.get("data") if isinstance(lbody, dict) else None) or []:
        if (p.get("email") or "") == email and p.get("username"):
            dst, _ = api("DELETE", f"/projects/{p['username']}", timeout=900)
            say(None, f"[orphan-cleanup] {p['username']} -> {dst}")


def test_app(app, outdir, timeout_s, keep, email=DEFAULT_EMAIL):
    slug = slugify(app["title"])
    # A retest overwrites result.json in place. What the earlier run found is
    # kept under `previous` so a report can say "was X, now Y" rather than
    # losing it — a deploy-failed from an engine defect since fixed otherwise
    # reads as if the app had never been tested.
    appdir = os.path.join(outdir, slug)
    previous = read_result(os.path.join(appdir, "result.json"))
    os.makedirs(appdir, exist_ok=True)
    rec = {"iid": app["iid"], "title": app["title"], "repo": app["repo"],
           "issue_url": app.get("issue_url", ""), "slug": slug, "verdict": None,
           # The account was created under this email, so a later orphan
           # cleanup can be scoped to exactly this batch's leftovers.
           "email": email,
           "previous": {k: previous.get(k) for k in ("verdict", "tested_at", "error")}
           if previous else None}
    logfile = open(os.path.join(appdir, "deploy.log"), "w")
    important = []  # milestone lines (non-dim) for verdicts + the report

    def wl(line):
        logfile.write(line + "\n")
        logfile.flush()

    # 1. inspect (git hosts flake with 504s; retry before declaring the app untestable)
    t0 = time.time()
    st, body = 0, None
    for attempt in range(4):
        st, body = api("POST", "/source/inspect", {"source": app["repo"], "type": "git"}, timeout=600)
        msg = body.get("message", "") if isinstance(body, dict) else ""
        if st == 200 or "504" not in msg:
            break
        wl(f"[inspect] attempt {attempt+1} failed: {msg[:200]}")
        time.sleep(30)
    app_body = body.get("data", body) if isinstance(body, dict) else {}
    git_info = (app_body.get("source") or {}).get("git") or {}
    branch = git_info.get("branch")
    application = app_body.get("application") or {}
    rec["inspect"] = {
        "status": st,
        "branch": branch,
        "commit": git_info.get("commit"),
        "strategy": application.get("strategy"),
        "platform": application.get("platform"),
        "runtime": (application.get("toolchain") or [{}])[0].get("version") if application.get("toolchain") else None,
        "deployable": application.get("deployable"),
        "issue": application.get("issue"),
        "seconds": round(time.time() - t0, 1),
    }
    wl(f"[inspect] status={st} branch={branch} strategy={application.get('strategy')} "
       f"deployable={application.get('deployable')} issue={application.get('issue')}")
    if st != 200 or not branch:
        rec["verdict"] = "inspect-failed"
        msg = body.get("message") if isinstance(body, dict) else body
        rec["error"] = str(msg or body)[:500]
        # Surface the engine's own reason on stdout too: a 422 "could not
        # clone" is the whole result for this app, and burying it in the
        # report makes a batch run look like an instant no-op.
        say(slug, f"! inspect failed: HTTP {st} — {str(msg)[:200]}")
        logfile.close()
        write_report_md(rec, open(os.path.join(appdir, "deploy.log")).read(),
                        os.path.join(appdir, "REPORT.md"))
        return rec

    # 2. create (also retried on transient clone 504s — a failed deploy is rolled back server-side)
    suffix = "".join(random.choices(string.ascii_lowercase + string.digits, k=4))
    # Account names have a length limit the slug no longer respects; the cap
    # here is about the username, not about identifying the app. `slug` stays
    # full-length and is what every artifact and the resume check use.
    username = (slug[:11] + suffix)
    t0 = time.time()
    st, body = 0, None
    for attempt in range(3):
        st, body = api("POST", "/projects", {
            "email": email,
            "name": username,
            "git_repo": app["repo"],
            "git_branch": branch,
        }, timeout=300)
        msg = str(body.get("message", "")) if isinstance(body, dict) else ""
        if st in (200, 201, 202) or "504" not in msg:
            break
        wl(f"[create] attempt {attempt+1} failed: {msg[:200]}")
        time.sleep(30)
    rec["create"] = {"status": st, "username": username, "seconds": round(time.time() - t0, 1)}
    wl(f"[create] status={st} username={username}")
    task_id = None
    if isinstance(body, dict):
        d = body.get("data") or {}
        task_id = d.get("id") or (d.get("job") or {}).get("id")
        details = d.get("details") or {}
        # The server may normalise/truncate the username it provisions; every
        # later lookup must use the name the server reports, not the requested one.
        actual_username = details.get("username") or d.get("username")
        if actual_username:
            username = actual_username
            rec["create"]["actual_username"] = actual_username
        rec["create"]["domain"] = details.get("domain") or d.get("domain")
    if st not in (200, 201, 202) or not task_id:
        rec["verdict"] = "create-failed"
        msg = body.get("message") if isinstance(body, dict) else body
        rec["error"] = str(msg or body)[:500]
        say(slug, f"! create failed: HTTP {st} — {str(msg)[:200]}")
        logfile.close()
        write_report_md(rec, open(os.path.join(appdir, "deploy.log")).read(),
                        os.path.join(appdir, "REPORT.md"))
        return rec

    # 3. poll task
    after = 0
    t0 = time.time()
    status = "queued"
    while status not in ("completed", "failed", "cancelled") and time.time() - t0 < timeout_s:
        time.sleep(15)
        lines, next_after, st = log_lines(task_id, after)
        for line in lines:
            lv = line.get("level", "info")
            msg = line.get("log", "")
            wl(msg)
            if lv in ("info", "ok", "warn", "error"):
                important.append(msg)
            if lv in ("info", "ok", "warn", "error"):
                say(slug, f"{round(time.time()-t0):>5}s {msg[:150]}")
        after = next_after if next_after is not None else after
        tst, tbody = api("GET", f"/tasks/{task_id}", timeout=60)
        tdata = tbody.get("data", tbody) if isinstance(tbody, dict) else {}
        status = tdata.get("status", "unknown")
    # Our poll window closing is not a verdict about the app. Ask the engine
    # once more whether the job is really over: a deploy with a 20-minute
    # build legitimately needs longer than --timeout, and recording that as
    # `deploy-timeout` -- which resume then treats as a finished test -- both
    # misreports a working app and hides it from every later run. On
    # 2.29.1.58 a host reboot left three apps recorded that way and skipped.
    if status in ("running", "queued", "unknown"):
        for _ in range(3):
            time.sleep(20)
            tst, tbody = api("GET", f"/tasks/{task_id}", timeout=60)
            tdata = tbody.get("data", tbody) if isinstance(tbody, dict) else {}
            status = tdata.get("status", "unknown")
            if status in ("completed", "failed", "cancelled"):
                wl(f"[deploy] task settled to {status} after our poll window closed")
                break
    rec["deploy"] = {
        "task_id": task_id,
        "status": status,
        "seconds": round(time.time() - t0, 1),
        "timed_out": time.time() - t0 >= timeout_s,
    }
    wl(f"[deploy] status={status} seconds={rec['deploy']['seconds']}")
    say(slug, f"deploy finished: {status} in {rec['deploy']['seconds']}s")
    # The task's own error field carries the condensed failure message even
    # after the rollback deletes the per-line log. It has to be fetched
    # *before* it can be announced: the say() used to sit above this block and
    # read rec["deploy"]["error"], which is not set until the fetch below, so
    # the one-line failure summary never printed on any run.
    if status == "failed":
        tst, tbody = api("GET", f"/tasks/{task_id}", timeout=60)
        tdata = tbody.get("data", tbody) if isinstance(tbody, dict) else {}
        err = tdata.get("error") or ""
        if err:
            rec["deploy"]["error"] = str(err)[:4000]
            wl(str(err))
            say(slug, "! deploy failed — " + str(err).splitlines()[0][:200])

    # 4. verify
    if status == "completed":
        tst, pbody = api("GET", f"/projects/{username}", timeout=60)
        pdata = pbody.get("data", pbody) if isinstance(pbody, dict) else {}
        domain = (pdata.get("domain") or (pdata.get("details") or {}).get("domain")
                  or rec["create"].get("domain") or "")
        rec["domain"] = domain
        hst, hbody = api("GET", f"/projects/{username}/app/health", timeout=180)
        rec["health"] = {"status": hst, "body": hbody if isinstance(hbody, dict) else str(hbody)[:300]}
        wl(f"[health] status={hst} body={json.dumps(rec['health']['body'])[:400]}")
        if domain:
            code, title = probe("https://" + domain + "/")
            rec["http"] = {"code": code, "title": title}
            wl(f"[http] GET https://{domain}/ -> {code} title={title!r}")
        else:
            rec["http"] = {"code": 0, "title": "no domain"}
        ok_health = rec["health"]["status"] == 200
        code = rec["http"]["code"]
        # `serving` is the engine's one-word answer to "is the running thing
        # actually the app": "ok", or what is being served instead
        # (e.g. "placeholder" — the engine's own page, not the application).
        # An HTTP 200 on a placeholder must never count as app support.
        hdata = rec["health"]["body"].get("data") if isinstance(rec["health"]["body"], dict) else None
        serving = (hdata or {}).get("serving") if isinstance(hdata, dict) else None
        rec["serving"] = serving
        if not ok_health:
            rec["verdict"] = "health-fail"
        elif serving not in (None, "ok"):
            rec["verdict"] = "serving-" + str(serving)
            say(slug, f"! site answers but serves the engine's '{serving}', not the app")
        else:
            rec["verdict"] = ("deploy-ok" if code in (200, 301, 302)
                              else "deploy-ok-probe-fail")

    # A deploy that finishes successfully already contains the engine's own
    # external probe (`Reachable: ... answered 200 through the webserver`) and
    # its health check, so trust those even when our second curl could not run
    # (e.g. DNS not yet propagated) and the server normalised the username.
    if status == "completed":
        # Save the engine's own deploy-log summary (id, stages, timings with
        # build_timings=1 -> phases + per-layer build breakdown + cache ratio).
        lst, lbody = api("GET", f"/projects/{username}/deploy-log?build_timings=1", timeout=120)
        ldata = lbody.get("data") if isinstance(lbody, dict) else {}
        if lst == 200 and ldata:
            rec["deploy_log"] = {k: ldata.get(k) for k in
                                 ("id", "status", "stage", "stages", "error",
                                  "started_at", "finished_at", "timings")}
            with open(os.path.join(appdir, "deploy-log.json"), "w") as f:
                json.dump(ldata, f, indent=2)
            wl("[deploy-log] saved deploy-log.json with timings")
        else:
            # The account exists (the deploy completed) but has no readable
            # log -- record the HTTP status so a 404 is distinguishable from a
            # permission error when a report looks thin.
            rec["deploy_log_unavailable"] = {"status": lst}
            wl(f"[deploy-log] status={lst} (not saved)")
        # Only a probe-side gap may be healed by the engine's own external
        # probe: a placeholder/health verdict is about what is being served,
        # and a 200 through the webserver proves reachability, not the app.
        if rec.get("verdict") == "deploy-ok-probe-fail":
            m = re.search(r"Reachable: (\S+) answered (\d+) through the webserver", "\n".join(important))
            if m and m.group(2) == "200":
                rec["verdict"] = "deploy-ok"
                rec["http"] = {"code": 200, "title": "engine-probe", "url": m.group(1)}
    elif rec.get("verdict") is None:
        rec["verdict"] = "deploy-failed"
        if rec["deploy"].get("timed_out"):
            rec["verdict"] = "deploy-timeout"
        # A cancelled task is not a failed deploy. The engine's reconciler sets
        # `cancelled` for work that stopped without a verdict, so treating it as
        # a conclusion about the app both misreports a working app and exempts
        # it from every later run. {@see UNFINISHED_VERDICTS}
        elif rec["deploy"].get("status") == "cancelled":
            rec["verdict"] = "deploy-cancelled"
        # A failed deploy is rolled back, account and deploy-log included, so
        # there is nothing for step 5 to fetch. Say so explicitly: the 304 of
        # 305 deploy-failed apps that have no deploy-log.json otherwise look
        # like a fetch that was skipped rather than one that could not succeed.
        rec["deploy_log_unavailable"] = {"status": None, "reason": "account rolled back"}

    # 5. delete this app's account. Orphan cleanup (accounts left behind by
    # failed deploys) is NOT done here: with --parallel it would race the
    # other in-flight apps of this same process sharing --email. It runs once
    # per process, before and after the batch (see cleanup_orphans()).
    if not keep:
        dst, dbody = api("DELETE", f"/projects/{username}", timeout=900)
        rec["delete"] = {"status": dst}
        wl(f"[delete] status={dst}")

    logfile.close()
    write_report_md(rec, open(os.path.join(appdir, "deploy.log")).read(),
                    os.path.join(appdir, "REPORT.md"))
    return rec


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--apps", default="apps.json")
    ap.add_argument("--outdir", default="/tmp/app-support",
                    help="where per-app artifacts live; also the resume "
                         "source, read as <outdir>/<slug>/result.json")
    ap.add_argument("--only", default="")
    ap.add_argument("--limit", type=int, default=0,
                    help="test at most N apps in this run (sequential, one at a time)")
    ap.add_argument("--skip", default="")
    ap.add_argument("--keep", action="store_true",
                    help="leave the deployed account on the server (skips orphan cleanup too)")
    ap.add_argument("--redo", action="store_true",
                    help="retest apps that already have a verdict in --outdir")
    ap.add_argument("--timeout", type=int, default=1800)
    ap.add_argument("--shard", default="",
                    help="run only this shard of the list, e.g. --shard=1/4 "
                         "runs items 1, 5, 9, ... (index %% M == N-1). Shards "
                         "partition the list, so M instances cover it exactly once")
    ap.add_argument("--parallel", type=int, default=1,
                    help="test up to N apps concurrently (default 1). Keep at or "
                         "below the engine's QUEUE_WORKERS; above it, "
                         "apps just wait in the deploy queue")
    ap.add_argument("--email", default=DEFAULT_EMAIL,
                    help="test account email (orphan cleanup deletes only "
                         "accounts with this email; use a distinct one per "
                         "instance when running several processes)")
    args = ap.parse_args()
    if not BASE or not TOKEN:
        sys.exit("PA_API_URL and PA_API_TOKEN required")
    if args.parallel < 1:
        sys.exit("--parallel must be >= 1")
    shard_n = shard_m = None
    if args.shard:
        m = re.fullmatch(r"(\d+)/(\d+)", args.shard.strip())
        if not m:
            sys.exit("--shard must look like N/M (e.g. 1/4)")
        shard_n, shard_m = int(m.group(1)), int(m.group(2))
        if not (1 <= shard_n <= shard_m):
            sys.exit("--shard N must be 1..M")
    os.makedirs(args.outdir, exist_ok=True)
    with open(args.apps) as f:
        apps = json.load(f)
    only = {s.strip().lower() for s in args.only.split(",") if s.strip()}
    skip = {s.strip().lower() for s in args.skip.split(",") if s.strip()}

    # ---- selection: filter once, up front ----
    # Resume looks an app up by iid first (index_outdir), falling back to the
    # slug directory, so records written under the old capped slug still count.
    known = index_outdir(args.outdir)
    todo, resumed = [], 0
    for idx, app in enumerate(apps):
        slug = slugify(app["title"])
        if only and slug not in only:
            continue
        if slug in skip:
            say(None, f"--- SKIP {slug}")
            continue
        if shard_n is not None and idx % shard_m != shard_n - 1:
            continue
        # Resume: an app with a recorded verdict is done unless --redo.
        if not args.redo:
            old = None
            if known.get(str(app["iid"])):
                old = read_result(os.path.join(args.outdir, known[str(app["iid"])],
                                               "result.json"))
            if old is None:
                old = read_result(os.path.join(args.outdir, slug, "result.json"))
            if old and old.get("verdict"):
                if is_unfinished(old):
                    # `deploy-timeout` (and anything else that means "we never
                    # learned the answer") is not a result, it is a missing
                    # one. Resuming on it makes the absence permanent: a host
                    # reboot at 15:34 left clyocloud/notthre/depay recorded as
                    # deploy-timeout, and the next run skipped all three as
                    # already tested. Retest them.
                    say(None, f"--- RETEST {slug} (unfinished: {old['verdict']})")
                else:
                    say(None, f"--- DONE {slug} ({old['verdict']})")
                    resumed += 1
                    continue
        todo.append((idx, app))
        if args.limit and len(todo) >= args.limit:
            break

    if resumed:
        say(None, f"({resumed} app(s) already had a verdict — skipped; use --redo to retest)")
    say(None, f"{len(todo)} app(s) to test, --parallel={args.parallel}")

    # ---- execution ----
    def run_one(item):
        _, app = item
        slug = slugify(app["title"])
        say(slug, f"===== #{app['iid']} {app['title']} ({app['repo']})")
        try:
            rec = test_app(app, args.outdir, args.timeout, args.keep, args.email)
        except Exception as e:  # noqa: BLE001
            prev = read_result(os.path.join(args.outdir, slug, "result.json"))
            rec = {"iid": app["iid"], "title": app["title"], "repo": app["repo"], "slug": slug,
                   "email": args.email, "verdict": "script-error", "error": str(e)[:500],
                   "previous": {k: prev.get(k) for k in ("verdict", "tested_at", "error")}
                   if prev else None}
            os.makedirs(os.path.join(args.outdir, slug), exist_ok=True)
            write_report_md(rec, "", os.path.join(args.outdir, slug, "REPORT.md"))
        rec["tested_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
        with open(os.path.join(args.outdir, slug, "result.json"), "w") as f:
            json.dump(rec, f, indent=2)
        say(slug, f"===== verdict: {rec['verdict']} =====")
        return rec

    # Pre-run cleanup removes leftovers of crashed runs; the post-run pass
    # catches accounts rolled back after our per-app DELETE already ran.
    cleanup_orphans(args.email, args.outdir, args.keep)
    if args.parallel <= 1:
        for item in todo:
            run_one(item)
    else:
        with ThreadPoolExecutor(max_workers=args.parallel) as pool:
            for _ in pool.map(run_one, todo):
                pass
    cleanup_orphans(args.email, args.outdir, args.keep)

    # ---- summary ----
    recs = []
    for d in sorted(os.listdir(args.outdir)):
        p = os.path.join(args.outdir, d, "result.json")
        if os.path.isfile(p):
            with open(p) as f:
                recs.append(json.load(f))
    with open(os.path.join(args.outdir, "summary.json"), "w") as f:
        json.dump(recs, f, indent=2)
    print("\n===== SUMMARY =====")
    for r in recs:
        d = r.get("deploy") or {}
        h = r.get("http") or {}
        # An inspect-failed app never reached a deploy, so there is no status
        # or duration to print; render those as "-" rather than the literal
        # "None", which read as a value in the summary table.
        secs = d.get("seconds")
        secs = f"{secs}s" if secs is not None else "-"
        print(f"{r['iid']}\t{r['title'][:30]}\t{r['verdict']}\tdeploy={d.get('status') or '-'}\t{secs}\thttp={h.get('code')}")
    if shard_n is not None:
        print(f"\n(shard {shard_n}/{shard_m}, email {args.email}: summary above mixes all shards; "
              f"this instance tested {len(todo)} app(s))")


if __name__ == "__main__":
    main()