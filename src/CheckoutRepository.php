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

    public function checkOut(string $assetNumber, string $userName, ?string $photoUuid): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO checkouts (asset_number, user_name, checkout_time, photo_uuid) VALUES (:asset, :name, :time, :uuid)");
        $stmt->execute([
            ':asset' => $assetNumber,
            ':name' => $userName,
            ':time' => date('Y-m-d H:i:s'),
            ':uuid' => $photoUuid
        ]);
    }

    public function update(int $id, string $assetNumber, string $userName, string $checkoutTime, ?string $checkinTime): void
    {
        $stmt = $this->pdo->prepare("UPDATE checkouts SET asset_number = :asset, user_name = :name, checkout_time = :checkout, checkin_time = :checkin WHERE id = :id");
        $stmt->execute([
            ':asset' => $assetNumber,
            ':name' => $userName,
            ':checkout' => $checkoutTime,
            ':checkin' => $checkinTime,
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
