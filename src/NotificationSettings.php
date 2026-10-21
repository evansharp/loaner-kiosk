<?php

/**
 * Overdue email notification + SMTP relay settings, as configured on the admin page.
 */
class NotificationSettings extends SettingsGroup
{
    const ENCRYPTION_OPTIONS = [
        'tls' => 'STARTTLS (port 587)',
        'ssl' => 'SSL/TLS (port 465)',
        'none' => 'None (port 25)',
    ];

    // Settings table key for each property
    const KEYS = [
        'enabled' => 'notify_enabled',
        'notifyUsers' => 'notify_users',
        'notifyTime' => 'notify_time',
        'recipients' => 'notify_recipients',
        'smtpHost' => 'smtp_host',
        'smtpPort' => 'smtp_port',
        'smtpEncryption' => 'smtp_encryption',
        'smtpHelo' => 'smtp_helo',
        'smtpUsername' => 'smtp_username',
        'smtpPassword' => 'smtp_password',
        'fromAddress' => 'mail_from_address',
        'fromName' => 'mail_from_name',
    ];

    public $enabled = false;
    public $notifyUsers = true;    // Also email each borrower linked to the Google directory
    public $notifyTime = '15:30';  // HH:MM, 24-hour
    public $recipients = '';       // Comma-separated
    public $smtpHost = 'smtp-relay.gmail.com';
    public $smtpPort = 587;
    public $smtpEncryption = 'tls';
    public $smtpHelo = '';         // Blank = domain of the From address
    public $smtpUsername = '';     // Blank = relay authenticates by IP address
    public $smtpPassword = '';
    public $fromAddress = '';
    public $fromName = 'Loaner Chromebook Kiosk';

    /**
     * Applies submitted admin form values.
     * A blank password keeps the saved one, unless smtp_password_clear is checked.
     */
    public function applyPost(array $post): void
    {
        $this->enabled = !empty($post['notify_enabled']);
        $this->notifyUsers = !empty($post['notify_users']);
        $this->notifyTime = substr(self::postedLine($post, 'notify_time'), 0, 5); // Drop seconds if the browser sends them
        $this->recipients = implode(', ', self::splitList(self::postedLine($post, 'notify_recipients')));
        $this->smtpHost = self::postedLine($post, 'smtp_host');
        $this->smtpPort = (int)self::postedLine($post, 'smtp_port');
        $this->smtpEncryption = self::postedLine($post, 'smtp_encryption');
        $this->smtpHelo = self::postedLine($post, 'smtp_helo');
        $this->smtpUsername = self::postedLine($post, 'smtp_username');
        $this->fromAddress = self::postedLine($post, 'mail_from_address');
        $this->fromName = self::postedLine($post, 'mail_from_name');

        if (!empty($post['smtp_password_clear'])) {
            $this->smtpPassword = '';
        } elseif (($post['smtp_password'] ?? '') !== '') {
            $this->smtpPassword = str_replace(["\r", "\n"], '', $post['smtp_password']); // Not trimmed: spaces may be part of it
        }
    }

    /**
     * Returns a list of problems; empty if valid.
     *
     * @param bool $forSending Also require everything needed to actually send mail
     */
    public function validate(bool $forSending): array
    {
        $errors = [];

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $this->notifyTime)) {
            $errors[] = 'Notify time must be a valid time of day.';
        }
        foreach ($this->recipientList() as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "\"$address\" is not a valid recipient email address.";
            }
        }
        if ($this->smtpHost !== '' && !self::isHostname($this->smtpHost)) {
            $errors[] = 'SMTP host must be a hostname, e.g. smtp-relay.gmail.com.';
        }
        if ($this->smtpPort < 1 || $this->smtpPort > 65535) {
            $errors[] = 'SMTP port must be between 1 and 65535.';
        }
        if (!isset(self::ENCRYPTION_OPTIONS[$this->smtpEncryption])) {
            $errors[] = 'Choose a valid encryption type.';
        }
        if ($this->smtpHelo !== '' && !self::isHostname($this->smtpHelo)) {
            $errors[] = 'EHLO domain must be a domain name, e.g. example.org.';
        }
        if ($this->fromAddress !== '' && !filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'From address is not a valid email address.';
        }
        if ($this->smtpUsername !== '' && $this->smtpPassword === '') {
            $errors[] = 'Enter the SMTP password, or clear the username to use IP address authentication.';
        }

        if ($forSending) {
            if (empty($this->recipientList())) {
                $errors[] = 'Enter at least one recipient address.';
            }
            if ($this->smtpHost === '') {
                $errors[] = 'SMTP host is required.';
            }
            if ($this->fromAddress === '') {
                $errors[] = 'From address is required.';
            }
        }

        return $errors;
    }

    public function recipientList(): array
    {
        return self::splitList($this->recipients);
    }

    /**
     * Domain announced in EHLO. Google's relay rejects "localhost", so default to the From address's domain.
     */
    public function heloDomain(): string
    {
        if ($this->smtpHelo !== '') {
            return $this->smtpHelo;
        }
        $at = strrpos($this->fromAddress, '@');
        if ($at !== false) {
            return substr($this->fromAddress, $at + 1);
        }
        return gethostname() ?: 'localhost';
    }

    /**
     * Notify time formatted for people, e.g. "3:30 PM".
     */
    public function notifyTimeLabel(): string
    {
        return date('g:i A', strtotime($this->notifyTime));
    }

    public function createMailer(): SmtpMailer
    {
        return new SmtpMailer(
            $this->smtpHost,
            $this->smtpPort,
            $this->smtpEncryption,
            $this->heloDomain(),
            $this->smtpUsername,
            $this->smtpPassword
        );
    }

    private static function splitList(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $list)), 'strlen'));
    }

    private static function isHostname(string $value): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $value);
    }
}
