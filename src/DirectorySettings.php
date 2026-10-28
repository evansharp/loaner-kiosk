<?php

/**
 * Google directory settings, as configured on the admin page.
 * (The service account key itself is a file: SERVICE_ACCOUNT_KEY_FILE.)
 */
class DirectorySettings extends SettingsGroup
{
    const KEYS = [
        'enabled' => 'directory_enabled',
        'orgUnits' => 'directory_org_units',
        'allowTypedNames' => 'directory_allow_typed_names',
        'customerId' => 'directory_customer_id',
    ];

    public $enabled = false;
    public $orgUnits = '';            // One org unit path per line, e.g. /Students
    public $allowTypedNames = true;   // Allow checkouts to names not in the directory
    public $customerId = '';          // Google Workspace customer ID, e.g. C01abc23d

    public function applyPost(array $post): void
    {
        $this->enabled = !empty($post['directory_enabled']);
        $this->allowTypedNames = !empty($post['directory_allow_typed_names']);
        $this->customerId = self::postedLine($post, 'directory_customer_id');

        // Normalize: one path per line, accepting commas too; ensure a leading slash, no trailing slash
        $paths = preg_split('/[\r\n,]+/', (string)($post['directory_org_units'] ?? ''));
        $normalized = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $path = '/' . trim($path, '/');
            $normalized[$path] = true;
        }
        $this->orgUnits = implode("\n", array_keys($normalized));
    }

    /**
     * @param bool $hasKey Whether a service account key has been uploaded
     */
    public function validate(bool $hasKey): array
    {
        $errors = [];

        foreach ($this->orgUnitList() as $path) {
            // Quotes and backslashes would break the Directory API query
            if (preg_match('/[\'"\\\\]/', $path)) {
                $errors[] = "Org unit \"$path\" can't contain quotes or backslashes.";
            }
        }

        if ($this->customerId !== '' && !preg_match('/^C[0-9a-z]+$/i', $this->customerId)) {
            $errors[] = 'The customer ID should look like C01abc23d (Admin console → Account → Account settings).';
        }

        if ($this->enabled) {
            if (empty($this->orgUnitList())) {
                $errors[] = 'Enter at least one org unit to sync, e.g. /Students.';
            }
            if (!$hasKey) {
                $errors[] = 'Upload a service account key to use the directory.';
            }
        }

        return $errors;
    }

    public function orgUnitList(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->orgUnits)), 'strlen'));
    }
}
