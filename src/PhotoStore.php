<?php

/**
 * Saves and locates checkout verification photos at checkout_verification/YYYY/MM/DD/<uuid>.jpg
 */
class PhotoStore
{
    /**
     * @param string $baseDir Absolute directory photos are stored in
     * @param string $baseUrl Same directory, relative to the web root
     */
    public function __construct(private string $baseDir, private string $baseUrl)
    {
    }

    public function save(string $uuid, string $dataUrl): void
    {
        $photoBlob = str_replace('data:image/jpeg;base64,', '', $dataUrl);
        $photoBlob = str_replace(' ', '+', $photoBlob);
        $data = base64_decode($photoBlob);

        $filePath = "{$this->baseDir}/" . $this->relativePath($uuid, time());
        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $data);
    }

    /**
     * Returns the web URL of a checkout's photo, or null if there is none on disk.
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

        return "{$this->baseUrl}/$relativePath";
    }

    private function relativePath(string $uuid, int $timestamp): string
    {
        return date('Y/m/d', $timestamp) . "/$uuid.jpg";
    }
}
