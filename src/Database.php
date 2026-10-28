<?php

/**
 * Opens the SQLite database and ensures the schema exists.
 */
class Database
{
    public static function connect(string $dbFile): PDO
    {
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

        // Key/value store for admin settings (e.g. overdue email notifications)
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");

        // People synced from the Google directory, for name autocomplete
        $pdo->exec("CREATE TABLE IF NOT EXISTS people (
            google_id TEXT PRIMARY KEY,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            org_unit TEXT,
            active INTEGER NOT NULL DEFAULT 1
        )");

        // Databases first created by older admin.php versions lack photo_uuid
        self::addColumnIfMissing($pdo, 'checkouts', 'photo_uuid', 'TEXT DEFAULT NULL');

        // Checkouts linked to a directory person (both NULL for typed names and older records)
        self::addColumnIfMissing($pdo, 'checkouts', 'user_id', 'TEXT DEFAULT NULL');
        self::addColumnIfMissing($pdo, 'checkouts', 'user_email', 'TEXT DEFAULT NULL');

        return $pdo;
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        $columns = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }
}
