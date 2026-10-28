# loaner-kiosk
A SPA to do self-serve signout of CMA loaner Chromebooks.

## Features
- **Ultra Lightweight Single-Page Architecture:** Instant actions, simple forms, and rapid-response client-side live filtering.
- **Resilient MDM Deployment:** Partitioned scripts (`bootstrap_env.sh` and `run_at_login.sh`) optimized for macOS 10.15.7 via MDM or manual device installation.
- **Embedded Web Server Watchdog:** Background loops monitor and auto-revive the local PHP instance seamlessly in the event of failure.
- **Photo Verification:** Automatically captures high-resolution webcam photos of students upon checkout using the native browser `ImageCapture` API for secure audits.
- **Automatic State Reset:** Idle timer automatically resets the kiosk back to the asset scan step after 5 seconds of inactivity.
- **Web Audio Context Feedback:** Plays unique success, warning, and error audio cues to assist users during self-checkout.
- **Interactive Admin Dashboard:** Built-in charts (D3.js), top-user summaries, customizable activity log filters, verification photo modal viewer (1000px layout), and record management (edit/delete) in `admin.php`. Password protected, with automatic logout.
- **Overdue Email Notifications:** At a configurable time each day, borrowers linked to the Google directory are emailed about their own overdue devices, and administrators get an HTML summary of everything still checked out. Sent through Google's SMTP relay; configured on the admin page.
- **Google Directory Names:** The name field suggests people from chosen Google Workspace org units, and checkouts are linked to the person's Google account. Configured on the admin page.

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
- **Actions:** Verifies Xcode Command Line Tools, ensures directories are established, clones/updates the project repository inside `~/Sites`, constructs the login launcher script (`~/setup_and_launch.sh`), and registers the LaunchAgent `.plist` in the user domain.
- At each login, `~/setup_and_launch.sh` pulls the latest code and hands off to `scripts/kiosk_worker.sh` in the repo, which runs the PHP server (`router.php`, serving only `public/`), opens Chrome, revives the server if it crashes, and runs the background jobs every 5 minutes. Changes to the worker arrive with `git pull`.
- **Kiosks bootstrapped before `public/` existed** still run `php -S` from the repo root. The root `index.php` routes their requests into `public/` so they keep working, but **re-run `bootstrap_env.sh` on them** to stop serving the rest of the repo (including `private/`) and to get the background jobs.
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
3. Set the admin password (prompts twice; at least 8 characters):
   ```bash
   php bin/set_admin_password.php
   ```
4. Open your browser and navigate to:
   - **Kiosk Interface:** `http://localhost:8080`
   - **Admin Analytics Panel:** `http://localhost:8080/admin.php`

Without the scripts, run `php -S localhost:8080 router.php` from the repo root (the router serves only `public/`). Under Apache, the root `.htaccess` does the same.

### Admin Login
- The admin page logs out after 10 minutes without activity (returning to the kiosk), whenever the kiosk page is opened, and from **Open Kiosk View**.
- Five wrong passwords lock the login for 5 minutes. Running `bin/set_admin_password.php` again changes the password and clears the lockout.

---

## Overdue Email Notifications
Configure under **Overdue Email Notifications** at the bottom of `admin.php`: the daily notify time, the administrator recipients (comma-separated), whether to email borrowers, and the SMTP relay connection. Use **Save & Send Test Email** to check the relay settings, and the **Preview** buttons to see both emails.

Each day at the notify time:

- **Borrowers** whose checkouts are linked to the Google directory each get one email at their directory address, listing their overdue devices and asking them to return them. Replies go to the administrator recipients. Each borrower is emailed at most once a day; a failed borrower email is reported to the administrators, not retried.
- **Administrators** get a summary of every device still checked out (including typed names not in the directory), showing whether each borrower was emailed.

Both are HTML emails with a plain-text alternative. The templates are in `templates/emails/` (`layout.php` wraps `overdue_admin.php` and `overdue_borrower.php`); `src/OverdueEmails.php` prepares their data and the plain-text versions.

1. **Set up the relay** in the Google Admin console under *Apps → Google Workspace → Gmail → Routing → SMTP relay service*. Either allow the kiosk network's public IP address (then leave the username and password blank), or require SMTP authentication (then enter a Workspace account and its password or app password). Requiring TLS is supported.
2. **Typical settings:** host `smtp-relay.gmail.com`, port `587` with STARTTLS (or `465` with SSL/TLS). The From address must be in your Workspace domain. The EHLO domain defaults to the From address's domain, since Google's relay rejects `localhost`.
3. **Scheduling:** `scripts/kiosk_worker.sh` runs `bin/notify_overdue.php` every 5 minutes. It sends at most once per day, after the notify time. If the kiosk was asleep or offline, it sends when it next runs that day, and a failed send is retried on the next run. Results appear under *Last check* on the admin page and in `~/Library/Logs/php_kiosk_server.log`.
   - To check and send immediately (ignoring the schedule): `php bin/notify_overdue.php --force`

---

## Google Directory Names
Configure under **Google Directory** at the bottom of `admin.php`. When it's on, students start typing their name and pick it from a list (arrow keys and Enter, or tap). The checkout records their Google account ID and email along with their name.

1. **Google Cloud:** in a project owned by the school, enable the **Admin SDK API**, create a service account, and download a JSON key for it. (If key creation is blocked, an org policy administrator must allow it for that project: `iam.disableServiceAccountKeyCreation`.)
2. **Google Admin console:** under *Account → Admin roles*, create a role with only the **Users → Read** privilege, and assign it to the service account's email address. No domain-wide delegation is needed.
3. **Admin page:** upload the key, enter your **Customer ID** (*Account → Account settings* in the Admin console; Google may reject the sync with "Invalid Input" without it), list the org units to sync (one per line, e.g. `/Students`; sub-units are included), turn it on, and click **Save & Sync Now**.
4. **Typed names:** with *Also allow typed names* on, people not in the directory (guests, new students) can still type a full name. The list then offers "Use what I typed", and those checkouts aren't linked. With it off, a name must be chosen from the list. A typed name that exactly matches one person in the directory is linked automatically.

Syncing runs every 6 hours from `scripts/kiosk_worker.sh` (failures retry after 30 minutes): `php bin/sync_directory.php --force` syncs immediately. Only active (not suspended or archived) users are suggested. People who leave the synced org units stay in the local table, marked inactive, so old records still show their name. A sync that finds nobody (usually a mistyped org unit) changes nothing.

---

## Security & Verification Storage
- Only `public/` is served. Everything private lives in the gitignored `private/` folder, readable only by the kiosk's user account:
  - `private/chromebooks.sqlite`: checkouts, settings, synced directory people, and the admin password hash.
  - `private/checkout_verification/YYYY/MM/DD/{photo_uuid}.jpg`: verification photos, shown only to logged-in admins (via `photo.php`).
  - `private/google-service-account.json`: the directory service account key (uploaded on the admin page).
- Data from older versions in the repo root (`chromebooks.sqlite`, `checkout_verification/`) is moved into `private/` automatically.
- No remote databases or external trackers are used. The only outside services are Google's SMTP relay (overdue emails) and the Google Directory API (name lookup), each only when enabled.
- SMTP relay settings, including any SMTP password, are stored in the database. Prefer IP address authentication on the relay so no password needs to be stored.
- The name autocomplete has to work without a login, so anyone at the kiosk can look up names and emails in the synced org units (at most 8 matches per search, after 2 or more characters).
