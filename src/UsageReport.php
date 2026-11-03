<?php

/**
 * Usage report emailed on request from the admin page: summary totals, the top devices
 * and users, and every user's total time checked out, for a chosen period. Sent through
 * the SMTP relay configured for overdue notifications.
 */
class UsageReport
{
    const RANGES = [
        'all' => 'All time',
        'school_year' => 'This school year',
        'since_last' => 'Since last email report',
    ];

    // How many devices and users the "top" lists show (as on the admin page)
    const TOP_COUNT = 5;

    // Settings table key for when a report last sent successfully (Y-m-d H:i:s), for "since last email report"
    const LAST_SENT_AT = 'report_last_sent_at';

    // Settings table key for the addresses last sent to, to prefill the form
    const RECIPIENTS = 'report_recipients';

    /** @var CheckoutRepository */
    private $checkouts;

    /** @var SettingsRepository */
    private $settings;

    /** @var JobStatus */
    private $status;

    /** @var EmailRenderer */
    private $renderer;

    public function __construct(CheckoutRepository $checkouts, SettingsRepository $settings, ?EmailRenderer $renderer = null)
    {
        $this->checkouts = $checkouts;
        $this->settings = $settings;
        $this->status = new JobStatus($settings, 'report');
        $this->renderer = $renderer ?: new EmailRenderer();
    }

    /**
     * Start of the report period, or null for all time. "Since last email report" is
     * all time until a report has been sent.
     */
    public function rangeStart(string $range, int $now): ?int
    {
        if ($range === 'school_year') {
            return self::schoolYearStart($now);
        }
        if ($range === 'since_last') {
            return $this->lastSentAt();
        }
        return null;
    }

    /**
     * Midnight on the most recent September 1st (today, if it is September 1st).
     */
    public static function schoolYearStart(int $now): int
    {
        $year = (int)date('Y', $now);
        if ((int)date('n', $now) < 9) {
            $year--;
        }
        return mktime(0, 0, 0, 9, 1, $year);
    }

    public function lastSentAt(): ?int
    {
        $sentAt = $this->settings->get(self::LAST_SENT_AT);
        return $sentAt ? (strtotime($sentAt) ?: null) : null;
    }

    /**
     * Addresses the last report was sent to, or the overdue notice recipients before then.
     */
    public function savedRecipients(NotificationSettings $config): string
    {
        return $this->settings->get(self::RECIPIENTS) ?? $config->recipients;
    }

    /**
     * @return string[] Addresses from a comma-separated list
     */
    public static function parseRecipients(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', str_replace(["\r", "\n"], '', $list))), 'strlen'));
    }

    /**
     * @return array ['subject' => string, 'text' => string, 'html' => string]
     */
    public function compose(string $range, int $now, string $schoolName): array
    {
        $since = $this->rangeStart($range, $now);
        $stats = new UsageStats($this->checkouts->allByCheckoutTime(), $now, $since);
        $users = $stats->users();
        $totalSeconds = array_sum($stats->deviceSeconds);

        $period = $this->periodLabel($range, $since, $now);
        $subject = 'Loaner Chromebook usage report: ' . self::RANGES[$range] . ' (' . $period . ')';
        $intro = 'Time loaner Chromebooks were checked out, ' . lcfirst($this->periodSentence($range, $since, $now))
            . ' Checkouts that span the start of the period count only their time within it; devices still out count up to now.';

        $summary = [
            ['label' => 'Checkouts', 'value' => number_format($stats->checkoutCount)],
            ['label' => 'Total time out', 'value' => UsageStats::formatDuration($totalSeconds)],
            ['label' => 'Borrowers', 'value' => number_format(count($users))],
            ['label' => 'Out right now', 'value' => number_format($this->checkouts->countActive())],
        ];

        $topDevices = [];
        foreach (array_slice($stats->deviceSeconds, 0, self::TOP_COUNT, true) as $asset => $seconds) {
            $topDevices[] = ['asset' => '#' . $asset, 'time' => UsageStats::formatDuration($seconds)];
        }

        $userRows = [];
        foreach ($users as $user) {
            $userRows[] = [
                'name' => $user['name'],
                'email' => $user['email'] ?? '',
                'checkouts' => $user['checkouts'],
                'time' => UsageStats::formatDuration($user['seconds']),
            ];
        }

        $text = [$intro, ''];
        foreach ($summary as $item) {
            $text[] = "  {$item['label']}: {$item['value']}";
        }
        $text[] = '';
        $text[] = 'TOP ' . self::TOP_COUNT . ' DEVICES BY TIME';
        foreach ($topDevices as $i => $device) {
            $text[] = '  ' . ($i + 1) . ". {$device['asset']}  {$device['time']}";
        }
        $text[] = '';
        $text[] = 'TOP ' . self::TOP_COUNT . ' USERS BY TIME';
        foreach (array_slice($userRows, 0, self::TOP_COUNT) as $i => $row) {
            $text[] = '  ' . ($i + 1) . '. ' . self::userText($row);
        }
        $text[] = '';
        $text[] = 'ALL USERS BY TIME';
        foreach ($userRows as $i => $row) {
            $text[] = '  ' . ($i + 1) . '. ' . self::userText($row);
        }
        if (!$userRows) {
            $text[] = '  No checkouts in this period.';
        }

        return [
            'subject' => $subject,
            'text' => EmailRenderer::text($text, $schoolName),
            'html' => $this->renderer->html('usage_report', $subject, $intro, $schoolName, [
                'heading' => 'Loaner Chromebook usage: ' . self::RANGES[$range],
                'period' => $period,
                'intro' => $intro,
                'summary' => $summary,
                'topDevices' => $topDevices,
                'topUsers' => array_slice($userRows, 0, self::TOP_COUNT),
                'users' => $userRows,
                'topCount' => self::TOP_COUNT,
            ]),
        ];
    }

    /**
     * Emails the report. Throws RuntimeException if sending fails.
     *
     * @param string[] $recipients
     * @return string What was sent, for the admin page
     */
    public function send(string $range, array $recipients, NotificationSettings $config, int $now): string
    {
        $email = $this->compose($range, $now, $config->schoolName);
        try {
            $config->createMailer()->send($config->fromAddress, $config->fromName, $recipients, $email['subject'], $email['text'], $email['html']);
        } catch (RuntimeException $e) {
            $this->status->record($now, 'Sending failed: ' . $e->getMessage(), false);
            throw $e;
        }

        return $this->status->record($now, self::RANGES[$range] . ' report sent to ' . implode(', ', $recipients) . '.', true, [
            self::LAST_SENT_AT => date('Y-m-d H:i:s', $now),
        ]);
    }

    /**
     * @return array|null ['message' => string, 'at' => 'Y-m-d H:i:s', 'ok' => bool], or null if never sent
     */
    public function lastResult(): ?array
    {
        return $this->status->last();
    }

    /**
     * Short date range for the subject and heading, e.g. "Sep 1 – Nov 3, 2026".
     */
    private function periodLabel(string $range, ?int $since, int $now): string
    {
        if ($since === null) {
            return 'through ' . date('M j, Y', $now);
        }
        $startFormat = date('Y', $since) === date('Y', $now) ? 'M j' : 'M j, Y';
        if ($range === 'since_last') {
            $startFormat .= ', g:i A';
        }
        return date($startFormat, $since) . ' – ' . date('M j, Y', $now);
    }

    private function periodSentence(string $range, ?int $since, int $now): string
    {
        if ($range === 'since_last' && $since === null) {
            return 'For all time (no earlier report has been sent), through ' . date('F j, Y \a\t g:i A', $now) . '.';
        }
        if ($since === null) {
            return 'For all time, through ' . date('F j, Y \a\t g:i A', $now) . '.';
        }
        $from = $range === 'since_last' ? date('F j, Y \a\t g:i A', $since) . ' (the last report)' : date('F j, Y', $since);
        return "From $from to " . date('F j, Y \a\t g:i A', $now) . '.';
    }

    private static function userText(array $row): string
    {
        $checkouts = $row['checkouts'] . ' checkout' . ($row['checkouts'] === 1 ? '' : 's');
        return $row['name'] . ($row['email'] !== '' ? " <{$row['email']}>" : '') . "  {$row['time']} ($checkouts)";
    }
}
