<?php

/**
 * Handles admin actions: record management (Delete / Edit) and notification settings.
 */
class AdminController
{
    /** @var CheckoutRepository */
    private $checkouts;

    /** @var SettingsRepository */
    private $settings;

    /** @var OverdueNotifier */
    private $notifier;

    /** @var NotificationSettings|null Submitted settings that failed validation, to redisplay unsaved */
    public $settingsDraft = null;

    public function __construct(CheckoutRepository $checkouts, SettingsRepository $settings, OverdueNotifier $notifier)
    {
        $this->checkouts = $checkouts;
        $this->settings = $settings;
        $this->notifier = $notifier;
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
        } elseif ($action === 'save_settings' || $action === 'test_email') {
            return $this->saveSettings($post, $action === 'test_email');
        }

        return null;
    }

    private function saveSettings(array $post, bool $sendTest): Alert
    {
        $config = NotificationSettings::load($this->settings);
        $config->applyPost($post);

        $errors = $config->validate($config->enabled || $sendTest);
        if ($errors) {
            $this->settingsDraft = $config;
            return new Alert('Settings not saved. ' . implode(' ', $errors), 'danger', 'settings');
        }

        $config->save($this->settings);

        if (!$sendTest) {
            return new Alert('Notification settings saved.', 'success', 'settings');
        }

        try {
            $this->notifier->sendTest($config);
        } catch (RuntimeException $e) {
            return new Alert('Settings saved, but the test email failed: ' . $e->getMessage(), 'danger', 'settings');
        }
        return new Alert('Settings saved. Test email sent to ' . implode(', ', $config->recipientList()) . '.', 'success', 'settings');
    }
}
