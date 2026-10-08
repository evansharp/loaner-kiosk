#!/bin/bash
set -e

# ==========================================
# 1. CONFIGURATION (MDM Bootstrap Environment)
# ==========================================
TARGET_USER=$(scutil <<< "show State:/Users/ConsoleUser" | awk '/Name :/ {print $3}')
if [ -z "$TARGET_USER" ] || [ "$TARGET_USER" = "loginwindow" ]; then
    TARGET_USER=$(dscl . list /Users UniqueID | awk '$2 > 500 {print $1}' | grep -v 'Shared' | head -n1)
fi

REPO_URL="https://github.com/evansharp/loaner-kiosk"
TARGET_PORT="8080"

# Dynamically derive the exact folder name Git will create
REPO_NAME=$(basename -s .git "$REPO_URL")

USER_HOME="/Users/$TARGET_USER"
SITES_DIR="$USER_HOME/Sites"
PROJECT_DIR="$SITES_DIR/$REPO_NAME"
LAUNCH_SCRIPT="$USER_HOME/setup_and_launch.sh"
PLIST_LABEL="com.user.bootlaunch"
PLIST_PATH="$USER_HOME/Library/LaunchAgents/$PLIST_LABEL.plist"

echo "Executing MDM bootstrap setup for user: $TARGET_USER"
echo "Dynamic Repository Name Target: $REPO_NAME"

# ==========================================
# 2. RUNTIME PREREQUISITES & HOOKS (Xcode CLT & Git)
# ==========================================

# 2a. Check/Install Xcode Command Line Tools (includes Git)
if ! xcode-select -p &>/dev/null; then
    echo "Installing Xcode Command Line Tools..."
    touch /tmp/.com.apple.dt.CommandLineTools.installondemand.in-progress
    PROD=$(softwareupdate -l | grep "\*.*Command Line" | head -n 1 | awk -F"*" '{print $2}' | sed -e 's/^ *//' | tr -d '\n')
    softwareupdate -i "$PROD" --verbose
    rm -f /tmp/.com.apple.dt.CommandLineTools.installondemand.in-progress
fi

export PATH="/usr/bin:/bin:/usr/sbin:/sbin"

# ==========================================
# 3. DIRECTORY STRUCTURE & REPOSITORY PREPARATION
# ==========================================

# Create the parent container if it doesn't exist
if [ ! -d "$SITES_DIR" ]; then
    echo "Creating base directory $SITES_DIR..."
    mkdir -p "$SITES_DIR"
    chown "$TARGET_USER":staff "$SITES_DIR"
fi

# Check if we are being run manually from within a directory matching $REPO_NAME
CURRENT_DIR_NAME=$(basename "$PWD")

if [ "$CURRENT_DIR_NAME" = "$REPO_NAME" ]; then
    echo "Detected manual execution from within existing repo directory ($CURRENT_DIR_NAME)."
    if [ "$PROJECT_DIR" != "$PWD" ]; then
        echo "Copying/synchronizing current repository into target path $PROJECT_DIR..."
        mkdir -p "$PROJECT_DIR"
        cp -R . "$PROJECT_DIR/"
        chown -R "$TARGET_USER":staff "$PROJECT_DIR"
    else
        echo "Already running inside target project directory $PROJECT_DIR."
    fi
    cd "$PROJECT_DIR"
    chown -R "$TARGET_USER":staff "$PROJECT_DIR"
elif [ ! -d "$PROJECT_DIR" ]; then
    echo "Cloning repository directly into $SITES_DIR..."
    cd "$SITES_DIR"
    sudo -u "$TARGET_USER" git clone "$REPO_URL"
else
    echo "Repository directory already exists. Verifying codebase freshness..."
    cd "$PROJECT_DIR"
    sudo -u "$TARGET_USER" git pull origin $(sudo -u "$TARGET_USER" git branch --show-current) || true
fi

# ==========================================
# 4. GENERATING RUNTIME HOOKS & LAUNCHAGENT
# ==========================================

# 4a. Write Runtime Worker Script
echo "Writing worker loop execution script..."
cat << 'EOF' > "$LAUNCH_SCRIPT"
#!/bin/bash
export PATH="/usr/bin:/bin:/usr/sbin:/sbin"

SITES_DIR="$HOME/Sites"
PROJECT_DIR="$SITES_DIR/DYNAMIC_REPO_NAME"
PORT="DYNAMIC_PORT"
LOG_FILE="$HOME/Library/Logs/php_kiosk_server.log"

# Safely build user log directory structures
mkdir -p "$(dirname "$LOG_FILE")"

echo "=== Kiosk Launch Bootstrapper Activated ($(date)) ===" >> "$LOG_FILE"

cd "$PROJECT_DIR"
echo "Synchronizing with upstream remote branch..." >> "$LOG_FILE"
git pull origin $(git branch --show-current) >> "$LOG_FILE" 2>&1

# -------------------------------------------------------------
# 1. START LOCAL PHP SERVER IN BACKGROUND
# -------------------------------------------------------------
echo "[$(date)] Starting local PHP server instance on port $PORT..." >> "$LOG_FILE"
php -S localhost:$PORT >> "$LOG_FILE" 2>&1 &
PHP_PID=$!

# Wait briefly for PHP server to bind to port
sleep 1

# -------------------------------------------------------------
# 2. CHROME INITIALIZATION (Runs once at startup; no auto-revive)
# -------------------------------------------------------------
open -a "Google Chrome" --args \
  --kiosk \
  --incognito \
  --disable-session-crashed-bubble \
  --no-first-run \
  "http://localhost:$PORT"

# -------------------------------------------------------------
# 3. INFINITE WATCH & RECOVERY LOOP (Logs crash/uptime analytics)
# -------------------------------------------------------------
while true; do
    if ! kill -0 $PHP_PID 2>/dev/null; then
        echo "[$(date)] CRITICAL: PHP server dropped socket connection or crashed." >> "$LOG_FILE"
        echo "[$(date)] Attempting recovery bind step in 3 seconds..." >> "$LOG_FILE"
        sleep 3

        lsof -ti :$PORT | xargs kill -9 2>/dev/null || true
        php -S localhost:$PORT >> "$LOG_FILE" 2>&1 &
        PHP_PID=$!
    fi
    sleep 5
done
EOF

# Inject the dynamically derived strings into the static background worker template
sed -i '' "s|DYNAMIC_REPO_NAME|$REPO_NAME|g" "$LAUNCH_SCRIPT"
sed -i '' "s|DYNAMIC_PORT|$TARGET_PORT|g" "$LAUNCH_SCRIPT"
chmod +x "$LAUNCH_SCRIPT"
chown "$TARGET_USER":staff "$LAUNCH_SCRIPT"

# 4b. Write the Plist Configuration File into place
echo "Writing LaunchAgent definition profile..."
mkdir -p "$(dirname "$PLIST_PATH")"
chown "$TARGET_USER":staff "$(dirname "$PLIST_PATH")"

cat << EOF > "$PLIST_PATH"
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://apple.com">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$PLIST_LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>/bin/bash</string>
        <string>$LAUNCH_SCRIPT</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
</dict>
</plist>
EOF

chmod 644 "$PLIST_PATH"
chown "$TARGET_USER":staff "$PLIST_PATH"

echo "Bootstrap environment setup finished successfully."
