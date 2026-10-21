<?php

/**
 * Composes the overdue-device emails: the summary for administrators, and the note to
 * each borrower linked to the Google directory. HTML comes from templates/emails/;
 * each also gets a plain-text version for mail clients that don't show HTML.
 */
class OverdueEmails
{
    // Borrower email outcomes passed to admin()
    const BORROWER_EMAILED = 'emailed';
    const BORROWER_EMAILS_OFF = 'off';

    /** @var string */
    private $templateDir;

    public function __construct(?string $templateDir = null)
    {
        $this->templateDir = $templateDir ?: dirname(__DIR__) . '/templates/emails';
    }

    /**
     * Overdue checkouts linked to a directory person, grouped by person.
     *
     * @return array[] user_id => ['name', 'email', 'records' => array[]]
     */
    public static function byBorrower(array $overdue): array
    {
        $borrowers = [];
        foreach ($overdue as $record) {
            if (empty($record['user_id']) || empty($record['user_email'])) {
                continue;
            }
            $userId = (string)$record['user_id'];
            if (!isset($borrowers[$userId])) {
                $borrowers[$userId] = ['name' => $record['user_name'], 'email' => $record['user_email'], 'records' => []];
            }
            $borrowers[$userId]['records'][] = $record;
        }
        return $borrowers;
    }

    /**
     * Summary for administrators.
     *
     * @param array $borrowerStatus user_id => BORROWER_EMAILED, BORROWER_EMAILS_OFF, or an error message
     * @return array ['subject' => string, 'text' => string, 'html' => string]
     */
    public function admin(array $overdue, array $borrowerStatus, NotificationSettings $config, int $now): array
    {
        $count = count($overdue);
        $subject = $count === 1
            ? "Overdue loaner Chromebook: #{$overdue[0]['asset_number']} not returned by {$config->notifyTimeLabel()}"
            : "Overdue loaner Chromebooks: $count not returned by {$config->notifyTimeLabel()}";
        $intro = ($count === 1 ? 'This loaner Chromebook was' : "These $count loaner Chromebooks were")
            . " still checked out at {$config->notifyTimeLabel()} on " . date('l, F j, Y', $now) . '.';

        $rows = [];
        foreach ($overdue as $record) {
            $outTime = strtotime($record['checkout_time']);
            list($status, $tone) = self::borrowerStatusLabel($record, $borrowerStatus);
            $rows[] = [
                'asset' => '#' . $record['asset_number'],
                'name' => $record['user_name'],
                'email' => $record['user_email'] ?? '',
                'checkedOut' => date('D M j, g:i A', $outTime),
                'outFor' => UsageStats::formatDuration($now - $outTime),
                'status' => $status,
                'statusTone' => $tone, // ok, muted or error
            ];
        }

        $text = [$intro, ''];
        foreach ($rows as $row) {
            $text[] = "  {$row['asset']}  {$row['name']}" . ($row['email'] ? " <{$row['email']}>" : '')
                . " (checked out {$row['checkedOut']}; out for {$row['outFor']}) - {$row['status']}";
        }
        $text[] = '';
        $text[] = 'Returned devices can be checked in at the kiosk, and records corrected on its admin page.';

        return [
            'subject' => $subject,
            'text' => $this->textWithFooter($text, $config),
            'html' => $this->renderEmail('overdue_admin', $subject, $intro, $config, [
                'heading' => $count === 1 ? '1 loaner Chromebook not returned' : "$count loaner Chromebooks not returned",
                'intro' => $intro,
                'rows' => $rows,
            ]),
        ];
    }

    /**
     * Note to one borrower about their own overdue devices.
     *
     * @param array $borrower ['name', 'email', 'records'] from byBorrower()
     * @return array ['subject' => string, 'text' => string, 'html' => string]
     */
    public function borrower(array $borrower, NotificationSettings $config): array
    {
        $records = $borrower['records'];
        $count = count($records);
        $assets = array_map(function (array $record): string {
            return '#' . $record['asset_number'];
        }, $records);

        $subject = $count === 1
            ? "Please return loaner Chromebook {$assets[0]}"
            : 'Please return your loaner Chromebooks (' . implode(', ', $assets) . ')';
        $intro = 'Our records show you still have ' . ($count === 1 ? 'this loaner Chromebook' : "these $count loaner Chromebooks")
            . ", which hadn't been checked back in at the kiosk as of {$config->notifyTimeLabel()} today.";
        $it = $count === 1 ? 'it' : 'them';

        $devices = [];
        foreach ($records as $record) {
            $devices[] = [
                'asset' => '#' . $record['asset_number'],
                'checkedOut' => date('l, M j \a\t g:i A', strtotime($record['checkout_time'])),
            ];
        }

        $text = ["Hi {$borrower['name']},", '', $intro, ''];
        foreach ($devices as $device) {
            $text[] = "  {$device['asset']} - checked out {$device['checkedOut']}";
        }
        $text[] = '';
        $text[] = "Please return $it to the loaner kiosk as soon as you can.";
        $text[] = '';
        $text[] = "Already returned $it? Reply to this email and let us know.";

        return [
            'subject' => $subject,
            'text' => $this->textWithFooter($text, $config),
            'html' => $this->renderEmail('overdue_borrower', $subject, $intro, $config, [
                'heading' => $count === 1 ? 'Please return your loaner Chromebook' : 'Please return your loaner Chromebooks',
                'name' => $borrower['name'],
                'intro' => $intro,
                'devices' => $devices,
                'it' => $it,
            ]),
        ];
    }

    /**
     * Made-up overdue checkouts for previewing the templates when nothing is overdue.
     */
    public static function sampleOverdue(int $now): array
    {
        return [
            ['asset_number' => '101', 'user_name' => 'Jane Doe', 'user_id' => 'sample-1', 'user_email' => 'jane.doe@example.org', 'checkout_time' => date('Y-m-d 08:45:00', $now - 86400)],
            ['asset_number' => '117', 'user_name' => 'Jane Doe', 'user_id' => 'sample-1', 'user_email' => 'jane.doe@example.org', 'checkout_time' => date('Y-m-d 09:10:00', $now)],
            ['asset_number' => '123', 'user_name' => 'Visiting Parent', 'user_id' => null, 'user_email' => null, 'checkout_time' => date('Y-m-d 11:30:00', $now)],
        ];
    }

    /**
     * @return array [label, tone]
     */
    private static function borrowerStatusLabel(array $record, array $borrowerStatus): array
    {
        if (empty($record['user_id']) || empty($record['user_email'])) {
            return ['Not in the directory, so not emailed', 'muted'];
        }
        $status = $borrowerStatus[(string)$record['user_id']] ?? self::BORROWER_EMAILS_OFF;
        if ($status === self::BORROWER_EMAILED) {
            return ['Borrower emailed', 'ok'];
        }
        if ($status === self::BORROWER_EMAILS_OFF) {
            return ['Borrower not emailed (borrower emails are off)', 'muted'];
        }
        return ["Couldn't email borrower: " . $status, 'error'];
    }

    private function textWithFooter(array $lines, NotificationSettings $config): string
    {
        $lines[] = '';
        $lines[] = '-- ';
        $lines[] = $config->schoolName . ' - sent automatically by the Loaner Chromebook Kiosk.';
        return implode("\n", $lines) . "\n";
    }

    /**
     * Renders a content template inside the shared email layout.
     */
    private function renderEmail(string $template, string $title, string $preheader, NotificationSettings $config, array $vars): string
    {
        $content = $this->render($template, $vars);
        return $this->render('layout', [
            'title' => $title,
            'preheader' => $preheader,
            'content' => $content,
            'schoolName' => $config->schoolName,
        ]);
    }

    private function render(string $template, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require "{$this->templateDir}/$template.php";
        return ob_get_clean();
    }
}
