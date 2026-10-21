<?php

/**
 * Remembers the outcome of a background job's latest run (in the settings table),
 * for display on the admin page.
 */
class JobStatus
{
    /** @var SettingsRepository */
    private $settings;

    /** @var string Settings key prefix, e.g. "notify" -> notify_last_result */
    private $prefix;

    public function __construct(SettingsRepository $settings, string $prefix)
    {
        $this->settings = $settings;
        $this->prefix = $prefix;
    }

    /**
     * @param array $extra Additional settings to save in the same write
     * @return string The message, for convenience
     */
    public function record(int $now, string $message, bool $ok, array $extra = []): string
    {
        $this->settings->setMany([
            "{$this->prefix}_last_result" => $message,
            "{$this->prefix}_last_result_at" => date('Y-m-d H:i:s', $now),
            "{$this->prefix}_last_result_ok" => $ok ? '1' : '0',
        ] + $extra);

        return $message;
    }

    /**
     * @return array|null ['message' => string, 'at' => 'Y-m-d H:i:s', 'ok' => bool], or null if never run
     */
    public function last(): ?array
    {
        $message = $this->settings->get("{$this->prefix}_last_result");
        if ($message === null) {
            return null;
        }
        return [
            'message' => $message,
            'at' => $this->settings->get("{$this->prefix}_last_result_at"),
            'ok' => $this->settings->get("{$this->prefix}_last_result_ok") === '1',
        ];
    }
}
