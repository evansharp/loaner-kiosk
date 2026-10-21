<?php
/**
 * Admin login view.
 *
 * @var bool        $passwordIsSet
 * @var string|null $loginError
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coast Mountain Academy — Admin Login</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>

<div class="container py-5" style="max-width: 420px;">
    <div class="text-center mb-4">
        <img src="logo.png" alt="Coast Mountain Academy" class="brand-logo mb-3" onerror="this.style.display='none'">
        <h1 class="h4 fw-bold mb-0">Admin Dashboard</h1>
        <p class="text-muted small">Loaner Chromebook Tracking System</p>
    </div>

    <div class="card admin-card">
        <div class="card-body p-4">
            <?php if (!$passwordIsSet): ?>
                <h2 class="h6 fw-bold"><i class="bi bi-lock-fill me-1"></i> No admin password has been set</h2>
                <p class="small mb-2">On the kiosk Mac, open Terminal in the project folder and run:</p>
                <pre class="bg-light border rounded p-2 small mb-0">php bin/set_admin_password.php</pre>
            <?php else: ?>
                <form method="POST" action="admin.php">
                    <input type="hidden" name="action" value="login">
                    <?php if ($loginError): ?>
                        <div class="alert alert-danger py-2 small" role="alert"><?= htmlspecialchars($loginError) ?></div>
                    <?php endif; ?>
                    <label for="password" class="form-label fw-semibold">Password</label>
                    <input type="password" class="form-control mb-3" id="password" name="password" autocomplete="current-password" required autofocus>
                    <button type="submit" class="btn btn-primary w-100 fw-semibold">Log In</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-center mt-3">
        <a href="index.php" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to the kiosk</a>
    </div>
</div>

</body>
</html>
