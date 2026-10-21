<?php

/**
 * All queries against the checkouts table.
 */
class CheckoutRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveByAsset(string $assetNumber): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM checkouts WHERE asset_number = :asset AND checkin_time IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([':asset' => $assetNumber]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Checks in a record by ID. Returns true if an active record was updated.
     */
    public function checkIn(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE checkouts SET checkin_time = :time WHERE id = :id AND checkin_time IS NULL");
        $stmt->execute([
            ':time' => date('Y-m-d H:i:s'),
            ':id' => $id
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param string|null $userId    Google directory ID, if the person was picked from the directory
     * @param string|null $userEmail Their directory email at the time of checkout
     */
    public function checkOut(string $assetNumber, string $userName, ?string $photoUuid, ?string $userId = null, ?string $userEmail = null): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO checkouts (asset_number, user_name, checkout_time, photo_uuid, user_id, user_email) VALUES (:asset, :name, :time, :uuid, :user_id, :user_email)");
        $stmt->execute([
            ':asset' => $assetNumber,
            ':name' => $userName,
            ':time' => date('Y-m-d H:i:s'),
            ':uuid' => $photoUuid,
            ':user_id' => $userId,
            ':user_email' => $userEmail
        ]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM checkouts WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param string|null $userId    Google directory ID of the linked person (null = not linked)
     * @param string|null $userEmail Their directory email
     */
    public function update(int $id, string $assetNumber, string $userName, string $checkoutTime, ?string $checkinTime, ?string $userId = null, ?string $userEmail = null): void
    {
        $stmt = $this->pdo->prepare("UPDATE checkouts SET asset_number = :asset, user_name = :name, checkout_time = :checkout, checkin_time = :checkin, user_id = :user_id, user_email = :user_email WHERE id = :id");
        $stmt->execute([
            ':asset' => $assetNumber,
            ':name' => $userName,
            ':checkout' => $checkoutTime,
            ':checkin' => $checkinTime,
            ':user_id' => $userId,
            ':user_email' => $userEmail,
            ':id' => $id
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM checkouts WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function all(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM checkouts ORDER BY id DESC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Devices currently checked out, oldest checkout first.
     */
    public function active(): array
    {
        return $this->pdo->query("SELECT * FROM checkouts WHERE checkin_time IS NULL ORDER BY checkout_time ASC")->fetchAll();
    }

    public function allByCheckoutTime(): array
    {
        return $this->pdo->query("SELECT * FROM checkouts ORDER BY checkout_time DESC")->fetchAll();
    }

    public function countAll(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM checkouts")->fetchColumn();
    }

    public function countActive(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM checkouts WHERE checkin_time IS NULL")->fetchColumn();
    }
}
