<?php
/**
 * Kiosk page view.
 *
 * @var KioskState $state
 * @var array      $checkouts
 * @var int        $totalCheckouts
 * @var int        $currentlyOut
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loaner Chromebook Kiosk</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/kiosk.css">
</head>
<body data-focus-target="<?= htmlspecialchars($state->focusTarget) ?>"
      data-sound="<?= htmlspecialchars($state->soundType ?? '') ?>"
      data-auto-reset="<?= $state->autoReset ? 'true' : 'false' ?>">

<div class="container py-4" style="max-width: 1000px;">
    <!-- Coast Mountain Academy Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <img src="logo.png" alt="Coast Mountain Academy" class="brand-logo" onerror="this.style.display='none'">
            <div>
                <h1 class="mb-0">Loaner Chromebook Checkout</h1>
            </div>
        </div>
        <div class="d-flex gap-2">
            <div class="text-center bg-white px-3 py-2 rounded border">
                <span class="d-block h5 mb-0 fw-bold text-primary"><?= $currentlyOut ?></span>
                <span class="small text-muted">Currently Out</span>
            </div>
        </div>
    </div>

    <!-- Alert Message Banner -->
    <?php if ($state->message): ?>
        <div id="statusAlert" class="alert alert-<?= $state->messageType ?> alert-dismissible fade show kiosk-card fs-5 py-3" role="alert">
            <?= $state->message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Smart Scan Form Card -->
    <div class="card kiosk-card mb-4 <?= $state->flashClass ?>">
        <div class="card-body p-4">
            <form method="POST" action="index.php" id="kioskForm" class="row g-3">

                <!-- Field 1: Asset Number Scan -->
                <div class="col-md-5">
                    <label for="asset_number" class="form-label fw-bold fs-5">1. Identify Chromebook</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><i class="bi bi-qr-code-scan"></i></span>
                        <input type="text" class="form-control" id="asset_number" name="asset_number"
                               placeholder="Scan tag or type asset #" value="<?= htmlspecialchars($state->prefillAsset) ?>"
                               autocomplete="off" required>
                    </div>
                </div>

                <!-- Field 2: Person's Name -->
                <div class="col-md-5">
                    <label for="user_name" class="form-label fw-bold fs-5">2. Checkout To</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="user_name" name="user_name"
                               placeholder="Type student name" value="<?= htmlspecialchars($state->prefillUser) ?>"
                               autocomplete="off">
                    </div>
                </div>

                <input type="hidden" name="photo_uuid" id="photo_uuid">
                <input type="hidden" name="photo_blob" id="photo_blob">

                <!-- Submit Button (Vertically Aligned) -->
                <div class="col-md-2">
                    <label class="form-label fs-5 d-none d-md-block">&nbsp;</label>
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">
                        Save
                    </button>
                </div>
            </form>

            <!-- Camera Preview (Hidden, used for capture) -->
            <video id="cameraPreview" autoplay playsinline style="display:none;"></video>
            <canvas id="photoCanvas" style="display:none;"></canvas>
        </div>
    </div>

    <!-- History & Filtering Card -->
    <div class="card kiosk-card">
        <div class="card-body p-4">
            <div class="row justify-content-between mb-3">
                <div class="col-md-6 align-self-center">
                    <h3> Activity Log </h3>
                </div>
                <div class="col-md-2">
                    <div class="text-center bg-white px-3 py-2 rounded border">
                        <span class="d-block h5 mb-0 fw-bold text-secondary"><?= $totalCheckouts ?></span>
                        <span class="small text-muted">Total Logs</span>
                    </div>
                </div>
            </div>
            <!-- Filter Bar (Searches Asset # OR Name) -->
            <div class="row g-3 mb-4 align-items-end">
                <div class="col-md-7">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="filter_query" class="form-control"
                               placeholder="Type to filter table..." autocomplete="off">
                    </div>
                </div>
                <div class="col-md-5">
                    <select id="filter_status" class="form-select">
                        <option value="all" selected>Checked In and Out</option>
                        <option value="active">Out Only</option>
                        <option value="returned">In Only</option>
                    </select>
                </div>
            </div>

            <!-- Checkout History Table -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="historyTable">
                    <thead class="table-light">
                        <tr>
                            <th>Asset #</th>
                            <th>Person's Name</th>
                            <th>Check-Out Time</th>
                            <th>Check-In Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($checkouts)): ?>
                            <tr id="emptyStaticRow">
                                <td colspan="5" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                    No records found in database.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($checkouts as $row): ?>
                                <?php
                                    $isOut = is_null($row['checkin_time']);
                                    $outTime = date('M j, Y — g:i A', strtotime($row['checkout_time']));
                                    $inTime = $isOut ? '—' : date('M j, Y — g:i A', strtotime($row['checkin_time']));
                                ?>
                                <tr data-asset="<?= htmlspecialchars(strtolower($row['asset_number'])) ?>"
                                    data-name="<?= htmlspecialchars(strtolower($row['user_name'])) ?>"
                                    data-status="<?= $isOut ? 'active' : 'returned' ?>">
                                    <td>
                                        <span class="fw-bold font-monospace badge bg-light text-dark border fs-6">
                                            <?= htmlspecialchars($row['asset_number']) ?>
                                        </span>
                                    </td>
                                    <td class="fw-semibold"><?= htmlspecialchars($row['user_name']) ?></td>
                                    <td><small class="text-secondary"><?= $outTime ?></small></td>
                                    <td><small class="text-secondary"><?= $inTime ?></small></td>
                                    <td>
                                        <?php if ($isOut): ?>
                                            <span class="badge badge-active px-2 py-1"><i class="bi bi-clock me-1"></i>Checked Out</span>
                                        <?php else: ?>
                                            <span class="badge badge-returned px-2 py-1"><i class="bi bi-check-circle me-1"></i>Checked In</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr id="noResultsRow" style="display: none;">
                            <td colspan="5" class="text-center py-4 text-muted">
                                <i class="bi bi-search fs-3 d-block mb-1"></i>
                                No matching records found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/kiosk.js"></script>
</body>
</html>
