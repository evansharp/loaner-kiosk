<?php

/**
 * Handles admin actions: record management (Delete / Edit), usage report emails,
 * notification settings, and Google directory settings.
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

    /** @var UsageReport */
    private $report;

    /** @var string */
    private $keyFile;

    /** @var NotificationSettings|null Submitted settings that failed validation, to redisplay unsaved */
    public $settingsDraft = null;

    /** @var DirectorySettings|null Submitted directory settings that failed validation, to redisplay unsaved */
    public $directoryDraft = null;

    /** @var array|null ['range', 'recipients'] submitted with a report that wasn't sent, to redisplay */
    public $reportDraft = null;

    public function __construct(CheckoutRepository $checkouts, PeopleRepository $people, SettingsRepository $settings, OverdueNotifier $notifier, DirectorySync $directorySync, UsageReport $report, string $keyFile)
    {
        $this->checkouts = $checkouts;
        $this->people = $people;
        $this->settings = $settings;
        $this->notifier = $notifier;
        $this->directorySync = $directorySync;
        $this->report = $report;
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
                $original = $this->checkouts->find($id);
                list($userId, $userEmail) = $this->resolveEditedLink($original, trim((string)($post['user_id'] ?? '')));
                $this->checkouts->update($id, $assetNumber, $userName, $checkoutTime, $checkinTime, $userId, $userEmail);

                $message = "Record #$id successfully updated.";
                if (!empty($post['link_matching']) && $userId !== null && $original && $original['user_id'] !== $userId) {
                    // Other checkouts where the student typed their name the same way
                    $linked = $this->checkouts->linkUnlinkedByName($original['user_name'], $userName, $userId, $userEmail);
                    if ($linked > 0) {
                        $message .= " Also linked $linked other " . ($linked === 1 ? 'record' : 'records') . " named “{$original['user_name']}”.";
                    }
                }
                return new Alert($message, "success");
            }
            return new Alert("Error: Asset number, user name, and check-out time are required.", "danger");
        } elseif ($action === 'send_report') {
            return $this->sendReport($post);
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
     * person's ID: the record's existing link, someone picked from the directory search
     * (which also replaces the name), or empty when unlinked.
     *
     * @param array|null $record The record before this edit
     * @return array [user_id, user_email], both null when unlinked
     */
    private function resolveEditedLink(?array $record, string $userId): array
    {
        if ($userId === '') {
            return [null, null];
        }

        // Unchanged link: keep the email recorded at checkout
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

    private function sendReport(array $post): Alert
    {
        $range = (string)($post['report_range'] ?? '');
        $recipientText = trim(str_replace(["\r", "\n"], '', (string)($post['report_recipients'] ?? '')));
        $recipients = UsageReport::parseRecipients($recipientText);
        $this->reportDraft = ['range' => $range, 'recipients' => implode(', ', $recipients)];

        $errors = [];
        if (!isset(UsageReport::RANGES[$range])) {
            $errors[] = 'Choose a time range.';
        }
        if (empty($recipients)) {
            $errors[] = 'Enter at least one email address to send the report to.';
        }
        foreach ($recipients as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "\"$address\" is not a valid email address.";
            }
        }
        $config = NotificationSettings::load($this->settings);
        if ($config->smtpHost === '' || $config->fromAddress === '') {
            $errors[] = 'Set up the SMTP relay and From address under Overdue Email Notifications first.';
        }
        if ($errors) {
            return new Alert('Report not sent. ' . implode(' ', $errors), 'danger', 'report');
        }

        $this->settings->setMany([UsageReport::RECIPIENTS => $this->reportDraft['recipients']]);
        try {
            $message = $this->report->send($range, $recipients, $config, time());
        } catch (RuntimeException $e) {
            return new Alert('Report not sent: ' . $e->getMessage(), 'danger', 'report');
        }
        return new Alert($message, 'success', 'report');
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
