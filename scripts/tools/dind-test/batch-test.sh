#!/bin/bash
#
# Batch DinD deploy tester — tests multiple git repos in DinD containers.
#
# Usage:
#   ./batch-test.sh <repo1> [repo2] [repo3] ...
#   ./batch-test.sh --file=repos.txt
#
# Options:
#   --file=FILE    Read repos from file (one per line, # comments allowed)
#   --timeout=N    Seconds to wait per app (default: 30)
#   --clean        Remove all test containers after each run
#   --verbose      Show full build output
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_PHP="${SCRIPT_DIR}/deploy.php"
RESULTS=()
TIMESTAMP=$(date +%Y%m%d-%H%M%S)

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[1;36m'
NC='\033[0m'

# Parse args
REPOS=()
FILE=""
TIMEOUT=30
CLEAN=false
VERBOSE=false

for arg in "$@"; do
    case $arg in
        --file=*) FILE="${arg#*=}" ;;
        --timeout=*) TIMEOUT="${arg#*=}" ;;
        --clean) CLEAN=true ;;
        --verbose) VERBOSE=true ;;
        --help|-h)
            echo "Usage: $0 <repo1> [repo2] ... | --file=repos.txt [--timeout=30] [--clean] [--verbose]"
            exit 0
            ;;
        *)
            REPOS+=("$arg")
            ;;
    esac
done

# Read from file if specified
if [[ -n "$FILE" ]]; then
    while IFS= read -r line || [[ -n "$line" ]]; do
        line=$(echo "$line" | sed 's/#.*//' | xargs)
        [[ -z "$line" ]] && continue
        REPOS+=("$line")
    done < "$FILE"
fi

if [[ ${#REPOS[@]} -eq 0 ]]; then
    echo "No repos specified. Use --file or pass repos as arguments."
    exit 1
fi

echo -e "${CYAN}════════════════════════════════════════════════════════════${NC}"
echo -e "${CYAN}  DinD Batch Deploy Tester${NC}"
echo -e "${CYAN}  $(date)${NC}"
echo -e "${CYAN}  ${#REPOS[@]} repo(s) to test${NC}"
echo -e "${CYAN}════════════════════════════════════════════════════════════${NC}"

INDEX=0
TOTAL=${#REPOS[@]}

for repo in "${REPOS[@]}"; do
    INDEX=$((INDEX + 1))
    echo -e "\n${CYAN}[${INDEX}/${TOTAL}] Testing: ${repo}${NC}"

    PHP_ARGS=""
    [[ "$VERBOSE" == "false" ]] && PHP_ARGS="${PHP_ARGS} 2>&1 | tail -30"

    if php "$DEPLOY_PHP" "$repo" --timeout="$TIMEOUT" 2>&1; then
        STATUS="✓ PASS"
        COLOR="$GREEN"
        EXIT_CODE=0
    else
        STATUS="✗ FAIL"
        COLOR="$RED"
        EXIT_CODE=$?
        if [[ $EXIT_CODE -eq 2 ]]; then
            STATUS="✗ BUILD FAILED"
        elif [[ $EXIT_CODE -eq 3 ]]; then
            STATUS="✗ NOT RUNNING"
        fi
    fi

    # Extract container name for cleanup
    CONTAINER_NAME="dind-test-$(basename "$repo" .git)"

    RESULTS+=("${STATUS}|${repo}|${CONTAINER_NAME}|${EXIT_CODE}")

    echo -e "${COLOR}  ${STATUS} — ${repo} (exit: ${EXIT_CODE})${NC}"

    if [[ "$CLEAN" == "true" ]]; then
        echo -e "  ${YELLOW}Cleaning up ${CONTAINER_NAME}...${NC}"
        docker rm -f "$CONTAINER_NAME" 2>/dev/null || true
    fi
done

# Summary
echo -e "\n${CYAN}════════════════════════════════════════════════════════════${NC}"
echo -e "${CYAN}  Summary${NC}"
echo -e "${CYAN}════════════════════════════════════════════════════════════${NC}"

PASSED=0
FAILED=0

for result in "${RESULTS[@]}"; do
    IFS='|' read -r status repo container exitcode <<< "$result"
    if [[ "$status" == "✓ PASS" ]]; then
        echo -e "  ${GREEN}${status}${NC}  ${repo}"
        PASSED=$((PASSED + 1))
    else
        echo -e "  ${RED}${status}${NC}  ${repo} (exit: ${exitcode})"
        FAILED=$((FAILED + 1))
    fi
done

echo -e "\n  ${GREEN}Passed: ${PASSED}${NC}  ${RED}Failed: ${FAILED}${NC}  Total: ${TOTAL}"

# List running test containers
echo -e "\n${CYAN}Running test containers:${NC}"
docker ps --filter "name=dind-test-" --format "  {{.Names}} — {{.Status}}" 2>/dev/null || true

echo -e "\n${YELLOW}To clean up all test containers:${NC}"
echo "  docker rm -f \$(docker ps -aq --filter name=dind-test-) 2>/dev/null"
echo "  rm -rf /tmp/dind-test-*"

exit $FAILED