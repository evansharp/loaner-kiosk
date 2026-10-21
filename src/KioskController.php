<?php

/**
 * Smart Scan logic: decides whether a scan is a check-in, a check-out,
 * or needs more input, and builds the resulting UX state.
 */
class KioskController
{
    /** @var CheckoutRepository */
    private $checkouts;

    /** @var PhotoStore */
    private $photos;

    /** @var PeopleRepository */
    private $people;

    /** @var DirectorySettings */
    private $directory;

    public function __construct(CheckoutRepository $checkouts, PhotoStore $photos, PeopleRepository $people, DirectorySettings $directory)
    {
        $this->checkouts = $checkouts;
        $this->photos = $photos;
        $this->people = $people;
        $this->directory = $directory;
    }

    public function handlePost(array $post): KioskState
    {
        $state = new KioskState();

        $rawAsset = trim($post['asset_number'] ?? '');
        $userName = PersonName::clean($post['user_name'] ?? '');
        $checkoutId = isset($post['checkout_id']) ? (int)$post['checkout_id'] : null;

        // Validate & parse asset number
        $assetNumber = AssetTag::parse($rawAsset);

        if ($checkoutId && $checkoutId > 0) {
            // --- DIRECT CHECK-IN BY RECORD ID (Quick Action) ---
            if ($this->checkouts->checkIn($checkoutId)) {
                $state->success("<strong>CHECK-IN SUCCESS!</strong> Chromebook <strong>#" . htmlspecialchars($rawAsset) . "</strong> returned.");
            } else {
                $state->warning("<strong>NOTICE:</strong> This Chromebook was already checked in or record not found.");
            }
            $state->autoReset = true;
        } elseif (empty($rawAsset)) {
            $state->error("Please scan or enter a Chromebook Asset Tag.");
            $state->focusTarget = "asset_number";
        } elseif ($assetNumber === null) {
            // REJECT invalid inputs
            $state->error("<strong>INVALID ASSET TAG:</strong> Scanned input must be a valid Asset URL or numeric Asset ID.");
            $state->focusTarget = "asset_number";
        } elseif ($activeRecord = $this->checkouts->findActiveByAsset($assetNumber)) {
            // --- SMART CHECK-IN ---
            $this->checkouts->checkIn((int)$activeRecord['id']);

            $state->success("<strong>CHECK-IN SUCCESS!</strong> Chromebook <strong>#" . htmlspecialchars($assetNumber) . "</strong> (previously with " . htmlspecialchars($activeRecord['user_name']) . ") returned.");
            $state->autoReset = true;
        } else {
            $this->handleCheckOut($state, $assetNumber, $userName, $post);
        }

        return $state;
    }

    // --- SMART CHECK-OUT ---
    private function handleCheckOut(KioskState $state, string $assetNumber, string $userName, array $post): void
    {
        if (empty($userName)) {
            // Name missing -> Prompt user to enter Name
            $state->warning("Chromebook <strong>#" . htmlspecialchars($assetNumber) . "</strong> is available. Please enter/scan <strong>Person's Name</strong> to finish check-out.");
            $state->prefillAsset = $assetNumber;
            $state->focusTarget = "user_name";
            return;
        }

        $person = $this->findPerson($userName, trim((string)($post['user_id'] ?? '')));

        if ($person) {
            $userName = $person['name'];
        } elseif ($this->directory->enabled && !$this->directory->allowTypedNames) {
            $state->error("<strong>NAME NOT FOUND:</strong> Please start typing your name and choose it from the list.");
            $state->prefillAsset = $assetNumber;
            $state->prefillUser = $userName;
            $state->focusTarget = "user_name";
            return;
        } else {
            // Validate full name (require at least 2 words: e.g., First and Last name)
            $nameWords = preg_split('/\s+/', $userName);
            if (count($nameWords) < 2) {
                $state->error("<strong>INVALID NAME:</strong> Please enter the full name (first and last name required).");
                $state->prefillAsset = $assetNumber;
                $state->prefillUser = $userName;
                $state->focusTarget = "user_name";
                return;
            }
        }

        // Photo Handling
        $photoUuid = $post['photo_uuid'] ?? null;
        $photoBlob = $post['photo_blob'] ?? null;

        if ($photoUuid && $photoBlob) {
            if (!$this->photos->save($photoUuid, $photoBlob)) {
                $photoUuid = null; // Not a safe filename; don't record a photo that wasn't saved
            }
        } else {
            $photoUuid = null;
        }

        // Name provided and valid -> Complete Check-Out
        $this->checkouts->checkOut($assetNumber, $userName, $photoUuid, $person['google_id'] ?? null, $person['email'] ?? null);

        $state->success("<strong>CHECK-OUT SUCCESS!</strong> Chromebook <strong>#" . htmlspecialchars($assetNumber) . "</strong> assigned to <strong>" . htmlspecialchars($userName) . "</strong>.");
        $state->autoReset = true;
    }

    /**
     * The directory person being checked out to: the one picked from the autocomplete list,
     * or else the only active person whose name exactly matches what was typed.
     */
    private function findPerson(string $userName, string $userId): ?array
    {
        if (!$this->directory->enabled) {
            return null;
        }
        if ($userId !== '') {
            return $this->people->findActive($userId);
        }
        return $this->people->findActiveByExactName($userName);
    }
}
