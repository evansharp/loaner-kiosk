<?php
// Set timezone
date_default_timezone_set('America/Vancouver');

$dbFile = __DIR__ . '/chromebooks.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS checkouts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        asset_number TEXT NOT NULL,
        user_name TEXT NOT NULL,
        checkout_time DATETIME NOT NULL,
        checkin_time DATETIME DEFAULT NULL
    )");
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

$message = null;
$messageType = null;

// Handle Admin Actions (Delete / Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['record_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM checkouts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $message = "Record #$id successfully deleted.";
            $messageType = "success";
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['record_id'] ?? 0);
        $assetNumber = trim($_POST['asset_number'] ?? '');
        $userName = trim($_POST['user_name'] ?? '');
        $checkoutTime = trim($_POST['checkout_time'] ?? '');
        $checkinTime = trim($_POST['checkin_time'] ?? '');
        $checkinTime = ($checkinTime === '') ? null : $checkinTime;

        if ($id > 0 && !empty($assetNumber) && !empty($userName) && !empty($checkoutTime)) {
            $stmt = $pdo->prepare("UPDATE checkouts SET asset_number = :asset, user_name = :name, checkout_time = :checkout, checkin_time = :checkin WHERE id = :id");
            $stmt->execute([
                ':asset' => $assetNumber,
                ':name' => $userName,
                ':checkout' => $checkoutTime,
                ':checkin' => $checkinTime,
                ':id' => $id
            ]);
            $message = "Record #$id successfully updated.";
            $messageType = "success";
        } else {
            $message = "Error: Asset number, user name, and check-out time are required.";
            $messageType = "danger";
        }
    }
}

// Fetch all checkouts for calculations and table
$stmt = $pdo->query("SELECT * FROM checkouts ORDER BY checkout_time DESC");
$allRecords = $stmt->fetchAll();

// 1. Calculate device time checked out (in seconds) & build statistics
$deviceStats = []; // asset_number => total_seconds
$userStats = [];   // user_name => total_seconds

$now = time();

foreach ($allRecords as $record) {
    $outTime = strtotime($record['checkout_time']);
    $inTime = $record['checkin_time'] ? strtotime($record['checkin_time']) : $now;
    
    if ($outTime && $inTime && $inTime >= $outTime) {
        $duration = $inTime - $outTime;
        
        // Device stats
        $asset = $record['asset_number'];
        if (!isset($deviceStats[$asset])) {
            $deviceStats[$asset] = 0;
        }
        $deviceStats[$asset] += $duration;

        // User stats
        $user = $record['user_name'];
        if (!isset($userStats[$user])) {
            $userStats[$user] = 0;
        }
        $userStats[$user] += $duration;
    }
}

// Sort devices by total time descending
arsort($deviceStats);

// 3. Top 5 users by time checked out
arsort($userStats);
$topUsers = array_slice($userStats, 0, 5, true);

// Helper function to format seconds into readable hours/minutes
function formatDuration($seconds) {
    if ($seconds <= 0) return '0m';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    if ($hours > 0) {
        return "{$hours}h {$minutes}m";
    }
    return "{$minutes}m";
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
    <style>
        body { background-color: #f4f6f9; }
        .admin-card { border-radius: 12px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .brand-logo { max-height: 45px; width: auto; object-fit: contain; }
        .chart-container { width: 100%; min-height: 350px; }
        .axis path, .axis line { stroke: #cbd5e1; }
        .bar { fill: #0d6efd; transition: fill 0.2s; }
        .bar:hover { fill: #0b5ed7; }
    </style>
</head>
<body>

<div class="container py-4" style="max-width: 1200px;">
    <!-- Top Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <img src="logo.png" alt="Coast Mountain Academy" class="brand-logo" onerror="this.style.display='none'">
            <div>
                <h1 class="h3 mb-0 fw-bold">Admin Dashboard & Analytics</h1>
                <p class="text-muted small mb-0">Loaner Kiosk Management System</p>
            </div>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm fw-semibold">
                <i class="bi bi-display me-1"></i> Open Kiosk View
            </a>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show admin-card mb-4" role="alert">
            <?= htmlspecialchars($message) ?>
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
                    <div id="deviceChart" class="chart-container"></div>
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
                                    <span class="badge bg-light text-dark border font-monospace"><?= formatDuration($seconds) ?></span>
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
                                            <td class="fw-semibold"><?= formatDuration($seconds) ?> <span class="text-muted fw-normal small">(<?= number_format($seconds) ?>s)</span></td>
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
                                        ?>
                                        <tr data-asset="<?= htmlspecialchars(strtolower($record['asset_number'])) ?>"
                                            data-user="<?= htmlspecialchars(strtolower($record['user_name'])) ?>"
                                            data-dateout="<?= $checkoutDateOnly ?>"
                                            data-datein="<?= $checkinDateOnly ?>">
                                            <td class="text-muted small">#<?= (int)$record['id'] ?></td>
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
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// --- D3.js Device Time Chart ---
document.addEventListener("DOMContentLoaded", () => {
    const rawData = [
        <?php foreach ($deviceStats as $asset => $seconds): ?>
        { asset: "#<?= htmlspecialchars($asset) ?>", hours: +(<?= $seconds ?> / 3600).toFixed(2) },
        <?php endforeach; ?>
    ];

    if (rawData.length > 0) {
        const container = d3.select("#deviceChart");
        const containerWidth = container.node().getBoundingClientRect().width || 700;
        const margin = { top: 20, right: 30, bottom: 60, left: 60 };
        const width = containerWidth - margin.left - margin.right;
        const height = 320 - margin.top - margin.bottom;

        const svg = container.append("svg")
            .attr("width", width + margin.left + margin.right)
            .attr("height", height + margin.top + margin.bottom)
            .append("g")
            .attr("transform", `translate(${margin.left},${margin.top})`);

        // X axis
        const x = d3.scaleBand()
            .range([0, width])
            .domain(rawData.map(d => d.asset))
            .padding(0.3);

        svg.append("g")
            .attr("transform", `translate(0,${height})`)
            .call(d3.axisBottom(x))
            .selectAll("text")
            .attr("transform", "translate(-10,0)rotate(-35)")
            .style("text-anchor", "end");

        // Y axis
        const y = d3.scaleLinear()
            .domain([0, d3.max(rawData, d => d.hours) * 1.1 || 10])
            .range([height, 0]);

        svg.append("g")
            .call(d3.axisLeft(y));

        // Bars
        svg.selectAll(".bar")
            .data(rawData)
            .enter()
            .append("rect")
            .attr("class", "bar")
            .attr("x", d => x(d.asset))
            .attr("y", d => y(d.hours))
            .attr("width", x.bandwidth())
            .attr("height", d => height - y(d.hours))
            .append("title")
            .text(d => `${d.asset}: ${d.hours} hours`);
    } else {
        d3.select("#deviceChart").html("<p class='text-muted text-center py-5'>No chart data available.</p>");
    }

    // --- Advanced Table Filtering ---
    const filterDevice = document.getElementById('filterDevice');
    const filterUser = document.getElementById('filterUser');
    const filterDateOut = document.getElementById('filterDateOut');
    const filterDateIn = document.getElementById('filterDateIn');
    const resetFiltersBtn = document.getElementById('resetFilters');
    const adminTable = document.getElementById('adminTable');

    function applyAdminFilters() {
        const devQuery = filterDevice.value.toLowerCase().trim();
        const usrQuery = filterUser.value.toLowerCase().trim();
        const dateOutQuery = filterDateOut.value.trim();
        const dateInQuery = filterDateIn.value.trim();

        const rows = adminTable.querySelectorAll('tbody tr[data-asset]');
        const noResults = document.getElementById('adminNoResults');
        let visibleCount = 0;

        rows.forEach(row => {
            const asset = row.getAttribute('data-asset') || '';
            const user = row.getAttribute('data-user') || '';
            const dateOut = row.getAttribute('data-dateout') || '';
            const dateIn = row.getAttribute('data-datein') || '';

            const matchDev = devQuery === '' || asset.includes(devQuery);
            const matchUsr = usrQuery === '' || user.includes(usrQuery);
            const matchDateOut = dateOutQuery === '' || dateOut === dateOutQuery;
            const matchDateIn = dateInQuery === '' || dateIn.includes(dateInQuery);

            if (matchDev && matchUsr && matchDateOut && matchDateIn) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        if (noResults) {
            noResults.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
        }
    }

    [filterDevice, filterUser, filterDateOut, filterDateIn].forEach(el => {
        el.addEventListener('input', applyAdminFilters);
        el.addEventListener('change', applyAdminFilters);
    });

    resetFiltersBtn.addEventListener('click', () => {
        filterDevice.value = '';
        filterUser.value = '';
        filterDateOut.value = '';
        filterDateIn.value = '';
        applyAdminFilters();
    });

    // --- Modal Populate for Editing ---
    const editModal = document.getElementById('editModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            document.getElementById('editRecordId').value = button.getAttribute('data-id');
            document.getElementById('editAssetNumber').value = button.getAttribute('data-asset');
            document.getElementById('editUserName').value = button.getAttribute('data-user');
            document.getElementById('editCheckoutTime').value = button.getAttribute('data-checkout');
            document.getElementById('editCheckinTime').value = button.getAttribute('data-checkin');
        });
    }
});
</script>
</body>
</html>
