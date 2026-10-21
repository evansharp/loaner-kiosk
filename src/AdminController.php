<?php

/**
 * Handles admin actions: record management (Delete / Edit), notification settings,
 * and Google directory settings.
 */
class AdminController
{
    // Largest service account key file accepted (they are about 2.3 KB)
    const MAX_KEY_FILE_BYTES = 20000;

    /** @var CheckoutRepository */
    private $checkouts;

    /** @var PeopleRepository */
    private $people;

    /** @var SettingsRepository */
    private $settings;

    /** @var OverdueNotifier */
    private $notifier;

    /** @var DirectorySync */
    private $directorySync;

    /** @var string */
    private $keyFile;

    /** @var NotificationSettings|null Submitted settings that failed validation, to redisplay unsaved */
    public $settingsDraft = null;

    /** @var DirectorySettings|null Submitted directory settings that failed validation, to redisplay unsaved */
    public $directoryDraft = null;

    public function __construct(CheckoutRepository $checkouts, PeopleRepository $people, SettingsRepository $settings, OverdueNotifier $notifier, DirectorySync $directorySync, string $keyFile)
    {
        $this->checkouts = $checkouts;
        $this->people = $people;
        $this->settings = $settings;
        $this->notifier = $notifier;
        $this->directorySync = $directorySync;
        $this->keyFile = $keyFile;
    }

    /**
     * Returns an Alert describing the outcome, or null if nothing was done.
     *
     * @param array $files Uploaded files ($_FILES)
     */
    public function handlePost(array $post, array $files = []): ?Alert
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
                list($userId, $userEmail) = $this->resolveEditedLink($id, trim((string)($post['user_id'] ?? '')));
                $this->checkouts->update($id, $assetNumber, $userName, $checkoutTime, $checkinTime, $userId, $userEmail);
                return new Alert("Record #$id successfully updated.", "success");
            }
            return new Alert("Error: Asset number, user name, and check-out time are required.", "danger");
        } elseif ($action === 'save_settings' || $action === 'test_email') {
            return $this->saveSettings($post, $action === 'test_email');
        } elseif ($action === 'save_directory' || $action === 'sync_directory') {
            return $this->saveDirectory($post, $files['directory_key'] ?? null, $action === 'sync_directory');
        } elseif ($action === 'remove_directory_key') {
            if (is_file($this->keyFile)) {
                unlink($this->keyFile);
            }
            return new Alert('Service account key removed. People already synced are kept.', 'success', 'directory');
        }

        return null;
    }

    /**
     * The directory link to save with an edited record. The edit form submits the linked
     * person's ID; it's cleared in the browser as soon as the name is edited by hand, so
     * any typed name is saved unlinked.
     *
     * @return array [user_id, user_email], both null when unlinked
     */
    private function resolveEditedLink(int $recordId, string $userId): array
    {
        if ($userId === '') {
            return [null, null];
        }

        // Unchanged link: keep the email recorded at checkout
        $record = $this->checkouts->find($recordId);
        if ($record && $record['user_id'] === $userId) {
            return [$record['user_id'], $record['user_email']];
        }

        // Re-linked to someone picked from the directory (active or former)
        $person = $this->people->find($userId);
        return $person ? [$person['google_id'], $person['email']] : [null, null];
    }

    /**
     * @param array|null $upload Uploaded service account key ($_FILES entry), if any
     */
    private function saveDirectory(array $post, ?array $upload, bool $syncNow): Alert
    {
        $config = DirectorySettings::load($this->settings);
        $config->applyPost($post);

        // Optional new service account key
        $newKeyJson = null;
        if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > self::MAX_KEY_FILE_BYTES) {
                $this->directoryDraft = $config;
                return new Alert('Settings not saved. The key file could not be uploaded; choose the .json key file downloaded from Google Cloud.', 'danger', 'directory');
            }
            $newKeyJson = (string)file_get_contents($upload['tmp_name']);
            try {
                ServiceAccountKey::fromJson($newKeyJson);
            } catch (InvalidArgumentException $e) {
                $this->directoryDraft = $config;
                return new Alert('Settings not saved. ' . $e->getMessage(), 'danger', 'directory');
            }
        }

        $errors = $config->validate($newKeyJson !== null || is_file($this->keyFile));
        if ($errors) {
            $this->directoryDraft = $config;
            return new Alert('Settings not saved. ' . implode(' ', $errors), 'danger', 'directory');
        }

        if ($newKeyJson !== null) {
            // Readable only by the kiosk's user
            $oldUmask = umask(0077);
            file_put_contents($this->keyFile, $newKeyJson);
            umask($oldUmask);
            chmod($this->keyFile, 0600);
        }
        $config->save($this->settings);

        $saved = 'Directory settings saved' . ($newKeyJson !== null ? ' and service account key uploaded' : '') . '.';
        if (!$syncNow) {
            return new Alert($saved, 'success', 'directory');
        }

        $result = $this->directorySync->run(time(), true);
        $ok = ($this->directorySync->lastResult()['ok'] ?? false);
        return new Alert("$saved $result", $ok ? 'success' : 'danger', 'directory');
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
