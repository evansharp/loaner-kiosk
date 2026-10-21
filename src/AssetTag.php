<?php

class AssetTag
{
    /**
     * Parses and validates an asset tag input.
     * Accepts:
     *   1. Direct integer Asset IDs (e.g., "10")
     *   2. Asset URL pattern ending with an integer ID (e.g., "HTTPS://ASSETS.COASTMOUNTAINACADEMY.CA/HARDWARE/10")
     *
     * Returns extracted integer asset number on success, or null if invalid.
     */
    public static function parse(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // Direct integer asset ID (e.g., "10")
        if (preg_match('/^\d+$/', $input)) {
            return $input;
        }

        // URL pattern ending with an integer segment (case-insensitive)
        if (preg_match('/^https?:\/\/[^\s]+\/(\d+)\/?$/i', $input, $matches)) {
            return $matches[1];
        }

        return null; // Invalid input
    }
}
