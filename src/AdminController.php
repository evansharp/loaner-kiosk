<?php

/**
 * Handles admin record management actions (Delete / Edit).
 */
class AdminController
{
    public function __construct(private CheckoutRepository $checkouts)
    {
    }

    /**
     * Returns an Alert describing the outcome, or null if nothing was done.
     */
    public function handlePost(array $post): ?Alert
    {
        $action = $post['action'] ?? '';
        $id = (int)($post['record_id'] ?? 0);

        if ($action === 'delete') {
            if ($id > 0) {
                $this->checkouts->delete($id);
                return new Alert("Record #$id successfully deleted.", "success");
            }
        } elseif ($action === 'edit') {
            $assetNumber = trim($post['asset_number'] ?? '');
            $userName = PersonName::clean($post['user_name'] ?? '');
            $checkoutTime = trim($post['checkout_time'] ?? '');
            $checkinTime = trim($post['checkin_time'] ?? '');
            $checkinTime = ($checkinTime === '') ? null : $checkinTime;

            if ($id > 0 && !empty($assetNumber) && !empty($userName) && !empty($checkoutTime)) {
                $this->checkouts->update($id, $assetNumber, $userName, $checkoutTime, $checkinTime);
                return new Alert("Record #$id successfully updated.", "success");
            }
            return new Alert("Error: Asset number, user name, and check-out time are required.", "danger");
        }

        return null;
    }
}
