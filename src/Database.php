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

        return $pdo;
    }
}
