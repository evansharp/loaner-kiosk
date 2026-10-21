<?php

/**
 * Saves and locates checkout verification photos at <baseDir>/YYYY/MM/DD/<uuid>.jpg
 * Photos are stored outside the web root and served to logged-in admins by public/photo.php.
 */
class PhotoStore
{
    // Photo UUIDs come from the browser; only allow safe filename characters
    const UUID_PATTERN = '[A-Za-z0-9-]{1,64}';

    /** @var string Absolute directory photos are stored in */
    private $baseDir;

    /** @var string URL prefix the relative photo path is appended to (e.g. "photo.php?f=") */
    private $urlPrefix;

    public function __construct(string $baseDir, string $urlPrefix = '')
    {
        $this->baseDir = $baseDir;
        $this->urlPrefix = $urlPrefix;
    }

    /**
     * @return bool False if the UUID isn't a safe filename (the photo is not saved)
     */
    public function save(string $uuid, string $dataUrl): bool
    {
        if (!preg_match('/^' . self::UUID_PATTERN . '$/', $uuid)) {
            return false;
        }

        $photoBlob = str_replace('data:image/jpeg;base64,', '', $dataUrl);
        $photoBlob = str_replace(' ', '+', $photoBlob);
        $data = base64_decode($photoBlob);

        $filePath = "{$this->baseDir}/" . $this->relativePath($uuid, time());
        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($filePath, $data) !== false;
    }

    /**
     * Returns the URL of a checkout's photo, or null if there is none on disk.
     */
    public function urlFor(?string $uuid, string $checkoutTime): ?string
    {
        if (empty($uuid)) {
            return null;
        }

        $relativePath = $this->relativePath($uuid, strtotime($checkoutTime));
        if (!file_exists("{$this->baseDir}/$relativePath")) {
            return null;
        }

        return $this->urlPrefix . rawurlencode($relativePath);
    }

    /**
     * Turns a relative photo path from a URL back into a file path, or null if it isn't a valid photo.
     */
    public function resolve(string $relativePath): ?string
    {
        if (!preg_match('#^\d{4}/\d{2}/\d{2}/' . self::UUID_PATTERN . '\.jpg$#', $relativePath)) {
            return null;
        }
        $file = "{$this->baseDir}/$relativePath";
        return is_file($file) ? $file : null;
    }

    private function relativePath(string $uuid, int $timestamp): string
    {
        return date('Y/m/d', $timestamp) . "/$uuid.jpg";
    }
}
