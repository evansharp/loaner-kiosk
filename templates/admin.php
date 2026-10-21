<?php
/**
 * Admin dashboard view.
 *
 * @var ?Alert      $alert
 * @var array       $allRecords
 * @var array       $deviceStats  asset_number => total_seconds
 * @var array[]     $topUsers     ['name', 'email', 'seconds'] from UsageStats::topUsers()
 * @var PhotoStore  $photos
 * @var string      $csrfToken    Included in every form; checked by public/admin.php
 * @var NotificationSettings $notificationSettings
 * @var array|null  $lastNotification  ['message', 'at', 'ok'] from OverdueNotifier::lastResult()
 * @var bool        $directoryEnabled  Whether the edit form suggests directory names
 * @var DirectorySettings $directorySettings
 * @var array|null  $lastDirectorySync ['message', 'at', 'ok'] from DirectorySync::lastResult()
 * @var int         $directoryPeopleCount
 * @var ServiceAccountKey|null $serviceAccountKey
 */
$csrfField = '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrfToken) . '">';

// Chart data for assets/js/admin.js
$chartData = [];
foreach ($deviceStats as $asset => $seconds) {
    $chartData[] = ['asset' => '#' . $asset, 'seconds' => $seconds];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coast Mountain Academy — Admin Dashboard</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- D3.js -->
    <script src="https://d3js.org/d3.v7.min.js"></script>
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/name-autocomplete.css">
</head>
<body data-idle-timeout="<?= AdminAuth::IDLE_TIMEOUT ?>" data-directory="<?= $directoryEnabled ? 'on' : 'off' ?>">

<div class="container py-4" style="max-width: 1200px;">
    <!-- Top Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <img src="logo.png" alt="Coast Mountain Academy" class="brand-logo" onerror="this.style.display='none'">
            <div>
                <h1 class="h3 mb-0 fw-bold">Admin Dashboard & Analytics</h1>
                <p class="text-muted small mb-0">Loaner Chromebook Tracking System</p>
            </div>
        </div>
        <div>
            <a href="admin.php?logout=1" class="btn btn-outline-secondary btn-sm fw-semibold" title="Logs out of the admin page">
                <i class="bi bi-display me-1"></i> Open Kiosk View
            </a>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($alert && $alert->section === 'page'): ?>
        <div class="alert alert-<?= $alert->type ?> alert-dismissible fade show admin-card mb-4" role="alert">
            <?= htmlspecialchars($alert->message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Row 1: Charts & Top Users -->
    <div class="row g-4 mb-4">
        <!-- Chart: Device Time Out -->
        <div class="col-lg-8">
            <div class="card admin-card h-100">
                <div class="card-body p-4">
                    <h5 class="card-title fw-bold mb-3"><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Device Total Time Checked Out</h5>
                    <div id="deviceChart" class="chart-container" data-chart="<?= htmlspecialchars(json_encode($chartData)) ?>"></div>
                </div>
            </div>
        </div>

        <!-- Top 5 Users -->
        <div class="col-lg-4">
            <div class="card admin-card h-100">
                <div class="card-body p-4">
                    <h5 class="card-title fw-bold mb-3"><i class="bi bi-trophy-fill me-2 text-warning"></i>Top 5 Users by Time</h5>
                    <?php if (empty($topUsers)): ?>
                        <p class="text-muted text-center py-4">No usage data available.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php $rank = 1; foreach ($topUsers as $topUser): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary rounded-circle p-2" style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;"><?= $rank++ ?></span>
                                        <span class="fw-semibold text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($topUser['email'] ?: $topUser['name']) ?>"><?= htmlspecialchars($topUser['name']) ?></span>
                                    </div>
                                    <span class="badge bg-light text-dark border font-monospace"><?= UsageStats::formatDuration($topUser['seconds']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Device Time Summary Table -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card admin-card">
                <div class="card-body p-4">
                    <h5 class="card-title fw-bold mb-3"><i class="bi bi-stopwatch-fill me-2 text-success"></i>Device Time Summary</h5>
                    <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Asset #</th>
                                    <th>Total Time Checked Out</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($deviceStats)): ?>
                                    <tr><td colspan="2" class="text-center text-muted py-3">No device data found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($deviceStats as $asset => $seconds): ?>
                                        <tr>
                                            <td><span class="badge bg-light text-dark border font-monospace">#<?= htmlspecialchars($asset) ?></span></td>
                                            <td class="fw-semibold"><?= UsageStats::formatDuration($seconds) ?> <span class="text-muted fw-normal small">(<?= number_format($seconds) ?>s)</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: Comprehensive Activity Table with Advanced Filtering -->
    <div class="row g-4">
        <div class="col-12">
            <div class="card admin-card">
                <div class="card-body p-4">
                    <h5 class="card-title fw-bold mb-3"><i class="bi bi-table me-2 text-info"></i>All Activity Records & Management</h5>

                    <!-- Filter Toolbar -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Filter Device (Asset #)</label>
                            <input type="text" id="filterDevice" class="form-control form-control-sm" placeholder="e.g. 101">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Filter User Name</label>
                            <input type="text" id="filterUser" class="form-control form-control-sm" placeholder="e.g. John">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Date Out</label>
                            <input type="date" id="filterDateOut" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Date In</label>
                            <input type="date" id="filterDateIn" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" id="resetFilters" class="btn btn-outline-secondary btn-sm w-100">Reset Filters</button>
                        </div>
                    </div>

                    <!-- Activity Table -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="adminTable">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Asset #</th>
                                    <th>Person's Name</th>
                                    <th>Check-Out Time</th>
                                    <th>Check-In Time</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allRecords)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">No checkout records found in database.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allRecords as $record): ?>
                                        <?php
                                            $isOut = is_null($record['checkin_time']);
                                            $checkoutDateOnly = date('Y-m-d', strtotime($record['checkout_time']));
                                            $checkinDateOnly = $record['checkin_time'] ? date('Y-m-d', strtotime($record['checkin_time'])) : '';
                                            $photoUrl = $photos->urlFor($record['photo_uuid'] ?? null, $record['checkout_time']);
                                        ?>
                                        <tr data-asset="<?= htmlspecialchars(strtolower($record['asset_number'])) ?>"
                                            data-user="<?= htmlspecialchars(strtolower(trim($record['user_name'] . ' ' . ($record['user_email'] ?? '')))) ?>"
                                            data-dateout="<?= $checkoutDateOnly ?>"
                                            data-datein="<?= $checkinDateOnly ?>">
                                            <td class="text-muted small"><?= (int)$record['id'] ?></td>
                                            <td><span class="badge bg-light text-dark border font-monospace">#<?= htmlspecialchars($record['asset_number']) ?></span></td>
                                            <td class="fw-semibold">
                                                <?= htmlspecialchars($record['user_name']) ?>
                                                <?php if (!empty($record['user_email'])): ?>
                                                    <div class="small text-muted fw-normal" title="Linked to the Google directory"><i class="bi bi-google me-1"></i><?= htmlspecialchars($record['user_email']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><small class="text-secondary"><?= htmlspecialchars($record['checkout_time']) ?></small></td>
                                            <td><small class="text-secondary"><?= $record['checkin_time'] ? htmlspecialchars($record['checkin_time']) : '—' ?></small></td>
                                            <td>
                                                <?php if ($isOut): ?>
                                                    <span class="badge bg-warning text-dark">Checked Out</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Returned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($photoUrl): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#photoModal"
                                                            data-photo="<?= htmlspecialchars($photoUrl) ?>"
                                                            data-user="<?= htmlspecialchars($record['user_name']) ?>"
                                                            data-asset="<?= htmlspecialchars($record['asset_number']) ?>">
                                                        <i class="bi bi-camera-fill"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#editModal"
                                                        data-id="<?= (int)$record['id'] ?>"
                                                        data-asset="<?= htmlspecialchars($record['asset_number']) ?>"
                                                        data-user="<?= htmlspecialchars($record['user_name']) ?>"
                                                        data-user-id="<?= htmlspecialchars($record['user_id'] ?? '') ?>"
                                                        data-user-email="<?= htmlspecialchars($record['user_email'] ?? '') ?>"
                                                        data-checkout="<?= htmlspecialchars($record['checkout_time']) ?>"
                                                        data-checkin="<?= htmlspecialchars($record['checkin_time'] ?? '') ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" action="admin.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete record #<?= (int)$record['id'] ?>?');">
                                                    <?= $csrfField ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="record_id" value="<?= (int)$record['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <tr id="adminNoResults" style="display: none;">
                                    <td colspan="7" class="text-center py-4 text-muted">No matching activity records found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 4: Overdue Email Notification Settings -->
    <div class="row g-4 mt-0" id="notificationSettings">
        <div class="col-12">
            <div class="card admin-card">
                <div class="card-body p-4">
                    <h5 class="card-title fw-bold mb-3"><i class="bi bi-envelope-fill me-2 text-danger"></i>Overdue Email Notifications</h5>

                    <?php if ($alert && $alert->section === 'settings'): ?>
                        <div class="alert alert-<?= $alert->type ?> alert-dismissible fade show mb-4" role="alert">
                            <?= htmlspecialchars($alert->message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="admin.php#notificationSettings">
                        <?= $csrfField ?>
                        <div class="row g-4">
                            <!-- When & who to notify -->
                            <div class="col-lg-5">
                                <h6 class="settings-heading">Schedule &amp; Recipients</h6>

                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="notifyEnabled" name="notify_enabled" value="1" <?= $notificationSettings->enabled ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="notifyEnabled">Send a daily email about overdue devices</label>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="notifyUsers" name="notify_users" value="1" <?= $notificationSettings->notifyUsers ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="notifyUsers">Also email each borrower at their Google directory address</label>
                                    <div class="form-text mt-0">Only for checkouts linked to the directory. Their replies go to the recipients below.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="notifyTime" class="form-label fw-semibold">Notify At</label>
                                    <input type="time" class="form-control" id="notifyTime" name="notify_time" value="<?= htmlspecialchars($notificationSettings->notifyTime) ?>" required>
                                    <div class="form-text">Devices still checked out at this time each day are reported. The kiosk checks every 5 minutes.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="notifyRecipients" class="form-label fw-semibold">Administrator Recipients</label>
                                    <input type="text" class="form-control" id="notifyRecipients" name="notify_recipients" value="<?= htmlspecialchars($notificationSettings->recipients) ?>" placeholder="it@example.org, office@example.org" autocomplete="off">
                                    <div class="form-text">Comma-separated email addresses. They get the daily summary of all overdue devices.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="schoolName" class="form-label fw-semibold">School Name</label>
                                    <input type="text" class="form-control" id="schoolName" name="school_name" value="<?= htmlspecialchars($notificationSettings->schoolName) ?>" required autocomplete="off">
                                    <div class="form-text">Shown in the header and footer of every email.</div>
                                </div>

                                <div class="small p-3 rounded border bg-light">
                                    <span class="fw-semibold">Last check:</span>
                                    <?php if ($lastNotification): ?>
                                        <?= htmlspecialchars(date('M j, Y — g:i A', strtotime($lastNotification['at']))) ?><br>
                                        <span class="<?= $lastNotification['ok'] ? 'text-success' : 'text-danger' ?>"><?= htmlspecialchars($lastNotification['message']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Never run.</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Google SMTP relay connection -->
                            <div class="col-lg-7">
                                <h6 class="settings-heading">Google SMTP Relay</h6>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="smtpHost" class="form-label fw-semibold">SMTP Host</label>
                                        <input type="text" class="form-control" id="smtpHost" name="smtp_host" value="<?= htmlspecialchars($notificationSettings->smtpHost) ?>" placeholder="smtp-relay.gmail.com" autocomplete="off">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="smtpPort" class="form-label fw-semibold">Port</label>
                                        <input type="number" class="form-control" id="smtpPort" name="smtp_port" value="<?= (int)$notificationSettings->smtpPort ?>" min="1" max="65535">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="smtpEncryption" class="form-label fw-semibold">Encryption</label>
                                        <select class="form-select" id="smtpEncryption" name="smtp_encryption">
                                            <?php foreach (NotificationSettings::ENCRYPTION_OPTIONS as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= $notificationSettings->smtpEncryption === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="mailFromAddress" class="form-label fw-semibold">From Address</label>
                                        <input type="email" class="form-control" id="mailFromAddress" name="mail_from_address" value="<?= htmlspecialchars($notificationSettings->fromAddress) ?>" placeholder="kiosk@example.org" autocomplete="off">
                                        <div class="form-text">Must be an address in your Google Workspace domain.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mailFromName" class="form-label fw-semibold">From Name</label>
                                        <input type="text" class="form-control" id="mailFromName" name="mail_from_name" value="<?= htmlspecialchars($notificationSettings->fromName) ?>" autocomplete="off">
                                    </div>

                                    <div class="col-md-6">
                                        <label for="smtpUsername" class="form-label fw-semibold">Username <span class="text-muted fw-normal">(optional)</span></label>
                                        <input type="text" class="form-control" id="smtpUsername" name="smtp_username" value="<?= htmlspecialchars($notificationSettings->smtpUsername) ?>" placeholder="Blank for IP address authentication" autocomplete="off">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="smtpPassword" class="form-label fw-semibold">Password <span class="text-muted fw-normal">(optional)</span></label>
                                        <input type="password" class="form-control" id="smtpPassword" name="smtp_password" value="" placeholder="<?= $notificationSettings->smtpPassword !== '' ? 'Saved — leave blank to keep' : 'Blank for IP address authentication' ?>" autocomplete="new-password">
                                        <?php if ($notificationSettings->smtpPassword !== ''): ?>
                                            <div class="form-check mt-1">
                                                <input class="form-check-input" type="checkbox" id="smtpPasswordClear" name="smtp_password_clear" value="1">
                                                <label class="form-check-label small" for="smtpPasswordClear">Remove saved password</label>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="smtpHelo" class="form-label fw-semibold">EHLO Domain <span class="text-muted fw-normal">(optional)</span></label>
                                        <input type="text" class="form-control" id="smtpHelo" name="smtp_helo" value="<?= htmlspecialchars($notificationSettings->smtpHelo) ?>" placeholder="Defaults to the From address's domain" autocomplete="off">
                                    </div>
                                </div>

                                <div class="form-text mt-3">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Set up the relay in the Google Admin console under <strong>Apps → Google Workspace → Gmail → Routing → SMTP relay service</strong>.
                                    If the relay allows this kiosk's public IP address, leave the username and password blank.
                                    If it requires SMTP authentication, enter a Workspace account's address and password (or an app password if it uses 2-Step Verification).
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mt-4 pt-3 border-top">
                            <span class="small text-muted me-1">Preview:</span>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#emailPreviewModal" data-preview="admin" data-title="Administrator summary email">
                                <i class="bi bi-eye me-1"></i> Admin Email
                            </button>
                            <button type="button" class="btn btn-outline-secondary me-auto" data-bs-toggle="modal" data-bs-target="#emailPreviewModal" data-preview="borrower" data-title="Borrower email">
                                <i class="bi bi-eye me-1"></i> Borrower Email
                            </button>
                            <button type="submit" name="action" value="test_email" class="btn btn-outline-primary">
                                <i class="bi bi-send me-1"></i> Save &amp; Send Test Email
                            </button>
                            <button type="submit" name="action" value="save_settings" class="btn btn-primary">
                                Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 5: Google Directory Settings -->
    <div class="row g-4 mt-0" id="directorySettings">
        <div class="col-12">
            <div class="card admin-card">
                <div class="card-body p-4">
                    <h5 class="card-title fw-bold mb-3"><i class="bi bi-people-fill me-2 text-success"></i>Google Directory</h5>

                    <?php if ($alert && $alert->section === 'directory'): ?>
                        <div class="alert alert-<?= $alert->type ?> alert-dismissible fade show mb-4" role="alert">
                            <?= htmlspecialchars($alert->message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="admin.php#directorySettings" enctype="multipart/form-data">
                        <?= $csrfField ?>
                        <div class="row g-4">
                            <!-- Name lookup & org units -->
                            <div class="col-lg-5">
                                <h6 class="settings-heading">Name Lookup</h6>

                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="directoryEnabled" name="directory_enabled" value="1" <?= $directorySettings->enabled ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="directoryEnabled">Suggest names from the Google directory</label>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="directoryAllowTyped" name="directory_allow_typed_names" value="1" <?= $directorySettings->allowTypedNames ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="directoryAllowTyped">Also allow typed names that aren't in the directory</label>
                                </div>
                                <div class="mb-3">
                                    <label for="directoryOrgUnits" class="form-label fw-semibold">Org Units to Sync</label>
                                    <textarea class="form-control font-monospace" id="directoryOrgUnits" name="directory_org_units" rows="4" placeholder="/Students&#10;/Staff"><?= htmlspecialchars($directorySettings->orgUnits) ?></textarea>
                                    <div class="form-text">One org unit path per line, as shown in the Google Admin console. Sub-units are included.</div>
                                </div>

                                <div class="small p-3 rounded border bg-light">
                                    <span class="fw-semibold">Last sync:</span>
                                    <?php if ($lastDirectorySync): ?>
                                        <?= htmlspecialchars(date('M j, Y — g:i A', strtotime($lastDirectorySync['at']))) ?><br>
                                        <span class="<?= $lastDirectorySync['ok'] ? 'text-success' : 'text-danger' ?>"><?= htmlspecialchars($lastDirectorySync['message']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Never run.</span>
                                    <?php endif; ?>
                                    <br><span class="fw-semibold">People available:</span> <?= number_format($directoryPeopleCount) ?>
                                </div>
                            </div>

                            <!-- Service account -->
                            <div class="col-lg-7">
                                <h6 class="settings-heading">Service Account</h6>

                                <div class="mb-3">
                                    <?php if ($serviceAccountKey): ?>
                                        <div class="d-flex justify-content-between align-items-center p-3 rounded border mb-3">
                                            <div class="small">
                                                <i class="bi bi-key-fill text-success me-1"></i>
                                                <span class="fw-semibold"><?= htmlspecialchars($serviceAccountKey->clientEmail) ?></span>
                                                <?php if ($serviceAccountKey->projectId !== ''): ?>
                                                    <div class="text-muted">Project: <?= htmlspecialchars($serviceAccountKey->projectId) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="submit" name="action" value="remove_directory_key" class="btn btn-sm btn-outline-danger" formnovalidate onclick="return confirm('Remove the saved service account key?');">
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                    <label for="directoryKey" class="form-label fw-semibold"><?= $serviceAccountKey ? 'Replace Key File' : 'Key File' ?></label>
                                    <input type="file" class="form-control" id="directoryKey" name="directory_key" accept=".json,application/json">
                                    <div class="form-text">The service account's JSON key from Google Cloud. It's stored on this Mac in the project's <code>private/</code> folder, readable only by the kiosk's user account, and is never committed to git.</div>
                                </div>

                                <div class="form-text">
                                    <i class="bi bi-info-circle me-1"></i>
                                    <strong>Setup:</strong>
                                    In a Google Cloud project, enable the <strong>Admin SDK API</strong>, create a service account, and download a JSON key for it.
                                    Then in the Google Admin console, go to <strong>Account → Admin roles</strong>, create a role with only the <strong>Users → Read</strong> privilege, and assign it to the service account's email address.
                                    The directory is re-synced every 6 hours while the kiosk is running.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <button type="submit" name="action" value="sync_directory" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-repeat me-1"></i> Save &amp; Sync Now
                            </button>
                            <button type="submit" name="action" value="save_directory" class="btn btn-primary">
                                Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Record Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="admin.php" class="modal-content">
            <?= $csrfField ?>
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="editModalLabel">Edit Checkout Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="record_id" id="editRecordId">

                <div class="mb-3">
                    <label for="editAssetNumber" class="form-label fw-semibold">Asset Number</label>
                    <input type="text" class="form-control" id="editAssetNumber" name="asset_number" required>
                </div>
                <div class="mb-3">
                    <label for="editUserName" class="form-label fw-semibold">Person's Name</label>
                    <div class="position-relative">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person" id="editUserIcon"></i></span>
                            <input type="text" class="form-control" id="editUserName" name="user_name" required autocomplete="off">
                        </div>
                        <!-- Directory name suggestions (filled by name-autocomplete.js) -->
                        <ul id="editUserSuggestions" class="dropdown-menu w-100 user-suggestions" role="listbox"></ul>
                    </div>
                    <input type="hidden" name="user_id" id="editUserId">
                    <div class="form-text" id="editUserLink"></div>
                </div>
                <div class="mb-3">
                    <label for="editCheckoutTime" class="form-label fw-semibold">Check-Out Time</label>
                    <input type="text" class="form-control" id="editCheckoutTime" name="checkout_time" required>
                    <div class="form-text">Format: YYYY-MM-DD HH:MM:SS</div>
                </div>
                <div class="mb-3">
                    <label for="editCheckinTime" class="form-label fw-semibold">Check-In Time</label>
                    <input type="text" class="form-control" id="editCheckinTime" name="checkin_time">
                    <div class="form-text">Format: YYYY-MM-DD HH:MM:SS (Leave blank if currently out)</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn bg-secondary text-white" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Overdue Email Preview Modal -->
<div class="modal fade" id="emailPreviewModal" tabindex="-1" aria-labelledby="emailPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="emailPreviewModalLabel">Email Preview</h5>
                    <div class="small text-muted">Uses today's overdue devices if there are any, otherwise sample data.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="emailPreviewFrame" title="Email preview" style="width: 100%; height: 70vh; border: 0; display: block;"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- Photo Verification Modal (1000px wide layout) -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" style="max-width: 1040px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="photoModalLabel">Checkout Verification Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 text-center bg-dark rounded-bottom">
                <div class="mb-3 text-white small" id="photoMeta"></div>
                <img id="verificationImage" src="" alt="Verification Photo" class="img-fluid rounded border border-secondary" style="max-width: 1000px; width: 100%; height: auto; object-fit: contain;">
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/name-autocomplete.js"></script>
<script src="assets/js/admin.js"></script>
</body>
</html>
