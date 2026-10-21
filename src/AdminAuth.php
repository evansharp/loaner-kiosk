<?php

/**
 * Password login for the admin page.
 *
 * The admin page is used in the same browser as the student-facing kiosk, so sessions
 * end after IDLE_TIMEOUT seconds without activity, and whenever the kiosk page loads.
 * The password hash lives in the settings table; set it with bin/set_admin_password.php.
 */
class AdminAuth
{
    const IDLE_TIMEOUT = 600; // Seconds
    const MAX_FAILED_LOGINS = 5;
    const LOCKOUT_SECONDS = 300;

    const SESSION_NAME = 'kiosk_admin';
    const PASSWORD_HASH_KEY = 'admin_password_hash';
    const FAILED_COUNT_KEY = 'admin_failed_logins';
    const FAILED_SINCE_KEY = 'admin_failed_logins_since';

    /** @var SettingsRepository */
    private $settings;

    public function __construct(SettingsRepository $settings)
    {
        $this->settings = $settings;
    }

    public function hasPassword(): bool
    {
        return $this->settings->get(self::PASSWORD_HASH_KEY, '') !== '';
    }

    public function setPassword(string $password): void
    {
        $this->settings->setMany([
            self::PASSWORD_HASH_KEY => password_hash($password, PASSWORD_DEFAULT),
            self::FAILED_COUNT_KEY => '0',
        ]);
    }

    /**
     * Whether an admin is logged in. Ends the session if it has been idle too long.
     */
    public function check(): bool
    {
        $this->startSession();

        $lastSeen = $_SESSION['last_seen'] ?? 0;
        if (empty($_SESSION['admin']) || time() - $lastSeen > self::IDLE_TIMEOUT) {
            $this->endSession();
            return false;
        }

        $_SESSION['last_seen'] = time();
        return true;
    }

    /**
     * @return string|null An error message, or null on success
     */
    public function login(string $password): ?string
    {
        $lockedFor = $this->lockoutRemaining();
        if ($lockedFor > 0) {
            return 'Too many incorrect attempts. Try again in ' . ceil($lockedFor / 60) . ' minute' . ($lockedFor > 60 ? 's' : '') . '.';
        }

        $hash = $this->settings->get(self::PASSWORD_HASH_KEY, '');
        if ($hash === '' || !password_verify($password, $hash)) {
            $this->recordFailedLogin();
            return 'Incorrect password.';
        }

        $this->settings->setMany([self::FAILED_COUNT_KEY => '0']);

        $this->startSession();
        session_regenerate_id(true);
        $_SESSION = [
            'admin' => true,
            'last_seen' => time(),
            'csrf' => bin2hex(random_bytes(32)),
        ];
        return null;
    }

    /**
     * Ends any admin session. Cheap to call on every kiosk page load.
     */
    public function logout(): void
    {
        if (!isset($_COOKIE[self::SESSION_NAME])) {
            return;
        }
        $this->startSession();
        $this->endSession();
    }

    /**
     * Token to include in admin forms (as "csrf") so other pages can't submit them.
     */
    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public function checkCsrf($token): bool
    {
        return is_string($token) && $this->csrfToken() !== '' && hash_equals($this->csrfToken(), $token);
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    private function endSession(): void
    {
        $_SESSION = [];
        session_destroy();
        setcookie(self::SESSION_NAME, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
        unset($_COOKIE[self::SESSION_NAME]);
    }

    private function lockoutRemaining(): int
    {
        $count = (int)$this->settings->get(self::FAILED_COUNT_KEY, '0');
        $since = (int)$this->settings->get(self::FAILED_SINCE_KEY, '0');
        if ($count < self::MAX_FAILED_LOGINS) {
            return 0;
        }
        return max(0, $since + self::LOCKOUT_SECONDS - time());
    }

    private function recordFailedLogin(): void
    {
        $count = (int)$this->settings->get(self::FAILED_COUNT_KEY, '0');
        $since = (int)$this->settings->get(self::FAILED_SINCE_KEY, '0');

        // Count failures within a lockout window; start a new window after that
        if (time() - $since > self::LOCKOUT_SECONDS) {
            $count = 0;
            $since = time();
        }

        $this->settings->setMany([
            self::FAILED_COUNT_KEY => (string)($count + 1),
            self::FAILED_SINCE_KEY => (string)$since,
        ]);
    }
}
