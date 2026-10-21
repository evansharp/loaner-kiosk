<?php
/**
 * Admin dashboard view.
 *
 * @var ?Alert      $alert
 * @var array       $allRecords
 * @var array       $deviceStats  asset_number => total_seconds
 * @var array       $topUsers     user_name => total_seconds
 * @var PhotoStore  $photos
 */

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
</head>
<body>

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
            <a href="index.php" class="btn btn-outline-secondary btn-sm fw-semibold">
                <i class="bi bi-display me-1"></i> Open Kiosk View
            </a>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($alert): ?>
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
                            <?php $rank = 1; foreach ($topUsers as $userName => $seconds): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary rounded-circle p-2" style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;"><?= $rank++ ?></span>
                                        <span class="fw-semibold text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($userName) ?>"><?= htmlspecialchars($userName) ?></span>
                                    </div>
                                    <span class="badge bg-light text-dark border font-monospace"><?= UsageStats::formatDuration($seconds) ?></span>
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
                                            data-user="<?= htmlspecialchars(strtolower($record['user_name'])) ?>"
                                            data-dateout="<?= $checkoutDateOnly ?>"
                                            data-datein="<?= $checkinDateOnly ?>">
                                            <td class="text-muted small"><?= (int)$record['id'] ?></td>
                                            <td><span class="badge bg-light text-dark border font-monospace">#<?= htmlspecialchars($record['asset_number']) ?></span></td>
                                            <td class="fw-semibold"><?= htmlspecialchars($record['user_name']) ?></td>
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
                                                        data-checkout="<?= htmlspecialchars($record['checkout_time']) ?>"
                                                        data-checkin="<?= htmlspecialchars($record['checkin_time'] ?? '') ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" action="admin.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete record #<?= (int)$record['id'] ?>?');">
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
</div>

<!-- Edit Record Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="admin.php" class="modal-content">
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
                    <input type="text" class="form-control" id="editUserName" name="user_name" required>
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
<script src="assets/js/admin.js"></script>
</body>
</html>
