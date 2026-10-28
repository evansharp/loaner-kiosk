<?php

/**
 * Copies people from the configured Google org units into the local people table.
 * Meant to be run every few minutes (bin/sync_directory.php); syncs every SYNC_INTERVAL,
 * retrying failures after RETRY_INTERVAL.
 */
class DirectorySync
{
    const SYNC_INTERVAL = 6 * 3600;
    const RETRY_INTERVAL = 30 * 60;
    const LAST_SUCCESS_AT = 'directory_last_success_at';

    /** @var PeopleRepository */
    private $people;

    /** @var SettingsRepository */
    private $settings;

    /** @var string */
    private $keyFile;

    /** @var JobStatus */
    private $status;

    public function __construct(PeopleRepository $people, SettingsRepository $settings, string $keyFile)
    {
        $this->people = $people;
        $this->settings = $settings;
        $this->keyFile = $keyFile;
        $this->status = new JobStatus($settings, 'directory');
    }

    /**
     * Syncs if due. Failures are recorded and retried later; the existing people are kept.
     *
     * @param bool $force Sync now, ignoring the on/off switch and schedule
     * @return string|null What happened, or null if nothing was due
     */
    public function run(int $now, bool $force = false): ?string
    {
        $config = DirectorySettings::load($this->settings);

        if (!$force) {
            $last = $this->status->last();
            $lastAttempt = $last ? strtotime($last['at']) : 0;
            $lastSuccess = strtotime($this->settings->get(self::LAST_SUCCESS_AT, '') ?: '@0');
            if (!$config->enabled
                || $now - $lastSuccess < self::SYNC_INTERVAL
                || $now - $lastAttempt < self::RETRY_INTERVAL) {
                return null;
            }
        }

        $orgUnits = $config->orgUnitList();
        if (empty($orgUnits)) {
            return $this->status->record($now, 'Not synced: no org units are configured.', false);
        }

        try {
            $key = ServiceAccountKey::fromFile($this->keyFile);
            if ($key === null) {
                return $this->status->record($now, 'Not synced: no service account key has been uploaded.', false);
            }

            $client = new GoogleDirectoryClient($key, $config->customerId);
            $people = [];
            foreach ($orgUnits as $orgUnit) {
                try {
                    $users = $client->listUsers($orgUnit);
                } catch (RuntimeException $e) {
                    throw new RuntimeException("$orgUnit: " . rtrim($e->getMessage(), '.') . '.' . self::hint($e->getMessage(), $config->customerId), 0, $e);
                }
                foreach ($users as $person) {
                    $people[$person['google_id']] = $person; // Org units may overlap
                }
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->status->record($now, 'Sync failed: ' . $e->getMessage(), false);
        }

        $activeCount = count(array_filter($people, function (array $person): bool {
            return $person['active'];
        }));

        // An empty result is almost certainly a typo'd org unit; don't deactivate everyone
        if ($activeCount === 0) {
            return $this->status->record($now, 'Sync failed: no active users found in ' . implode(', ', $orgUnits) . '. Check the org unit paths; the existing list was kept.', false);
        }

        $this->people->replaceAll(array_values($people));

        return $this->status->record(
            $now,
            "Synced $activeCount " . ($activeCount === 1 ? 'person' : 'people') . ' from ' . implode(', ', $orgUnits) . '.',
            true,
            [self::LAST_SUCCESS_AT => date('Y-m-d H:i:s', $now)]
        );
    }

    /**
     * Likely fixes for Google's vaguer errors.
     */
    private static function hint(string $error, string $customerId): string
    {
        if (strpos($error, '(400)') !== false) {
            return $customerId === ''
                ? ' Enter your customer ID (Admin console → Account → Account settings), and check that the org unit path exists.'
                : ' Check the customer ID, and that the org unit path exists.';
        }
        if (strpos($error, '(403)') !== false) {
            return ' Check that the service account has been assigned an admin role with Users → Read.';
        }
        return '';
    }

    public function lastResult(): ?array
    {
        return $this->status->last();
    }
}
