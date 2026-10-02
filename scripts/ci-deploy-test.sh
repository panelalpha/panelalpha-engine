#!/usr/bin/env bash
# CI deployment smoke test for PanelAlpha Engine.
#
# Deploys a git repo to a throwaway hosting account through Artisan, verifies
# the deploy succeeded and the app responds over HTTP, then deletes the account.
# Exit code reflects pass/fail, so this is meant to be dropped straight into a
# CI job.
#
# Every engine call goes through `pae-artisan`'s own project commands
# (project:create, project:deploy:check, project:delete, ...), run inside the
# core container - no token and no network hop, so this must run on the
# engine host.
#
# Usage:
#   ci-deploy-test.sh --git https://github.com/you/app.git [options]
#
# Requires: pae-artisan (see docs/06-commands/pae-cli.md), jq, curl.

set -euo pipefail

# Colors for output (matches scripts/container-manager.sh)
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1" >&2; }

usage() {
    cat <<'EOF'
Usage: ci-deploy-test.sh --git URL [options]

Required:
  --git URL              Git repository to deploy

Options:
  --branch NAME           Git branch (default: repo default branch)
  --git-token TOKEN        Git HTTPS token for private repos (default: $GIT_TOKEN)
  --username NAME          Hosting username (default: generated, e.g. ci-1734567890)
  --domain DOMAIN           Primary domain (default: USERNAME.<CI_TEST_DOMAIN_SUFFIX or ci.test.local>)
  --email EMAIL             Account email (default: admin@localhost.localdomain)
  --env KEY=VALUE           Env var injected into the deployed app; repeatable
  --verify-path PATH        HTTP path checked after deploy (default: /)
  --verify-status RANGE     Accepted HTTP status range "MIN-MAX" (default: 200-399)
  --verify-timeout SECONDS  Max time to wait for a successful HTTP check (default: 90)
  --verify-interval SECONDS Delay between HTTP check retries (default: 5)
  --verify-cmd CMD          Extra shell command to run after deploy; USERNAME and
                             DOMAIN are exported to its environment. Non-zero = fail.
  --skip-http-verify        Skip the built-in HTTP check (e.g. non-web apps)
  --allow-partial            Don't fail when the engine reports deployment_status=partial
                             (app container started but exited non-zero)
  --allow-unrecognized        Don't fail when the engine couldn't detect how to run the
                             project (deploy_strategy=fallback) and served a placeholder
                             page instead — see docs/07-supported-projects/how-detection-works.md
  --skip-doctor             Skip the engine reachability pre-flight check
  --keep                    Don't delete the hosting account afterwards (debugging)
  --dump-log-json PATH       On any failure, write a JSON bundle (full deploy log lines +
                             account details) to PATH — feed this to an automated fix/retry
                             pipeline. See AGENTS.md.
  --pae PATH                 pae-artisan wrapper to use (default: $PAE_BIN or "pae-artisan")
  -h, --help                 Show this help

Examples:
  ci-deploy-test.sh --git https://github.com/you/app.git --branch "$CI_COMMIT_BRANCH"

  ci-deploy-test.sh --git git@github.com:you/app.git --git-token "$GIT_TOKEN" \
      --env APP_ENV=testing --verify-path /health \
      --verify-cmd 'pae-artisan domain:wp-cli "$DOMAIN" core is-installed'

  ci-deploy-test.sh --git https://github.com/you/app.git \
      --dump-log-json build/deploy-failure.json
EOF
}

# --- defaults -----------------------------------------------------------
GIT_REPO=""
BRANCH=""
GIT_TOKEN="${GIT_TOKEN:-}"
USERNAME=""
DOMAIN=""
EMAIL=""
ENV_VARS=()
VERIFY_PATH="/"
VERIFY_STATUS="200-399"
VERIFY_TIMEOUT=90
VERIFY_INTERVAL=5
VERIFY_CMD=""
SKIP_HTTP_VERIFY=0
ALLOW_PARTIAL=0
ALLOW_UNRECOGNIZED=0
SKIP_DOCTOR=0
KEEP=0
DUMP_LOG_JSON=""
DEPLOY_STREAM_LOG=""
PAE="${PAE_BIN:-pae-artisan}"

# --- arg parsing ----------------------------------------------------------
while true; do
    case "${1:-}" in
    --git)
        GIT_REPO="$2"
        shift 2
        ;;
    --branch)
        BRANCH="$2"
        shift 2
        ;;
    --git-token)
        GIT_TOKEN="$2"
        shift 2
        ;;
    --username)
        USERNAME="$2"
        shift 2
        ;;
    --domain)
        DOMAIN="$2"
        shift 2
        ;;
    --email)
        EMAIL="$2"
        shift 2
        ;;
    --env)
        ENV_VARS+=("$2")
        shift 2
        ;;
    --verify-path)
        VERIFY_PATH="$2"
        shift 2
        ;;
    --verify-status)
        VERIFY_STATUS="$2"
        shift 2
        ;;
    --verify-timeout)
        VERIFY_TIMEOUT="$2"
        shift 2
        ;;
    --verify-interval)
        VERIFY_INTERVAL="$2"
        shift 2
        ;;
    --verify-cmd)
        VERIFY_CMD="$2"
        shift 2
        ;;
    --skip-http-verify)
        SKIP_HTTP_VERIFY=1
        shift
        ;;
    --allow-partial)
        ALLOW_PARTIAL=1
        shift
        ;;
    --allow-unrecognized)
        ALLOW_UNRECOGNIZED=1
        shift
        ;;
    --skip-doctor)
        SKIP_DOCTOR=1
        shift
        ;;
    --keep)
        KEEP=1
        shift
        ;;
    --dump-log-json)
        DUMP_LOG_JSON="$2"
        shift 2
        ;;
    --pae)
        PAE="$2"
        shift 2
        ;;
    -h | --help)
        usage
        exit 0
        ;;
    "")
        break
        ;;
    *)
        log_error "Unknown option '$1'"
        usage
        exit 1
        ;;
    esac
done

if [[ -z "$GIT_REPO" ]]; then
    log_error "--git is required"
    usage
    exit 1
fi

command -v "$PAE" >/dev/null 2>&1 || {
    log_error "'$PAE' not found on PATH. Install it (scripts/pae-command.sh register) or pass --pae PATH."
    exit 1
}
command -v jq >/dev/null 2>&1 || {
    log_error "'jq' is required but not found on PATH."
    exit 1
}
command -v curl >/dev/null 2>&1 || {
    log_error "'curl' is required but not found on PATH."
    exit 1
}

# The API validates usernames as letters and digits only — no hyphen here.
[[ -n "$USERNAME" ]] || USERNAME="ci$(date +%s)"
[[ -n "$DOMAIN" ]] || DOMAIN="${USERNAME}.${CI_TEST_DOMAIN_SUFFIX:-ci.test.local}"

# --- pre-flight -----------------------------------------------------------
if [[ "$SKIP_DOCTOR" -eq 0 ]]; then
    log_info "Checking engine reachability (system:database:test)..."
    if ! "$PAE" system:database:test >/dev/null; then
        log_error "The engine did not answer — is the core container up? (Skip with --skip-doctor.)"
        exit 1
    fi
fi

# --- failure diagnostics -----------------------------------------------------
# Best-effort JSON fetch: prints the command's stdout if it succeeds and is
# valid JSON, otherwise prints "null" — never aborts the (set -e) script.
fetch_json_or_null() {
    local output
    if output="$("$@" 2>/dev/null)" && [[ -n "$output" ]] && echo "$output" | jq -e . >/dev/null 2>&1; then
        echo "$output"
    else
        echo 'null'
    fi
}

# Bundles everything an automated fix/retry pipeline needs into one JSON file:
# - deploy_log_stream: the live deploy log `project:create` wrote to stderr
#   (tee'd to DEPLOY_STREAM_LOG below). This is the ONLY reliable source for a
#   hard failure: on a hard failure the engine deletes the account (including
#   its DB row) as part of rollback (UserController::runDeployPipeline), so by
#   the time cleanup() runs, GET /projects/{username}/deploy-log 404s — the
#   account it belongs to is already gone. The stream was captured live,
#   before that deletion happened, so it still has the real error.
# - deploy_log_api / app: the structured deploy-log, and the account details
#   `project:create --json` printed on its own stdout when it succeeded (see
#   APP_JSON below). These are complete for partial/fallback outcomes, where
#   the account is deliberately kept — app is null on hard failures, because
#   there was never a successful create to capture it from.
dump_diagnostics() {
    local out="$1"
    log_info "Writing failure diagnostics to $out"

    local out_dir
    out_dir="$(dirname -- "$out")"
    mkdir -p "$out_dir" 2>/dev/null || true

    local stream_text deploy_log app
    stream_text="$(cat "$DEPLOY_STREAM_LOG" 2>/dev/null || true)"
    # The deploy log is a file on disk (DeployLogger), so unlike the account
    # row it survives the rollback that deletes the account on a hard failure.
    deploy_log="$(fetch_json_or_null "$PAE" project:deploy:log "$USERNAME" --raw --tail 500)"
    app="${APP_JSON:-null}"
    echo "$app" | jq -e . >/dev/null 2>&1 || app='null'

    if ! jq -n \
        --arg username "$USERNAME" \
        --arg domain "$DOMAIN" \
        --arg git_repo "$GIT_REPO" \
        --arg branch "$BRANCH" \
        --arg generated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg deploy_log_stream "$stream_text" \
        --argjson deploy_log_api "$deploy_log" \
        --argjson app "$app" \
        '{
            username: $username,
            domain: $domain,
            git_repo: $git_repo,
            branch: $branch,
            generated_at: $generated_at,
            deploy_log_stream: $deploy_log_stream,
            deploy_log_api: $deploy_log_api,
            app: $app
        }' >"$out" 2>/dev/null; then
        log_warn "Could not write diagnostics to $out"
    fi
}

# --- cleanup on exit --------------------------------------------------------
cleanup() {
    local exit_code=$?

    if [[ $exit_code -ne 0 && -n "$DUMP_LOG_JSON" ]]; then
        dump_diagnostics "$DUMP_LOG_JSON"
    fi

    if [[ "$KEEP" -eq 1 ]]; then
        log_warn "Keeping hosting account '$USERNAME' (--keep). Delete manually with: $PAE project:delete $USERNAME --force"
    else
        log_info "Cleaning up hosting account '$USERNAME'..."
        "$PAE" project:delete "$USERNAME" --force >/dev/null 2>&1 \
            || log_warn "Could not delete '$USERNAME' — it may not have been created, or needs manual cleanup."
    fi

    rm -f "$DEPLOY_STREAM_LOG"
    if [[ $exit_code -eq 0 ]]; then
        log_info "CI deploy test PASSED (username=$USERNAME, domain=$DOMAIN)"
    else
        log_error "CI deploy test FAILED (username=$USERNAME, domain=$DOMAIN, exit=$exit_code)"
    fi
    exit "$exit_code"
}
trap cleanup EXIT

# --- deploy -----------------------------------------------------------------
log_info "Deploying '$GIT_REPO' (branch: ${BRANCH:-default}) as '$USERNAME' -> https://$DOMAIN"

create_args=(project:create --json
    --project="$USERNAME"
    --domain="$DOMAIN"
    --email="${EMAIL:-admin@localhost.localdomain}"
    --repo="$GIT_REPO")
[[ -n "$BRANCH" ]] && create_args+=(--branch="$BRANCH")
[[ -n "$GIT_TOKEN" ]] && create_args+=(--git-token="$GIT_TOKEN")
for kv in "${ENV_VARS[@]:-}"; do
    [[ -n "$kv" ]] || continue
    if [[ "$kv" != *=* ]]; then
        log_error "Invalid --env '$kv' (expected KEY=VALUE)"
        exit 1
    fi
    create_args+=(--env-var="$kv")
done

# `project:create` runs the deploy synchronously and exits non-zero on a hard
# failure. NOTE: a "partial" deploy (container started but exited non-zero) and
# an "unrecognized project" deploy (engine falls back to a placeholder page, see
# UserController::runDeployPipeline / DeployStrategy::applyFallbackCompose) both
# still exit 0 — they are caught explicitly below.
#
# `--json` sends the created project's JSON to stdout and the live deploy log
# to stderr instead, so the two can be told apart: the log is tee'd to
# DEPLOY_STREAM_LOG for the diagnostics bundle (and still shown on this
# terminal), the JSON is captured in APP_JSON for the outcome check below. On a
# hard failure the engine deletes the account as part of its rollback, so the
# account row is gone by the time cleanup() runs; the deploy log survives it,
# because DeployLogger writes files (read back with project:deploy:log).
DEPLOY_STREAM_LOG="$(mktemp)"
CREATE_JSON_FILE="$(mktemp)"
APP_JSON=""
if ! "$PAE" "${create_args[@]}" >"$CREATE_JSON_FILE" 2> >(tee "$DEPLOY_STREAM_LOG" >&2); then
    log_error "Deploy failed. Last deploy-log entries:"
    tail -n 40 "$DEPLOY_STREAM_LOG" || true
    "$PAE" project:deploy:log "$USERNAME" --tail 40 2>/dev/null || true
    exit 1
fi
APP_JSON="$(cat "$CREATE_JSON_FILE")"
rm -f "$CREATE_JSON_FILE"

# --- verify: deploy actually recognized and ran the project -------------------
# project:create only exits non-zero on status=failed/cancelled. A "partial"
# deploy (app container failed to start) or a "fallback" strategy (engine
# couldn't detect how to run the project — no compose/Dockerfile/manifest, or
# the Railpack build itself failed) both still report success and exit 0.
# Both are only visible in the account's stored details, so check them here.
log_info "Checking deploy outcome (deployment_status / deploy_strategy)..."
app_json="$APP_JSON"
deployment_status="$(echo "$app_json" | jq -r '(.data // .).details.deployment_status // "unknown"')"
deploy_strategy="$(echo "$app_json" | jq -r '(.data // .).details.deploy_strategy // "unknown"')"
deploy_label="$(echo "$app_json" | jq -r '(.data // .).details.deploy_label // "unknown"')"

if [[ "$deployment_status" == "partial" ]]; then
    warnings="$(echo "$app_json" | jq -r '(.data // .).details.deployment_warnings // [] | join("; ")')"
    if [[ "$ALLOW_PARTIAL" -eq 1 ]]; then
        log_warn "Deploy finished as 'partial' (--allow-partial set): $warnings"
    else
        log_error "Deploy finished as 'partial': $warnings"
        exit 1
    fi
fi

if [[ "$deploy_strategy" == "fallback" || "$deploy_label" == "Unknown" ]]; then
    if [[ "$ALLOW_UNRECOGNIZED" -eq 1 ]]; then
        log_warn "Engine could not recognize the project (strategy=fallback, --allow-unrecognized set); it deployed a placeholder page instead of the app."
    else
        log_error "Engine could not recognize the project (strategy=fallback, label=Unknown)."
        log_error "It deployed a placeholder 'Project not configured' page instead of the app — deploy log won't show this as an error."
        log_error "Check your repo for a docker-compose.yml, Dockerfile, or a recognized package manifest (package.json/composer.json/etc)."
        exit 1
    fi
fi
log_info "Deploy outcome OK: status=$deployment_status strategy=$deploy_strategy label=$deploy_label"

# --- verify: the application actually answers ---------------------------------
log_info "Checking application health..."
health_json="$("$PAE" project:deploy:check "$USERNAME" --json)"
if echo "$health_json" | jq -e 'has("error")' >/dev/null 2>&1; then
    log_error "Could not check '$USERNAME': $(echo "$health_json" | jq -r '.error')"
    exit 1
fi
if ! echo "$health_json" | jq -e '.healthy != false' >/dev/null 2>&1; then
    log_error "'$USERNAME' did not answer on every published port."
    echo "$health_json" | jq '.'
    exit 1
fi
log_info "Application health OK: $(echo "$health_json" | jq -r '.healthy')"

# --- verify: HTTP check -------------------------------------------------------
if [[ "$SKIP_HTTP_VERIFY" -eq 0 ]]; then
    engine_ip="$("$PAE" settings:get default_ipv4 2>/dev/null | tr -d '\r\n[:space:]')"
    if [[ -z "$engine_ip" ]]; then
        log_warn "Could not determine engine IPv4 from 'settings:get default_ipv4'; skipping HTTP verification."
    else
        status_min="${VERIFY_STATUS%-*}"
        status_max="${VERIFY_STATUS#*-}"
        url="https://${DOMAIN}${VERIFY_PATH}"
        log_info "Waiting for $url to respond (resolving $DOMAIN -> $engine_ip, timeout ${VERIFY_TIMEOUT}s)..."

        body_file="$(mktemp)"
        elapsed=0
        http_code=0
        until [[ "$elapsed" -ge "$VERIFY_TIMEOUT" ]]; do
            http_code="$(curl -k -s -o "$body_file" -w '%{http_code}' \
                --resolve "${DOMAIN}:443:${engine_ip}" --resolve "${DOMAIN}:80:${engine_ip}" \
                --max-time 10 "$url" || echo 000)"
            if [[ "$http_code" -ge "$status_min" && "$http_code" -le "$status_max" ]]; then
                break
            fi
            sleep "$VERIFY_INTERVAL"
            elapsed=$((elapsed + VERIFY_INTERVAL))
        done

        if [[ "$http_code" -lt "$status_min" || "$http_code" -gt "$status_max" ]]; then
            log_error "HTTP check failed: $url returned $http_code (expected $VERIFY_STATUS) after ${elapsed}s"
            rm -f "$body_file"
            exit 1
        fi
        log_info "HTTP check OK: $url returned $http_code"

        # Redundant safety net: the placeholder pages the engine serves when it
        # couldn't recognize/build the project (see the deploy_strategy check
        # above) return a normal 2xx status, so a status check alone can't tell
        # them apart from a real app. Their title is load-bearing (matched by
        # DetectProjectStrategy::isEngineWelcomeHtml() on the PHP side too).
        if [[ "$ALLOW_UNRECOGNIZED" -eq 0 ]] && grep -qE 'PanelAlpha — Project not configured|PanelAlpha — Ready' "$body_file" 2>/dev/null; then
            log_error "$url served PanelAlpha's placeholder page, not the deployed app (project was not recognized)."
            rm -f "$body_file"
            exit 1
        fi
        rm -f "$body_file"
    fi
fi

# --- verify: custom command ---------------------------------------------------
if [[ -n "$VERIFY_CMD" ]]; then
    log_info "Running custom verify command..."
    if ! USERNAME="$USERNAME" DOMAIN="$DOMAIN" bash -c "$VERIFY_CMD"; then
        log_error "Custom verify command failed: $VERIFY_CMD"
        exit 1
    fi
    log_info "Custom verify command OK"
fi

exit 0
