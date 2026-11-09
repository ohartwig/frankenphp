#!/bin/sh
# Worker alternation test: one FrankenPHP frontend worker, many alternating
# requests for two sites and two languages, every response checked against
# its own request. See README.md, "Worker alternation test".
#
# Needs php (with curl and pdo_sqlite), composer, frankenphp, git and tar,
# as the franken-php CI image provides. Builds everything in a work
# directory outside the repository (WA_WORK_DIR, default: a new temp dir).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../../.." && pwd)
WORK=${WA_WORK_DIR:-$(mktemp -d)}
PROJECT="$WORK/project"
LOG="$WORK/frankenphp.log"

# Xdebug in the CI image slows every request down and is of no use here.
export XDEBUG_MODE=off

echo "Work directory: $WORK"
rm -rf "$WORK/src" "$PROJECT"
mkdir -p "$WORK/src" "$PROJECT"

# The extension as it is in this working tree (tracked and untracked files,
# without what .gitignore excludes, such as vendor/). Installed from there
# as a copy, so the worker file sits under vendor/ exactly as in a real
# installation and resolves vendor/autoload.php on its own.
(cd "$REPO" && git ls-files -z --cached --others --exclude-standard \
    | xargs -0 sh -c 'for f; do [ -e "$f" ] && printf "%s\0" "$f"; done' sh \
    | tar --null -T - -cf -) | tar -xf - -C "$WORK/src"

cp -R "$HERE/project/." "$PROJECT/"
cd "$PROJECT"

echo "--- composer install"
composer install --no-interaction --no-progress --prefer-dist

echo "--- TYPO3 setup (SQLite)"
vendor/bin/typo3 setup --no-interaction --force \
    --driver=sqlite \
    --admin-username=admin \
    --admin-user-password='Worker-Alternation-1' \
    --admin-email=admin@example.com \
    --project-name='Worker alternation' \
    --server-type=other
php "$HERE/seed.php" "$PROJECT"
vendor/bin/typo3 cache:flush

echo "--- start FrankenPHP with one frontend worker"
# Something already answering on the port would take the requests meant for
# the worker started here, and the test would check another server.
if curl -fsS -o /dev/null http://127.0.0.1:8080/healthz 2>/dev/null; then
    echo "Port 8080 is already in use; stop the server running there first." >&2
    exit 1
fi
WA_PERSISTENT_BOOTSTRAP=${WA_PERSISTENT_BOOTSTRAP:-0}
export WA_PERSISTENT_BOOTSTRAP
echo "KOH_PERSISTENT_BOOTSTRAP=$WA_PERSISTENT_BOOTSTRAP"
WA_PROJECT_DIR="$PROJECT" MAX_REQUESTS=100000 \
    frankenphp run --config "$HERE/Caddyfile" --adapter caddyfile >"$LOG" 2>&1 &
SERVER_PID=$!
stop_server() {
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
}
trap stop_server EXIT

ready=0
for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null http://127.0.0.1:8080/healthz 2>/dev/null; then
        ready=1
        break
    fi
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        break
    fi
    sleep 1
done
if [ "$ready" != 1 ]; then
    echo "FrankenPHP did not come up:" >&2
    cat "$LOG" >&2
    exit 1
fi

echo "--- alternating requests"
rc=0
php "$HERE/check.php" 127.0.0.1 8080 || rc=$?

echo "--- request timing (koh_timing lines of this run)"
if [ "$WA_PERSISTENT_BOOTSTRAP" = 1 ]; then mode=persistent; else mode=fresh; fi
php "$HERE/timing.php" "$LOG" "$mode" || rc=1

if [ "$rc" != 0 ]; then
    echo "--- FrankenPHP log (last 80 lines)"
    tail -n 80 "$LOG"
    for f in "$PROJECT"/var/log/typo3_*.log; do
        [ -f "$f" ] || continue
        echo "--- $f (last 40 lines)"
        tail -n 40 "$f"
    done
fi
exit "$rc"
