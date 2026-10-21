<?php

/**
 * Emails the configured recipients a list of devices still checked out at the
 * configured time of day. Meant to be run every few minutes (bin/notify_overdue.php);
 * sends at most once per day.
 */
class OverdueNotifier
{
    // Settings table keys recording the most recent run
    const LAST_RUN_DATE = 'notify_last_run_date'; // Day the daily check completed (Y-m-d)
    const LAST_RESULT = 'notify_last_result';
    const LAST_RESULT_AT = 'notify_last_result_at';
    const LAST_RESULT_OK = 'notify_last_result_ok';

    /** @var CheckoutRepository */
    private $checkouts;

    /** @var SettingsRepository */
    private $settings;

    public function __construct(CheckoutRepository $checkouts, SettingsRepository $settings)
    {
        $this->checkouts = $checkouts;
        $this->settings = $settings;
    }

    /**
     * Sends today's overdue email if it is due. Failures are retried on the next run.
     *
     * @param bool $force Ignore the enabled switch, notify time and once-per-day limit
     * @return string|null What happened, or null if nothing was due
     */
    public function run(int $now, bool $force = false): ?string
    {
        $config = NotificationSettings::load($this->settings);

        if (!$force) {
            if (!$config->enabled
                || date('H:i', $now) < $config->notifyTime
                || $this->settings->get(self::LAST_RUN_DATE) === date('Y-m-d', $now)) {
                return null;
            }
        }

        $errors = $config->validate(true);
        if ($errors) {
            return $this->record($now, 'Not sent: ' . implode(' ', $errors), false);
        }

        $overdue = $this->checkouts->active();
        if (empty($overdue)) {
            return $this->record($now, 'No devices overdue; no email needed.', true);
        }

        try {
            $config->createMailer()->send(
                $config->fromAddress,
                $config->fromName,
                $config->recipientList(),
                $this->overdueSubject($overdue, $config),
                $this->overdueBody($overdue, $config, $now)
            );
        } catch (RuntimeException $e) {
            return $this->record($now, 'Sending failed: ' . $e->getMessage(), false);
        }

        $count = count($overdue);
        return $this->record($now, "Sent overdue notice for $count device" . ($count === 1 ? '' : 's') . ' to ' . implode(', ', $config->recipientList()) . '.', true);
    }

    /**
     * Sends a test email using the given settings. Throws RuntimeException on failure.
     */
    public function sendTest(NotificationSettings $config): void
    {
        $body = "This is a test email from the Loaner Chromebook Kiosk.\n\n"
            . "Your SMTP relay settings are working. "
            . ($config->enabled
                ? "Overdue notices will be sent daily at {$config->notifyTimeLabel()} to: " . implode(', ', $config->recipientList()) . '.'
                : 'Daily overdue notices are currently turned off.')
            . "\n";

        $config->createMailer()->send(
            $config->fromAddress,
            $config->fromName,
            $config->recipientList(),
            'Test email from the Loaner Chromebook Kiosk',
            $body
        );
    }

    /**
     * @return array|null ['message' => string, 'at' => 'Y-m-d H:i:s', 'ok' => bool], or null if never run
     */
    public function lastResult(): ?array
    {
        $message = $this->settings->get(self::LAST_RESULT);
        if ($message === null) {
            return null;
        }
        return [
            'message' => $message,
            'at' => $this->settings->get(self::LAST_RESULT_AT),
            'ok' => $this->settings->get(self::LAST_RESULT_OK) === '1',
        ];
    }

    /**
     * @param bool $done Whether today's check is finished (false = try again next run)
     */
    private function record(int $now, string $message, bool $done): string
    {
        $values = [
            self::LAST_RESULT => $message,
            self::LAST_RESULT_AT => date('Y-m-d H:i:s', $now),
            self::LAST_RESULT_OK => $done ? '1' : '0',
        ];
        if ($done) {
            $values[self::LAST_RUN_DATE] = date('Y-m-d', $now);
        }
        $this->settings->setMany($values);

        return $message;
    }

    private function overdueSubject(array $overdue, NotificationSettings $config): string
    {
        $count = count($overdue);
        return $count === 1
            ? "Overdue loaner Chromebook: #{$overdue[0]['asset_number']} not returned by {$config->notifyTimeLabel()}"
            : "Overdue loaner Chromebooks: $count not returned by {$config->notifyTimeLabel()}";
    }

    private function overdueBody(array $overdue, NotificationSettings $config, int $now): string
    {
        $count = count($overdue);
        $lines = [
            ($count === 1 ? 'This loaner Chromebook was' : "These $count loaner Chromebooks were")
                . " not checked back in by {$config->notifyTimeLabel()} on " . date('l, F j, Y', $now) . ':',
            '',
        ];

        foreach ($overdue as $record) {
            $outTime = strtotime($record['checkout_time']);
            $lines[] = "  #{$record['asset_number']}  {$record['user_name']}"
                . ' (checked out ' . date('M j, g:i A', $outTime) . '; out for ' . UsageStats::formatDuration($now - $outTime) . ')';
        }

        $lines[] = '';
        $lines[] = 'Returned devices can be checked in at the kiosk, and records corrected on its admin page.';
        $lines[] = '';
        $lines[] = '-- ';
        $lines[] = 'Sent automatically by the Loaner Chromebook Kiosk.';

        return implode("\n", $lines) . "\n";
    }
}
