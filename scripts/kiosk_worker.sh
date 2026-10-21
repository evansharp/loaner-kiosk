#!/bin/bash
# Kiosk runtime: serves the app, opens Chrome in kiosk mode, and keeps the server alive.
#
# Started at login by ~/setup_and_launch.sh (written by bootstrap_env.sh) right after it
# pulls the latest code, so changes to this file ship with a normal git pull.
#
# Usage: kiosk_worker.sh [port]
export PATH="/usr/bin:/bin:/usr/sbin:/sbin"

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8080}"
LOG_FILE="$HOME/Library/Logs/php_kiosk_server.log"

# Safely build user log directory structures
mkdir -p "$(dirname "$LOG_FILE")"
cd "$PROJECT_DIR"

# router.php serves only public/; the database, photos and Google key in private/ are not
start_server() {
    php -S localhost:$PORT -t "$PROJECT_DIR" "$PROJECT_DIR/router.php" >> "$LOG_FILE" 2>&1 &
    PHP_PID=$!
}

# -------------------------------------------------------------
# 1. START LOCAL PHP SERVER IN BACKGROUND
# -------------------------------------------------------------
echo "[$(date)] Starting local PHP server instance on port $PORT..." >> "$LOG_FILE"
start_server

# Wait briefly for PHP server to bind to port
sleep 2

# -------------------------------------------------------------
# 2. CHROME INITIALIZATION (Runs once at startup; no auto-revive)
# -------------------------------------------------------------
open -a "Google Chrome" --args \
  --kiosk \
  --disable-session-crashed-bubble \
  --no-first-run \
  "http://localhost:$PORT"

# -------------------------------------------------------------
# 3. INFINITE WATCH & RECOVERY LOOP (Logs crash/uptime analytics)
#    Also runs the background jobs every ~5 minutes (60 x 5s);
#    each decides for itself whether it's due:
#    - overdue-device email (at most once per day)
#    - Google directory sync (every 6 hours)
# -------------------------------------------------------------
TICKS=0
while true; do
    if [ $((TICKS % 60)) -eq 0 ]; then
        php "$PROJECT_DIR/bin/notify_overdue.php" >> "$LOG_FILE" 2>&1 &
        php "$PROJECT_DIR/bin/sync_directory.php" >> "$LOG_FILE" 2>&1 &
    fi
    TICKS=$((TICKS + 1))

    if ! kill -0 $PHP_PID 2>/dev/null; then
        echo "[$(date)] CRITICAL: PHP server dropped socket connection or crashed." >> "$LOG_FILE"
        echo "[$(date)] Attempting recovery bind step in 3 seconds..." >> "$LOG_FILE"
        sleep 3

        lsof -ti :$PORT | xargs kill -9 2>/dev/null || true
        start_server
    fi
    sleep 5
done
