#!/usr/bin/env python3
"""Find self-hostable open-source apps that are not yet in the supported-apps tracker.

Every run reads the *whole* current list of each source, so the first run is a gap
analysis and later runs surface only what those sources added since. "New" means:
not in the tracker snapshot (--tracker) and not already reported (--state).

Sources, in two kinds:
  catalogues  curated app stores for self-hosting; an app listed there already runs
              headless in a container and serves a web UI, which is our criterion
              awesome-selfhosted-data, Umbrel, Runtipi, CasaOS, Dokploy, YunoHost,
              LinuxServer.io, Unraid Community Apps, Coolify
  news feeds  where new projects are announced
              GitHub search (topic:self-hosted, recently created), Hacker News
              Show HN, selfh.st Self-Host Weekly, OpenAlternative, Lemmy
              selfhosted@lemmy.world

Each candidate is enriched from its GitHub page (stars, archived, topics,
description) and dropped when out of scope: archived, below --min-stars, or a
class the owner rejected (plugins/extensions, mail servers, libraries, awesome
lists, game servers, exporters). It is ranked by how many independent sources
list it, then by stars.

Usage:
  python3 scripts/tools/app-discover.py --tracker=tracker.json [--state=discover-state.json]
      [--out=candidates.json] [--min-stars=100] [--days=45] [--limit=60] [--only=src,src]

--tracker is a JSON array of {iid, title, repo} (the tracker's Repository lines).
Set GITHUB_TOKEN to enrich through the API instead of the HTML page.
"""

import argparse
import html
import io
import json
import math
import os
import re
import sys
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor

UA = "Mozilla/5.0 (panelalpha app-discover)"
FORGES = ("github.com", "gitlab.com", "codeberg.org", "git.sr.ht", "framagit.org",
          "gitea.com", "bitbucket.org", "salsa.debian.org", "invent.kde.org")
REPO_RE = re.compile(r"https?://(?:www\.)?(" + "|".join(re.escape(f) for f in FORGES)
                     + r")/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)")
NOT_OWNERS = {"sponsors", "orgs", "topics", "features", "marketplace", "about", "apps",
              "settings", "login", "collections", "trending", "explore", "users", "-"}

# Out of scope as a class (owner decisions), matched on topics, name and description.
REJECT_TOPICS = {
    "wordpress-plugin", "nextcloud-app", "home-assistant-integration", "hacs",
    "homeassistant-integration", "browser-extension", "chrome-extension",
    "firefox-extension", "vscode-extension", "obsidian-plugin", "plugin",
    "mail-server", "mailserver", "smtp-server", "email-server",
    "awesome", "awesome-list", "library", "sdk", "prometheus-exporter", "exporter",
    "game-server", "minecraft-server", "helm-chart", "kubernetes-operator", "dotfiles",
}
REJECT_NAME = re.compile(r"(^awesome-|-exporter$|-plugin$|-sdk$|^helm-|-helm$|-operator$|"
                         r"-extension$|-theme$|^dotfiles$|-cli$)", re.I)
REJECT_DESC = re.compile(r"\b(awesome list|curated list|a (?:wordpress|nextcloud|obsidian|"
                         r"home assistant|chrome|firefox|browser) (?:plugin|extension|app)|"
                         r"python library|go library|sdk for|mail server|smtp server)\b", re.I)


def http(url, timeout=60, raw=False):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    tok = os.environ.get("GITHUB_TOKEN")
    if tok and url.startswith("https://api.github.com/"):
        req.add_header("Authorization", "Bearer " + tok)
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                body = r.read()
                return (body if raw else body.decode("utf-8", "replace")), r.geturl()
        except urllib.error.HTTPError as e:
            if e.code in (404, 410, 451):
                return None, url
            if attempt == 2:
                raise
        except Exception:  # noqa: BLE001
            if attempt == 2:
                raise
        time.sleep(2 * (attempt + 1))
    return None, url


def norm(url):
    """host/owner/repo, lowercased, or None when the URL is not a repository."""
    if not url:
        return None
    m = REPO_RE.search(url.strip())
    if not m:
        return None
    host, owner, repo = m.group(1).lower(), m.group(2), m.group(3)
    if repo.endswith(".git"):
        repo = repo[:-4]
    if owner.lower() in NOT_OWNERS or not repo or repo in (".", ".."):
        return None
    return f"{host}/{owner}/{repo}".lower()


def name_key(s):
    s = re.sub(r"\(.*?\)", "", s or "").lower()
    return re.sub(r"[^a-z0-9]", "", s)


def tarball(slug):
    body, _ = http(f"https://codeload.github.com/{slug}/tar.gz/HEAD", timeout=300, raw=True)
    return tarfile.open(fileobj=io.BytesIO(body), mode="r:gz")


def members(tf, pattern):
    rx = re.compile(pattern)
    for m in tf.getmembers():
        if m.isfile() and rx.search(m.name):
            f = tf.extractfile(m)
            if f:
                yield m.name, f.read().decode("utf-8", "replace")


def yaml_scalar(text, key):
    m = re.search(rf"^\s*{key}:\s*['\"]?([^'\"\n#]+)", text, re.M)
    return m.group(1).strip() if m else None


def cand(repo, name, source, desc="", **extra):
    return {"repo": repo, "name": (name or "").strip(), "source": source,
            "description": (desc or "").strip()[:300], **extra}


# ---- catalogues -------------------------------------------------------------

def src_awesome_selfhosted(a):
    tf = tarball("awesome-selfhosted/awesome-selfhosted-data")
    nonfree = set()
    for _, t in members(tf, r"/licenses-nonfree\.yml$"):
        nonfree = set(re.findall(r"identifier:\s*(\S+)", t))
    out = []
    for _, t in members(tf, r"/software/[^/]+\.yml$"):
        lic = re.findall(r"^\s+-\s+(\S+)", t.split("platforms:")[0], re.M)
        if lic and all(x in nonfree for x in lic):
            continue
        if yaml_scalar(t, "archived") == "true":
            continue
        stars = yaml_scalar(t, "stargazers_count")
        out.append(cand(yaml_scalar(t, "source_code_url"), yaml_scalar(t, "name"),
                        "awesome-selfhosted", yaml_scalar(t, "description"),
                        stars=int(stars) if stars and stars.isdigit() else None))
    return out


def src_umbrel(a):
    tf = tarball("getumbrel/umbrel-apps")
    return [cand(yaml_scalar(t, "repo"), yaml_scalar(t, "name"), "umbrel", yaml_scalar(t, "tagline"))
            for _, t in members(tf, r"/umbrel-app\.yml$")]


def src_runtipi(a):
    tf = tarball("runtipi/runtipi-appstore")
    out = []
    for _, t in members(tf, r"/apps/[^/]+/config\.json$"):
        try:
            d = json.loads(t)
        except ValueError:
            continue
        out.append(cand(d.get("source"), d.get("name"), "runtipi", d.get("short_desc")))
    return out


def src_casaos(a):
    tf = tarball("IceWhaleTech/CasaOS-AppStore")
    out = []
    for n, t in members(tf, r"/Apps/[^/]+/docker-compose\.ya?ml$"):
        out.append(cand(yaml_scalar(t, "repo") or yaml_scalar(t, "project_url"),
                        n.split("/Apps/")[1].split("/")[0], "casaos"))
    return out


def src_dokploy(a):
    tf = tarball("Dokploy/templates")
    out = []
    for _, t in members(tf, r"/blueprints/[^/]+/meta\.json$"):
        try:
            d = json.loads(t)
        except ValueError:
            continue
        out.append(cand((d.get("links") or {}).get("github"), d.get("name"), "dokploy", d.get("description")))
    return out


def src_yunohost(a):
    body, _ = http("https://apps.yunohost.org/default/v3/apps.json", timeout=120)
    out = []
    for app in json.loads(body)["apps"].values():
        if app.get("state") not in (None, "working"):
            continue
        m = app.get("manifest") or {}
        up = m.get("upstream") or {}
        desc = m.get("description")
        out.append(cand(up.get("code"), m.get("name") or app.get("id"), "yunohost",
                        desc.get("en") if isinstance(desc, dict) else desc))
    return out


def src_linuxserver(a):
    body, _ = http("https://api.linuxserver.io/api/v1/images?include_config=false&include_deprecated=false")
    out = []
    for i in json.loads(body)["data"]["repositories"]["linuxserver"]:
        out.append(cand(i.get("project_url"), i.get("name"), "linuxserver", i.get("description")))
    return out


def src_unraid(a):
    body, _ = http("https://assets.ca.unraid.net/feed/applicationFeed.json", timeout=300)
    out = []
    for x in json.loads(body)["applist"]:
        if x.get("Plugin") or x.get("Blacklist") or x.get("Deprecated"):
            continue
        repo = x.get("Project") if norm(x.get("Project")) else ghcr_repo(x.get("Repository"))
        out.append(cand(repo, x.get("Name"), "unraid", html.unescape(x.get("Overview") or "")[:300]))
    return out


def ghcr_repo(image):
    """ghcr.io/owner/repo:tag -> https://github.com/owner/repo (GHCR images are named after the repo)."""
    m = re.match(r"ghcr\.io/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)", (image or "").strip())
    return f"https://github.com/{m.group(1)}/{m.group(2)}" if m else None


def src_coolify(a):
    body, _ = http("https://raw.githubusercontent.com/coollabsio/coolify/main/templates/service-templates.json")
    import base64
    out = []
    for name, t in json.loads(body).items():
        try:
            compose = base64.b64decode(t.get("compose") or "").decode("utf-8", "replace")
        except Exception:  # noqa: BLE001
            compose = ""
        repo = norm(t.get("documentation")) and t.get("documentation")
        if not repo:
            imgs = re.findall(r"image:\s*['\"]?(ghcr\.io/[^\s'\"]+)", compose)
            repo = ghcr_repo(imgs[0]) if imgs else None
        out.append(cand(repo, name, "coolify", t.get("slogan")))
    return out


# ---- news feeds -------------------------------------------------------------

def src_github_search(a):
    since = time.strftime("%Y-%m-%d", time.gmtime(time.time() - a.days * 86400))
    out = []
    for topic in ("self-hosted", "selfhosted", "self-hosting"):
        q = urllib.parse.quote(f"topic:{topic} created:>{since} stars:>={a.min_stars} archived:false")
        for page in (1, 2, 3):
            body, _ = http(f"https://api.github.com/search/repositories?q={q}&sort=stars&per_page=100&page={page}")
            items = json.loads(body).get("items", []) if body else []
            for r in items:
                out.append(cand(r["html_url"], r["name"], "github-search", r.get("description"),
                                stars=r["stargazers_count"], topics=r.get("topics", []),
                                archived=r.get("archived")))
            if len(items) < 100:
                break
            time.sleep(7)  # unauthenticated search is 10/min
    return out


def src_hn(a):
    since = int(time.time() - a.days * 86400)
    out = []
    for q in ("self-hosted", "selfhosted", "open source alternative", "open-source"):
        u = ("https://hn.algolia.com/api/v1/search_by_date?tags=show_hn&hitsPerPage=1000"
             f"&query={urllib.parse.quote(q)}&numericFilters=created_at_i>{since},points>=10")
        body, _ = http(u)
        for h in json.loads(body)["hits"]:
            repo = h.get("url") if norm(h.get("url")) else None
            if not repo:
                m = REPO_RE.search(h.get("story_text") or "")
                repo = m.group(0) if m else None
            out.append(cand(repo, re.sub(r"^Show HN:\s*", "", h.get("title") or "").split(" – ")[0].split(" - ")[0],
                            "hn-show", h.get("title"), hn_points=h.get("points")))
    return out


def src_selfhst(a):
    # The feed carries only excerpts; the repositories are on each weekly issue's page.
    body, _ = http("https://selfh.st/rss/")
    out, cutoff = [], time.strftime("%Y-%m-%d", time.gmtime(time.time() - a.days * 86400))
    for link in re.findall(r"<link>(https://selfh\.st/weekly/(\d{4}-\d{2}-\d{2})/)</link>", body or ""):
        if link[1] < cutoff:
            continue
        page, _ = http(link[0])
        for m in REPO_RE.finditer(page or ""):
            out.append(cand(m.group(0), m.group(3), "selfh.st", f"Self-Host Weekly {link[1]}"))
    return out


def src_openalternative(a):
    body, _ = http("https://openalternative.co/rss.xml")
    out = []
    for item in re.findall(r"<item>(.*?)</item>", body or "", re.S):
        title = re.sub(r"<!\[CDATA\[|\]\]>", "", (re.search(r"<title>(.*?)</title>", item, re.S) or [None, ""])[1]).strip()
        link = (re.search(r"<link>(.*?)</link>", item) or [None, ""])[1].strip()
        repos = [m.group(0) for m in REPO_RE.finditer(html.unescape(item))]
        if not repos and link:  # the listing page names the repository
            page, _ = http(link)
            repos = [m.group(0) for m in REPO_RE.finditer(page or "")
                     if norm(m.group(0)) and "openalternative" not in m.group(0).lower()][:1]
        for r in repos:
            out.append(cand(r, title, "openalternative"))
    return out


def src_lemmy(a):
    out = []
    for page in (1, 2, 3, 4, 5):
        body, _ = http("https://lemmy.world/api/v3/post/list?community_name=selfhosted@lemmy.world"
                       f"&sort=New&limit=50&page={page}")
        posts = json.loads(body).get("posts", []) if body else []
        cutoff = time.time() - a.days * 86400
        for p in posts:
            post = p["post"]
            text = (post.get("url") or "") + " " + (post.get("body") or "")
            for m in REPO_RE.finditer(text):
                out.append(cand(m.group(0), m.group(3), "lemmy-selfhosted", post.get("name")))
        if not posts or time.mktime(time.strptime(posts[-1]["post"]["published"][:19], "%Y-%m-%dT%H:%M:%S")) < cutoff:
            break
    return out


SOURCES = {
    "awesome-selfhosted": src_awesome_selfhosted, "umbrel": src_umbrel, "runtipi": src_runtipi,
    "casaos": src_casaos, "dokploy": src_dokploy, "yunohost": src_yunohost,
    "linuxserver": src_linuxserver, "unraid": src_unraid, "coolify": src_coolify,
    "github-search": src_github_search, "hn-show": src_hn, "selfh.st": src_selfhst,
    "openalternative": src_openalternative, "lemmy-selfhosted": src_lemmy,
}


# ---- enrichment -------------------------------------------------------------

def enrich(slug):
    """Stars, archived, topics, description and the canonical slug after renames."""
    host, owner, repo = slug.split("/", 2)
    if host != "github.com":
        return {"slug": slug, "stars": None, "archived": None, "topics": [], "desc": None, "exists": True}
    if os.environ.get("GITHUB_TOKEN"):
        body, _ = http(f"https://api.github.com/repos/{owner}/{repo}")
        if not body:
            return {"slug": slug, "exists": False}
        d = json.loads(body)
        return {"slug": norm(d["html_url"]), "stars": d["stargazers_count"], "archived": d["archived"],
                "topics": d.get("topics", []), "desc": d.get("description"), "fork": d.get("fork"),
                "pushed_at": d.get("pushed_at"), "exists": True}
    body, final = http(f"https://github.com/{owner}/{repo}")
    if not body:
        return {"slug": slug, "exists": False}
    m = re.search(r'id="repo-stars-counter-star"[^>]*title="([\d,]+)"', body)
    d = re.search(r'<meta name="description" content="([^"]*)"', body)
    return {"slug": norm(final) or slug, "stars": int(m.group(1).replace(",", "")) if m else None,
            "archived": "This repository was archived by the owner" in body,
            "topics": sorted(set(re.findall(r'href="/topics/([a-z0-9-]+)"', body))),
            "desc": html.unescape(d.group(1)) if d else None, "exists": True}


def rejected(c):
    topics = set(c.get("topics") or [])
    hit = topics & REJECT_TOPICS
    if hit:
        return "topic:" + ",".join(sorted(hit))
    name = c["slug"].rsplit("/", 1)[1]
    if REJECT_NAME.search(name):
        return "name"
    if REJECT_DESC.search(c.get("desc") or c.get("description") or ""):
        return "description"
    return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--tracker", required=True)
    ap.add_argument("--state", default="discover-state.json")
    ap.add_argument("--out", default="candidates.json")
    ap.add_argument("--min-stars", type=int, default=100)
    ap.add_argument("--days", type=int, default=45, help="look-back window for news feeds")
    ap.add_argument("--limit", type=int, default=0, help="keep the N best (0 = all)")
    ap.add_argument("--only", default="")
    ap.add_argument("--no-enrich", action="store_true")
    ap.add_argument("--reuse-raw", action="store_true", help="reuse the last fetch (<state>.raw.json)")
    ap.add_argument("--mark-reported", metavar="SLUGS_JSON",
                    help="record filed apps (JSON list of slugs) in --state and exit")
    a = ap.parse_args()

    if a.mark_reported:
        state = json.load(open(a.state)) if os.path.exists(a.state) else {"reported": {}, "enrich": {}}
        day = time.strftime("%Y-%m-%d", time.gmtime())
        for s in json.load(open(a.mark_reported)):
            state["reported"].setdefault(norm("https://" + s) or s, day)
        json.dump(state, open(a.state, "w"))
        print(f"{len(state['reported'])} reported", file=sys.stderr)
        return

    tracker = json.load(open(a.tracker))
    known = {norm(t.get("repo")) for t in tracker} - {None}
    known_names = {name_key(t.get("title")) for t in tracker}
    state = json.load(open(a.state)) if os.path.exists(a.state) else {"reported": {}, "enrich": {}}

    only = [s for s in a.only.split(",") if s] or list(SOURCES)
    raw, counts = [], {}

    def run(name):
        try:
            got = SOURCES[name](a)
            return name, got, None
        except Exception as e:  # noqa: BLE001
            return name, [], f"{type(e).__name__}: {e}"

    raw_path = a.state + ".raw.json"
    if a.reuse_raw and os.path.exists(raw_path):
        raw, counts = json.load(open(raw_path)).values()
    else:
        with ThreadPoolExecutor(6) as ex:
            for name, got, err in ex.map(run, only):
                counts[name] = {"listed": len(got), "with_repo": sum(1 for g in got if norm(g["repo"])), "error": err}
                print(f"{name:20} listed {len(got):5}  with repo {counts[name]['with_repo']:5}"
                      + (f"  ERROR {err}" if err else ""), file=sys.stderr)
                raw.extend(got)
        json.dump({"raw": raw, "counts": counts}, open(raw_path, "w"))

    by = {}
    for c in raw:
        s = norm(c["repo"])
        if not s:
            continue
        e = by.setdefault(s, {"slug": s, "names": set(), "sources": set(), "description": "", "stars": None,
                              "topics": set(), "hn_points": None})
        e["names"].add(c["name"])
        e["sources"].add(c["source"])
        e["description"] = e["description"] or c.get("description") or ""
        e["stars"] = c.get("stars") if c.get("stars") is not None else e["stars"]
        e["topics"] |= set(c.get("topics") or [])
        if c.get("hn_points"):
            e["hn_points"] = max(e["hn_points"] or 0, c["hn_points"])

    fresh = [e for s, e in by.items()
             if s not in known and s not in state["reported"]
             and not any(name_key(n) in known_names and len(name_key(n)) > 3 for n in e["names"])]
    print(f"unique repos {len(by)}, already tracked {sum(1 for s in by if s in known)}, "
          f"unseen {len(fresh)}", file=sys.stderr)

    if not a.no_enrich:
        todo = [e["slug"] for e in fresh if e["slug"] not in state["enrich"]
                or time.time() - state["enrich"][e["slug"]].get("_at", 0) > 7 * 86400]

        def one(s):
            try:
                r = enrich(s)
            except Exception as ex:  # noqa: BLE001
                r = {"slug": s, "exists": None, "error": str(ex)}
            r["_at"] = time.time()
            return s, r

        with ThreadPoolExecutor(8) as ex:
            for i, (s, r) in enumerate(ex.map(one, todo), 1):
                state["enrich"][s] = r
                if i % 100 == 0:
                    print(f"  enriched {i}/{len(todo)}", file=sys.stderr)

    keep, dropped = [], {}
    for e in fresh:
        info = state["enrich"].get(e["slug"], {})
        if info.get("exists") is False:
            dropped["gone"] = dropped.get("gone", 0) + 1
            continue
        slug = info.get("slug") or e["slug"]
        if slug != e["slug"] and (slug in known or slug in state["reported"]):
            dropped["renamed-tracked"] = dropped.get("renamed-tracked", 0) + 1
            continue
        c = {"slug": slug, "repo": "https://" + slug, "name": sorted(e["names"], key=len)[0] or slug.rsplit("/", 1)[1],
             "sources": sorted(e["sources"]), "stars": info.get("stars", e["stars"]),
             "archived": info.get("archived"), "topics": sorted(set(info.get("topics") or []) | e["topics"]),
             "desc": info.get("desc") or e["description"], "hn_points": e["hn_points"]}
        why = None
        if c["archived"]:
            why = "archived"
        elif info.get("fork"):
            why = "fork"
        elif c["stars"] is not None and c["stars"] < a.min_stars:
            why = "stars"
        else:
            why = rejected(c)
        if why:
            key = why.split(":")[0]
            dropped[key] = dropped.get(key, 0) + 1
            continue
        keep.append(c)

    # A renamed repo is listed under old and new names; merge them on the canonical slug.
    merged = {}
    for c in keep:
        m = merged.get(c["slug"])
        if m:
            m["sources"] = sorted(set(m["sources"]) | set(c["sources"]))
            m["topics"] = sorted(set(m["topics"]) | set(c["topics"]))
            m["hn_points"] = max(m["hn_points"] or 0, c["hn_points"] or 0) or None
        else:
            merged[c["slug"]] = c
    keep = list(merged.values())
    for c in keep:
        c["score"] = round(len(c["sources"]) * 3 + math.log10((c["stars"] or 0) + 1)
                           + (1 if c["hn_points"] and c["hn_points"] >= 50 else 0), 2)

    keep.sort(key=lambda c: (-c["score"], -(c["stars"] or 0)))
    if a.limit:
        keep = keep[:a.limit]
    json.dump({"generated": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "sources": counts,
               "dropped": dropped, "min_stars": a.min_stars, "candidates": keep}, open(a.out, "w"), indent=1)
    json.dump(state, open(a.state, "w"))
    print(f"kept {len(keep)}, dropped {dropped} -> {a.out}", file=sys.stderr)


if __name__ == "__main__":
    main()
