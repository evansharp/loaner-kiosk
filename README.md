# loaner-kiosk
A SPA to do self-serve signout of CMA loaner Chromebooks.

## Features
- **Ultra Lightweight Single-Page Architecture:** Instant actions, simple forms, and rapid-response client-side live filtering.
- **Resilient MDM Deployment:** Partitioned scripts (`bootstrap_env.sh` and `run_at_login.sh`) optimized for macOS 10.15.7 via MDM or manual device installation.
- **Embedded Web Server Watchdog:** Background loops monitor and auto-revive the local PHP instance seamlessly in the event of failure.
- **Photo Verification:** Automatically captures high-resolution webcam photos of students upon checkout using the native browser `ImageCapture` API for secure audits.
- **Automatic State Reset:** Idle timer automatically resets the kiosk back to the asset scan step after 5 seconds of inactivity.
- **Web Audio Context Feedback:** Plays unique success, warning, and error audio cues to assist users during self-checkout.
- **Interactive Admin Dashboard:** Built-in charts (D3.js), top-user summaries, customizable activity log filters, verification photo modal viewer (1000px layout), and record management (edit/delete) in `admin.php`.

---

## Requirements

### Hardware Requirements
1. **Host Machine (Kiosk Terminal):** 
   - Apple Mac, Mac mini, or iMac running macOS Catalina (10.15.7) or newer.
   - Built-in FaceTime HD camera or external USB/UVC Web Camera (required for verification photo capture).
   - High-speed USB Barcode / QR Code Scanner (configured in Keyboard Wedge emulation mode to automatically append Return/Enter keypresses).
2. **Kiosk Target Devices:** Chromebooks or general assets labeled with unique numeric/QR code asset tags.

### Software Requirements
1. **Operating System:** macOS 10.15.7 (Catalina) or newer.
2. **Browser:** Google Chrome (configured to launch in `--kiosk` fullscreen mode).
3. **Languages & Services:**
   - PHP 7.3+ (leveraging Catalina's built-in PHP engine; no third-party runtimes required).
   - SQLite 3 (pre-installed natively on macOS for localized datastore storage).
   - Git & Xcode Command Line Tools (used for code freshness and repository syncs).

---

## Architecture & MDM Deployment Guide

We break apart the kiosk lifecycle into two lightweight, robust scripts to work seamlessly with macOS MDM deployment workflows:

### 1. Initial Setup: `bootstrap_env.sh`
This script is executed **once** by the MDM agent with a long timeout to prepare a new terminal device.
- **Actions:** Verifies Xcode Command Line Tools, ensures directories are established, clones/updates the project repository inside `~/Sites`, constructs the background runner script (`~/setup_and_launch.sh`), and registers the LaunchAgent `.plist` in the user domain.
- **Execution (MDM Run-Once Command):**
  ```bash
  sudo /bin/bash /path/to/bootstrap_env.sh
  ```

### 2. User Space Kiosk Launcher: `run_at_login.sh`
This script runs automatically when the target console user logs in to facilitate the kiosk state.
- **Actions:** Safe-boots the `com.user.bootlaunch` LaunchAgent directly within the active user session GUI context.
- **Execution (MDM Run-on-Login / LaunchAgent Hook):**
  ```bash
  /bin/bash /path/to/run_at_login.sh
  ```

---

## Local Development & Manual Run
If you are running the kiosk manually on a machine:
1. Double-click or execute the bootstrap script from within the clone directory:
   ```bash
   ./bootstrap_env.sh
   ```
2. Kickstart the background process:
   ```bash
   ./run_at_login.sh
   ```
3. Open your browser and navigate to:
   - **Kiosk Interface:** `http://localhost:8080`
   - **Admin Analytics Panel:** `http://localhost:8080/admin.php`

---

## Security & Verification Storage
- Local database entries and configuration reside in `chromebooks.sqlite`.
- High-resolution verification photos are organized hierarchically by year, month, and date under:
  `checkout_verification/YYYY/MM/DD/{photo_uuid}.jpg`
- No remote databases, external trackers, or third-party cloud services are leveraged, offering total security and localized privacy.
