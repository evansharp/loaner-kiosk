<?php

/**
 * Key/value access to the settings table.
 */
class SettingsRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE key = :key");
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    }

    /**
     * @return array<string, string> key => value
     */
    public function all(): array
    {
        return $this->pdo->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * @param array<string, string|null> $values key => value
     */
    public function setMany(array $values): void
    {
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)");

        $this->pdo->beginTransaction();
        foreach ($values as $key => $value) {
            $stmt->execute([':key' => $key, ':value' => $value]);
        }
        $this->pdo->commit();
    }
}
