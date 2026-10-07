#!/bin/bash
set -e

# ==========================================
# 1. CONFIGURATION (User Login Runtime Environment)
# ==========================================
TARGET_USER=$(scutil <<< "show State:/Users/ConsoleUser" | awk '/Name :/ {print $3}')
if [ -z "$TARGET_USER" ] || [ "$TARGET_USER" = "loginwindow" ]; then
    TARGET_USER=$(dscl . list /Users UniqueID | awk '$2 > 500 {print $1}' | grep -v 'Shared' | head -n1)
fi

USER_HOME="/Users/$TARGET_USER"
PLIST_LABEL="com.user.bootlaunch"
PLIST_PATH="$USER_HOME/Library/LaunchAgents/$PLIST_LABEL.plist"

echo "Executing login kiosk initialization for user: $TARGET_USER"

# ==========================================
# 2. LOADING ENVIRONMENT CONTEXT & LAUNCHAGENT
# ==========================================
USER_ID=$(id -u "$TARGET_USER")

echo "Bootstrapping LaunchAgent into user space domain ($USER_ID)..."
if launchctl print gui/"$USER_ID" &>/dev/null; then
    launchctl bootstrap gui/"$USER_ID" "$PLIST_PATH" 2>/dev/null || true
    launchctl kickstart -k gui/"$USER_ID"/"$PLIST_LABEL" 2>/dev/null || true
else
    sudo -u "$TARGET_USER" launchctl load "$PLIST_PATH" 2>/dev/null || true
fi

echo "Login execution and kiosk setup finished successfully."
