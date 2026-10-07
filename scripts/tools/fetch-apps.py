#!/usr/bin/env python3
"""Fetch unlabeled app-support issues from GitLab into scripts/tools/apps.json.

Issues are selected from the tracker project (PROJECT) in creation order;
those that already have a verdict in --outdir are skipped so the batch runner
works through the backlog one app at a time. `--reset` rebuilds the file from
scratch.

Usage:
  GITLAB_API=https://<gitlab>/api/v4 GITLAB_PROJECT=<group/project> GITLAB_TOKEN=TOKEN python3 scripts/tools/fetch-apps.py [--outdir=DIR] [--limit=N] [--reset]
"""

import argparse
import json
import os
import re
import sys
import urllib.request
import urllib.parse
import urllib.error

API = os.environ.get("GITLAB_API", "")
PROJECT = urllib.parse.quote(os.environ.get("GITLAB_PROJECT", ""), safe="")
TOKEN = os.environ.get("GITLAB_TOKEN", "")
VERDICT_LABELS = ("Supported", "Unsupported")


def gitlab(path, params=None):
    url = API + path
    if params:
        url += "?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url)
    req.add_header("Authorization", "Bearer " + TOKEN)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            body = r.read().decode()
            return r.status, json.loads(body) if body else None
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except ValueError:
            return e.code, raw
    except Exception as e:  # noqa: BLE001
        return 0, {"error": str(e)}


def normalise(title):
    """The app's directory name, exactly as the batch runner derives it.

    Both sides of the "already tested" comparison go through this. They used
    to disagree: the batch runner capped the slug at 11 characters while this
    file built it at full length, so 151 of 1186 apps were re-queued for a
    test they had already had. Keep the two in step.
    """
    return re.sub(r"[^a-z0-9]+", "", title.lower()) or "app"


def done_apps(outdir):
    """Set of finished apps in outdir, keyed by iid and by normalised slug.

    An iid is the stable identity — a title can be edited on the issue — so
    it is checked first. The slug is the fallback for records written before
    iids were stored (and is what --outdir/<slug>/ is named after).
    """
    done = set()
    if os.path.isdir(outdir):
        for d in os.listdir(outdir):
            p = os.path.join(outdir, d, "result.json")
            if not os.path.isfile(p):
                continue
            try:
                with open(p) as f:
                    rec = json.load(f)
            except ValueError:
                continue
            # A null verdict from an interrupted run is not a finished verdict.
            if not rec.get("verdict"):
                continue
            if rec.get("iid") is not None:
                done.add(str(rec["iid"]))
            if rec.get("title"):
                done.add(normalise(rec["title"]))
            done.add(normalise(rec.get("slug") or d))
    return done
    # NOTE: the batch runner skips apps by reading <outdir>/<slug>/result.json
    # itself, so this set only matters when regenerating scripts/tools/apps.json.


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--outdir", default="/tmp/app-support",
                    help="batch runner --outdir: used to skip apps with a verdict already")
    ap.add_argument("--limit", type=int, default=0,
                    help="stop after N apps (0 = no limit; fetches all issues so "
                         "the summary stays complete)")
    ap.add_argument("--reset", action="store_true",
                    help="ignore existing result records; rebuild the whole list")
    args = ap.parse_args()
    if not API or not TOKEN or not PROJECT:
        sys.exit("GITLAB_API, GITLAB_PROJECT and GITLAB_TOKEN required")

    done = set() if args.reset else done_apps(args.outdir)

    issues, page, per = [], 1, 100
    while True:
        st, batch = gitlab(f"/projects/{PROJECT}/issues",
                           {"state": "open", "per_page": per, "page": page,
                            "order_by": "created_at", "sort": "asc"})
        if st != 200 or not isinstance(batch, list):
            sys.exit(f"failed to list issues: HTTP {st} {str(batch)[:300]}")
        if not batch:
            break
        issues.extend(batch)
        if len(batch) < per:
            break
        page += 1

    apps, skipped, tested = [], 0, 0
    for it in issues:
        # A verdict label means the issue is settled and needs nothing; any
        # other label (Awaiting Fixes, Support In Progress, ...) means it IS
        # work, which is exactly what this list is for. Skipping every
        # labelled issue dropped the 33 apps still carrying a workflow label
        # while their result record said the app was retested and working.
        labels = set(it.get("labels") or [])
        if labels & set(VERDICT_LABELS):
            skipped += 1
            continue
        desc = it.get("description") or ""
        m = re.search(r"\*\*Repository:\*\*\s*(\S+)", desc)
        repo = m.group(1).strip() if m else ""
        title = it.get("title", "")
        if not repo:
            continue
        if str(it["iid"]) in done or normalise(title) in done:
            tested += 1
            continue
        apps.append({"iid": it["iid"], "title": title, "repo": repo,
                     "issue_url": it.get("web_url", "")})
        if args.limit and len(apps) >= args.limit:
            break

    with open("scripts/tools/apps.json", "w") as f:
        json.dump(apps, f, indent=1)
    print(f"{len(issues)} open issues read, {skipped} skipped (already labelled "
          f"Supported/Unsupported), {tested} already have a verdict in {args.outdir}, "
          f"{len(apps)} queued -> scripts/tools/apps.json")
    for a in apps[:20]:
        print(f"  #{a['iid']:>5} {a['title'][:40]:<40} {a['repo']}")


if __name__ == "__main__":
    main()