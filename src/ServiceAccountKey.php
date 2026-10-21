<?php

/**
 * A Google Cloud service account's JSON key, as downloaded from the Cloud console.
 */
class ServiceAccountKey
{
    const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var string */
    public $clientEmail;

    /** @var string PEM-encoded RSA private key */
    public $privateKey;

    /** @var string */
    public $tokenUri;

    /** @var string */
    public $projectId;

    /**
     * @throws InvalidArgumentException if the JSON isn't a usable service account key
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('The key file is not valid JSON.');
        }
        if (($data['type'] ?? '') !== 'service_account') {
            throw new InvalidArgumentException('The key file is not a service account key (expected "type": "service_account").');
        }
        if (empty($data['client_email']) || empty($data['private_key'])) {
            throw new InvalidArgumentException('The key file is missing its client_email or private_key.');
        }
        if (!openssl_pkey_get_private($data['private_key'])) {
            throw new InvalidArgumentException("The key file's private key couldn't be read.");
        }

        $key = new self();
        $key->clientEmail = $data['client_email'];
        $key->privateKey = $data['private_key'];
        $key->tokenUri = $data['token_uri'] ?? self::DEFAULT_TOKEN_URI;
        $key->projectId = $data['project_id'] ?? '';
        return $key;
    }

    /**
     * @return self|null Null if there is no key file
     * @throws InvalidArgumentException if the file isn't a usable key
     */
    public static function fromFile(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }
        return self::fromJson((string)file_get_contents($path));
    }
}
