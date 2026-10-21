<?php

/**
 * People synced from the Google directory.
 */
class PeopleRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Active people whose name or email contains every word of the query.
     * Names starting with the query come first.
     *
     * @return array[] ['id' => google_id, 'name' => ..., 'email' => ...]
     */
    public function search(string $query, int $limit): array
    {
        $words = array_slice(preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY), 0, 5);
        if (empty($words)) {
            return [];
        }

        $conditions = [];
        $params = [':prefix' => self::likeEscape(trim($query)) . '%'];
        foreach ($words as $i => $word) {
            $conditions[] = "(name LIKE :w$i ESCAPE '\\' OR email LIKE :w$i ESCAPE '\\')";
            $params[":w$i"] = '%' . self::likeEscape($word) . '%';
        }

        $stmt = $this->pdo->prepare(
            "SELECT google_id AS id, name, email FROM people
             WHERE active = 1 AND " . implode(' AND ', $conditions) . "
             ORDER BY CASE WHEN name LIKE :prefix ESCAPE '\\' THEN 0 ELSE 1 END, name
             LIMIT " . (int)$limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findActive(string $googleId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM people WHERE google_id = :id AND active = 1");
        $stmt->execute([':id' => $googleId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The active person with exactly this name (ignoring case), or null if none or more than one.
     */
    public function findActiveByExactName(string $name): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM people WHERE active = 1 AND name = :name COLLATE NOCASE LIMIT 2");
        $stmt->execute([':name' => $name]);
        $matches = $stmt->fetchAll();
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function countActive(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM people WHERE active = 1")->fetchColumn();
    }

    /**
     * Replaces the synced directory. People missing from $people are kept but marked
     * inactive, so older checkout records still resolve.
     *
     * @param array[] $people ['google_id', 'email', 'name', 'org_unit', 'active' => bool]
     */
    public function replaceAll(array $people): void
    {
        $upsert = $this->pdo->prepare(
            "INSERT OR REPLACE INTO people (google_id, email, name, org_unit, active)
             VALUES (:id, :email, :name, :org_unit, :active)"
        );

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("UPDATE people SET active = 0");
            foreach ($people as $person) {
                $upsert->execute([
                    ':id' => $person['google_id'],
                    ':email' => $person['email'],
                    ':name' => $person['name'],
                    ':org_unit' => $person['org_unit'],
                    ':active' => $person['active'] ? 1 : 0,
                ]);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private static function likeEscape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
