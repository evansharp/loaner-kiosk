<?php
// Set timezone (Adjust to your local timezone if needed)
date_default_timezone_set('America/Vancouver');

/**
 * Parses and validates an asset tag input.
 * Accepts:
 *   1. Direct integer Asset IDs (e.g., "10")
 *   2. Asset URL pattern ending with an integer ID (e.g., "HTTPS://ASSETS.COASTMOUNTAINACADEMY.CA/HARDWARE/10")
 *
 * Returns extracted integer asset number on success, or null if invalid.
 */
function parseAssetNumber(string $input): ?string {
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    // Direct integer asset ID (e.g., "10")
    if (preg_match('/^\d+$/', $input)) {
        return $input;
    }

    // URL pattern ending with an integer segment (case-insensitive)
    if (preg_match('/^https?:\/\/[^\s]+\/(\d+)\/?$/i', $input, $matches)) {
        return $matches[1];
    }

    return null; // Invalid input
}

// 1. Initialize SQLite Database using PDO
$dbFile = __DIR__ . '/chromebooks.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS checkouts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        asset_number TEXT NOT NULL,
        user_name TEXT NOT NULL,
        checkout_time DATETIME NOT NULL,
        checkin_time DATETIME DEFAULT NULL,
        photo_uuid TEXT DEFAULT NULL
    )");

    // Ensure only one active checkout per asset number at a time
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_single_active_asset ON checkouts(asset_number) WHERE checkin_time IS NULL");
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

// State variables for UX feedback
$message = null;
$messageType = null; // success, warning, danger
$soundType = null;   // success, warning, error
$flashClass = null;  // flash-success, flash-warning, flash-danger
$prefillAsset = '';
$prefillUser = '';
$focusTarget = 'asset_number'; // Input ID to focus on load
$autoReset = false;           // Trigger auto-reset timer in JS

// 2. Handle POST Actions (Smart Scan Logic + Asset URL Parsing)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawAsset = trim($_POST['asset_number'] ?? '');
    $userName = trim($_POST['user_name'] ?? '');
    $checkoutId = isset($_POST['checkout_id']) ? (int)$_POST['checkout_id'] : null;

    // Validate & parse asset number
    $assetNumber = parseAssetNumber($rawAsset);

    if ($checkoutId && $checkoutId > 0) {
        // --- DIRECT CHECK-IN BY RECORD ID (Quick Action) ---
        $updateStmt = $pdo->prepare("UPDATE checkouts SET checkin_time = :time WHERE id = :id AND checkin_time IS NULL");
        $updateStmt->execute([
            ':time' => date('Y-m-d H:i:s'),
            ':id' => $checkoutId
        ]);

        if ($updateStmt->rowCount() > 0) {
            $message = "<strong>CHECK-IN SUCCESS!</strong> Chromebook <strong>#" . htmlspecialchars($rawAsset) . "</strong> returned.";
            $messageType = "success";
            $soundType = "success";
            $flashClass = "flash-success";
        } else {
            $message = "<strong>NOTICE:</strong> This Chromebook was already checked in or record not found.";
            $messageType = "warning";
            $soundType = "warning";
            $flashClass = "flash-warning";
        }
        $autoReset = true;
    } elseif (empty($rawAsset)) {
        $message = "Please scan or enter a Chromebook Asset Tag.";
        $messageType = "danger";
        $soundType = "error";
        $flashClass = "flash-danger";
        $focusTarget = "asset_number";
    } elseif ($assetNumber === null) {
        // REJECT invalid inputs
        $message = "<strong>INVALID ASSET TAG:</strong> Scanned input must be a valid Asset URL or numeric Asset ID.";
        $messageType = "danger";
        $soundType = "error";
        $flashClass = "flash-danger";
        $focusTarget = "asset_number";
    } else {
        // Check if device is currently checked out
        $stmt = $pdo->prepare("SELECT * FROM checkouts WHERE asset_number = :asset AND checkin_time IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([':asset' => $assetNumber]);
        $activeRecord = $stmt->fetch();

        if ($activeRecord) {
            // --- SMART CHECK-IN ---
            $updateStmt = $pdo->prepare("UPDATE checkouts SET checkin_time = :time WHERE id = :id");
            $updateStmt->execute([
                ':time' => date('Y-m-d H:i:s'),
                ':id' => $activeRecord['id']
            ]);

            $message = "<strong>CHECK-IN SUCCESS!</strong> Chromebook <strong>#" . htmlspecialchars($assetNumber) . "</strong> (previously with " . htmlspecialchars($activeRecord['user_name']) . ") returned.";
            $messageType = "success";
            $soundType = "success";
            $flashClass = "flash-success";
            $autoReset = true;
        } else {
            // --- SMART CHECK-OUT ---
            if (!empty($userName)) {
                // Validate full name (require at least 2 words: e.g., First and Last name)
                $nameWords = preg_split('/\s+/', $userName);
                if (count($nameWords) < 2) {
                    $message = "<strong>INVALID NAME:</strong> Please enter the full name (first and last name required).";
                    $messageType = "danger";
                    $soundType = "error";
                    $flashClass = "flash-danger";
                    $prefillAsset = $assetNumber;
                    $prefillUser = $userName;
                    $focusTarget = "user_name";
                } else {
                    // Photo Handling
                    $photoUuid = $_POST['photo_uuid'] ?? null;
                    $photoBlob = $_POST['photo_blob'] ?? null;

                    if ($photoUuid && $photoBlob) {
                        $photoBlob = str_replace('data:image/jpeg;base64,', '', $photoBlob);
                        $photoBlob = str_replace(' ', '+', $photoBlob);
                        $data = base64_decode($photoBlob);

                        $year = date('Y');
                        $month = date('m');
                        $day = date('d');
                        $dir = __DIR__ . "/checkout_verification/$year/$month/$day";
                        
                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }

                        $filePath = "$dir/$photoUuid.jpg";
                        file_put_contents($filePath, $data);
                    }

                    // Name provided and valid -> Complete Check-Out
                    $insertStmt = $pdo->prepare("INSERT INTO checkouts (asset_number, user_name, checkout_time, photo_uuid) VALUES (:asset, :name, :time, :uuid)");
                    $insertStmt->execute([
                        ':asset' => $assetNumber,
                        ':name' => $userName,
                        ':time' => date('Y-m-d H:i:s'),
                        ':uuid' => $photoUuid
                    ]);

                    $message = "<strong>CHECK-OUT SUCCESS!</strong> Chromebook <strong>#" . htmlspecialchars($assetNumber) . "</strong> assigned to <strong>" . htmlspecialchars($userName) . "</strong>.";
                    $messageType = "success";
                    $soundType = "success";
                    $flashClass = "flash-success";
                    $autoReset = true;
                }
            } else {
                // Name missing -> Prompt user to enter Name
                $message = "Chromebook <strong>#" . htmlspecialchars($assetNumber) . "</strong> is available. Please enter/scan <strong>Person's Name</strong> to finish check-out.";
                $messageType = "warning";
                $soundType = "warning";
                $flashClass = "flash-warning";
                $prefillAsset = $assetNumber;
                $focusTarget = "user_name";
            }
        }
    }
}

// 3. Fetch History (All records fetched for client-side live filtering in kiosk mode)
$stmt = $pdo->prepare("SELECT * FROM checkouts ORDER BY id DESC");
$stmt->execute();
$checkouts = $stmt->fetchAll();

// 4. Quick Statistics
$totalCheckouts = $pdo->query("SELECT COUNT(*) FROM checkouts")->fetchColumn();
$currentlyOut = $pdo->query("SELECT COUNT(*) FROM checkouts WHERE checkin_time IS NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coast Mountain Academy — Chromebook Kiosk</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; }
        .kiosk-card { border-radius: 12px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05); transition: all 0.3s ease; }
        .brand-logo { max-height: 55px; width: auto; object-fit: contain; }
        .badge-active { background-color: #ffc107; color: #000; }
        .badge-returned { background-color: #198754; }

        /* Keyframe Flash Animations for Visual Confirmation */
        @keyframes flashSuccess {
            0% { background-color: #d1e7dd; box-shadow: 0 0 20px rgba(25, 135, 84, 0.4); }
            100% { background-color: #ffffff; }
        }
        @keyframes flashWarning {
            0% { background-color: #fff3cd; box-shadow: 0 0 20px rgba(255, 193, 7, 0.4); }
            100% { background-color: #ffffff; }
        }
        @keyframes flashDanger {
            0% { background-color: #f8d7da; box-shadow: 0 0 20px rgba(220, 53, 69, 0.4); }
            100% { background-color: #ffffff; }
        }
        .flash-success { animation: flashSuccess 1.2s ease-out; }
        .flash-warning { animation: flashWarning 1.2s ease-out; }
        .flash-danger { animation: flashDanger 1.2s ease-out; }
    </style>
</head>
<body>

<div class="container py-4" style="max-width: 1000px;">
    <!-- Coast Mountain Academy Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <img src="logo.png" alt="Coast Mountain Academy" class="brand-logo" onerror="this.style.display='none'">
            <div>
                <h1 class="mb-0">Chromebook Self-Checkout</h1>
            </div>
        </div>
        <div class="d-flex gap-2">
            <div class="text-center bg-white px-3 py-2 rounded border">
                <span class="d-block h5 mb-0 fw-bold text-primary"><?= $currentlyOut ?></span>
                <span class="small text-muted">Currently Out</span>
            </div>
            <div class="text-center bg-white px-3 py-2 rounded border">
                <span class="d-block h5 mb-0 fw-bold text-secondary"><?= $totalCheckouts ?></span>
                <span class="small text-muted">Total Logs</span>
            </div>
        </div>
    </div>

    <!-- Alert Message Banner -->
    <?php if ($message): ?>
        <div id="statusAlert" class="alert alert-<?= $messageType ?> alert-dismissible fade show kiosk-card fs-5 py-3" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Smart Scan Form Card -->
    <div class="card kiosk-card mb-4 <?= $flashClass ?>">
        <div class="card-body p-4">
            <form method="POST" action="index.php" id="kioskForm" class="row g-3">

                <!-- Field 1: Asset Number Scan -->
                <div class="col-md-5">
                    <label for="asset_number" class="form-label fw-bold fs-5">1. Identify Chromebook</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><i class="bi bi-qr-code-scan"></i></span>
                        <input type="text" class="form-control" id="asset_number" name="asset_number"
                               placeholder="Scan tag or enter ID #" value="<?= htmlspecialchars($prefillAsset) ?>"
                               autocomplete="off" required>
                    </div>
                </div>

                <!-- Field 2: Person's Name -->
                <div class="col-md-5">
                    <label for="user_name" class="form-label fw-bold fs-5">2. Checkout To</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="user_name" name="user_name"
                               placeholder="Student Name" value="<?= htmlspecialchars($prefillUser) ?>"
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
            <h3> Activity Log </h3>
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

<script>
// --- Web Audio API Chimes ---
function playChime(type) {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();

        if (type === 'success') {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, ctx.currentTime);
            osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1);
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.35);
        } else if (type === 'warning') {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(440, ctx.currentTime);
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.25);
        } else if (type === 'error') {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(160, ctx.currentTime);
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        }
    } catch (e) {
        console.warn("Audio playback issue:", e);
    }
}

// Initial setup on load
document.addEventListener("DOMContentLoaded", () => {
    const focusTarget = document.getElementById("<?= $focusTarget ?>");
    if (focusTarget) {
        focusTarget.focus();
        focusTarget.select();
    }

    <?php if ($soundType): ?>
        playChime("<?= $soundType ?>");
    <?php endif; ?>

    <?php if ($autoReset): ?>
        setTimeout(() => {
            const alertEl = document.getElementById('statusAlert');
            if (alertEl) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
                bsAlert.close();
            }

            document.getElementById('asset_number').value = '';
            document.getElementById('user_name').value = '';

            const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
            if (activeTag !== 'input' && activeTag !== 'select' && activeTag !== 'textarea') {
                const assetInput = document.getElementById('asset_number');
                if (assetInput) {
                    assetInput.focus();
                }
            }
        }, 4000);
    <?php endif; ?>

    // --- Client-side Live Filtering ---
    const filterQueryInput = document.getElementById('filter_query');
    const filterStatusSelect = document.getElementById('filter_status');
    const historyTable = document.getElementById('historyTable');

    if (filterQueryInput && historyTable) {
        function performLiveFilter() {
            const query = filterQueryInput.value.toLowerCase().trim();
            const statusFilter = filterStatusSelect ? filterStatusSelect.value : 'all';
            const rows = historyTable.querySelectorAll('tbody tr[data-asset]');
            const noResultsRow = document.getElementById('noResultsRow');
            let visibleCount = 0;

            rows.forEach(row => {
                const asset = row.getAttribute('data-asset') || '';
                const name = row.getAttribute('data-name') || '';
                const status = row.getAttribute('data-status') || '';

                const matchesQuery = query === '' || asset.includes(query) || name.includes(query);
                const matchesStatus = statusFilter === 'all' || status === statusFilter;

                if (matchesQuery && matchesStatus) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (noResultsRow) {
                noResultsRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
            }
        }

        filterQueryInput.addEventListener('keyup', performLiveFilter);
        filterQueryInput.addEventListener('input', performLiveFilter);
        if (filterStatusSelect) {
            filterStatusSelect.addEventListener('change', performLiveFilter);
        }
    }

    // --- Inactivity Reset Timer (5 Seconds) ---
    let inactivityTimer;
    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(() => {
            const assetInput = document.getElementById('asset_number');
            const userInput = document.getElementById('user_name');
            const filterInput = document.getElementById('filter_query');
            const statusSelect = document.getElementById('filter_status');
            const alertEl = document.getElementById('statusAlert');

            let hasValues = false;
            if (assetInput && assetInput.value !== '') hasValues = true;
            if (userInput && userInput.value !== '') hasValues = true;
            if (filterInput && filterInput.value !== '') hasValues = true;
            if (statusSelect && statusSelect.value !== 'all') hasValues = true;

            if (hasValues) {
                if (assetInput) assetInput.value = '';
                if (userInput) userInput.value = '';
                if (filterInput) {
                    filterInput.value = '';
                    filterInput.dispatchEvent(new Event('input'));
                }
                if (statusSelect) {
                    statusSelect.value = 'all';
                    statusSelect.dispatchEvent(new Event('change'));
                }
                if (alertEl) {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
                    bsAlert.close();
                }
            }

            if (assetInput) {
                assetInput.focus();
                assetInput.select();
            }
        }, 5000);
    }

    // Reset timer on user activity events
    ['mousemove', 'mousedown', 'keypress', 'touchstart', 'scroll', 'input'].forEach(event => {
        document.addEventListener(event, resetInactivityTimer, true);
    });

    // Initialize timer on load
    resetInactivityTimer();

    // --- Camera Handling & Photo Capture ---
    const kioskForm = document.getElementById('kioskForm');
    const cameraPreview = document.getElementById('cameraPreview');
    const photoCanvas = document.getElementById('photoCanvas');
    const photoUuidInput = document.getElementById('photo_uuid');
    const photoBlobInput = document.getElementById('photo_blob');
    let stream = null;

    async function startCamera() {
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
            cameraPreview.srcObject = stream;
        } catch (err) {
            console.error("Camera access denied or unavailable:", err);
        }
    }

    // Start camera immediately
    startCamera();

    kioskForm.addEventListener('submit', async (e) => {
        // Only capture photo if user_name is being submitted (check-out)
        const userName = document.getElementById('user_name').value.trim();
        const assetNum = document.getElementById('asset_number').value.trim();
        
        // We only want to capture if both are present (Checkout flow)
        if (userName !== '' && assetNum !== '') {
            // Prevent submission briefly to capture photo
            e.preventDefault();

            if (stream) {
                const context = photoCanvas.getContext('2d');
                photoCanvas.width = cameraPreview.videoWidth;
                photoCanvas.height = cameraPreview.videoHeight;
                context.drawImage(cameraPreview, 0, 0, photoCanvas.width, photoCanvas.height);
                
                // Generate simple UUID
                const uuid = crypto.randomUUID();
                photoUuidInput.value = uuid;
                photoBlobInput.value = photoCanvas.toDataURL('image/jpeg', 0.8);
            }
            
            // Now submit the form
            kioskForm.submit();
        }
    });
});
</script>
</body>
</html>
