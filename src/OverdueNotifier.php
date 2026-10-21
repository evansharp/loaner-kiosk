<?php

/**
 * At the configured time of day, emails each borrower linked to the Google directory
 * about their own overdue devices, then sends the administrators a summary of every
 * device still checked out. Meant to be run every few minutes (bin/notify_overdue.php);
 * sends at most once per day.
 */
class OverdueNotifier
{
    // Settings table key for the day the daily check completed (Y-m-d)
    const LAST_RUN_DATE = 'notify_last_run_date';

    // Settings table key for borrowers already emailed today: JSON {"date": "Y-m-d", "ids": [user_id, ...]}
    // (so retrying a failed admin email doesn't email borrowers twice)
    const BORROWERS_EMAILED = 'notify_borrowers_emailed';

    /** @var CheckoutRepository */
    private $checkouts;

    /** @var SettingsRepository */
    private $settings;

    /** @var JobStatus */
    private $status;

    public function __construct(CheckoutRepository $checkouts, SettingsRepository $settings)
    {
        $this->checkouts = $checkouts;
        $this->settings = $settings;
        $this->status = new JobStatus($settings, 'notify');
    }

    /**
     * Sends today's overdue emails if they are due. A failed admin email is retried on the
     * next run; a failed borrower email is reported in the admin email and not retried.
     *
     * @param bool $force Ignore the enabled switch, notify time and once-per-day limits
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

        $emails = new OverdueEmails();
        $mailer = $config->createMailer();

        // Borrowers first, so the admin summary can say who was emailed
        $borrowerStatus = $config->notifyUsers
            ? $this->emailBorrowers($overdue, $config, $emails, $mailer, $now, $force)
            : [];
        $borrowerSummary = $config->notifyUsers ? ' ' . self::summarizeBorrowers($borrowerStatus) : '';

        $email = $emails->admin($overdue, $borrowerStatus, $config, $now);
        try {
            $mailer->send($config->fromAddress, $config->fromName, $config->recipientList(), $email['subject'], $email['text'], $email['html']);
        } catch (RuntimeException $e) {
            return $this->record($now, 'Sending failed: ' . $e->getMessage() . $borrowerSummary, false);
        }

        $count = count($overdue);
        return $this->record($now, "Sent overdue notice for $count device" . ($count === 1 ? '' : 's') . ' to ' . implode(', ', $config->recipientList()) . '.' . $borrowerSummary, true);
    }

    /**
     * Emails each linked borrower about their own overdue devices, once per day.
     *
     * @return array user_id => OverdueEmails::BORROWER_EMAILED, or the error message
     */
    private function emailBorrowers(array $overdue, NotificationSettings $config, OverdueEmails $emails, SmtpMailer $mailer, int $now, bool $force): array
    {
        $today = date('Y-m-d', $now);
        $state = json_decode((string)$this->settings->get(self::BORROWERS_EMAILED, ''), true);
        $emailedToday = (is_array($state) && ($state['date'] ?? '') === $today) ? array_map('strval', $state['ids']) : [];

        $status = [];
        foreach (OverdueEmails::byBorrower($overdue) as $userId => $borrower) {
            $userId = (string)$userId; // PHP turns numeric-string array keys into ints
            if (!$force && in_array($userId, $emailedToday, true)) {
                $status[$userId] = OverdueEmails::BORROWER_EMAILED;
                continue;
            }

            $email = $emails->borrower($borrower, $config);
            try {
                // Replies ("I already returned it") go to the administrators
                $mailer->send($config->fromAddress, $config->fromName, [$borrower['email']], $email['subject'], $email['text'], $email['html'], $config->recipientList());
            } catch (RuntimeException $e) {
                $status[$userId] = $e->getMessage();
                continue;
            }

            $status[$userId] = OverdueEmails::BORROWER_EMAILED;
            $emailedToday[] = $userId;
            $this->settings->setMany([self::BORROWERS_EMAILED => json_encode(['date' => $today, 'ids' => array_values(array_unique($emailedToday))])]);
        }

        return $status;
    }

    private static function summarizeBorrowers(array $borrowerStatus): string
    {
        $total = count($borrowerStatus);
        if ($total === 0) {
            return 'No overdue borrowers are linked to the directory, so none were emailed.';
        }

        $failed = array_filter($borrowerStatus, function (string $status): bool {
            return $status !== OverdueEmails::BORROWER_EMAILED;
        });
        $summary = 'Emailed ' . ($total - count($failed)) . " of $total borrower" . ($total === 1 ? '' : 's') . '.';
        if ($failed) {
            $summary .= ' Failed: ' . implode('; ', array_unique($failed));
        }
        return $summary;
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
        return $this->status->last();
    }

    /**
     * @param bool $done Whether today's check is finished (false = try again next run)
     */
    private function record(int $now, string $message, bool $done): string
    {
        return $this->status->record($now, $message, $done, $done ? [self::LAST_RUN_DATE => date('Y-m-d', $now)] : []);
    }
}
