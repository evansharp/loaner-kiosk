<?php

/**
 * Read-only Google Admin SDK Directory API client, authenticated as a service account.
 *
 * The service account needs an admin role with the "Users > Read" privilege
 * (Admin console > Account > Admin roles > assign to the service account),
 * and the Admin SDK API enabled in its Google Cloud project.
 *
 * Throws RuntimeException with Google's error message when a request fails.
 */
class GoogleDirectoryClient
{
    const SCOPE = 'https://www.googleapis.com/auth/admin.directory.user.readonly';
    const API_BASE = 'https://admin.googleapis.com/admin/directory/v1';

    /** @var ServiceAccountKey */
    private $key;

    /** @var string */
    private $apiBase;

    /** @var string|null */
    private $accessToken = null;

    /** @var int Seconds to wait for each request */
    private $timeout = 20;

    /** @var string */
    private $customerId;

    /**
     * @param string      $customerId The Workspace customer ID. A service account isn't a user in
     *                                the domain, so Google may reject the 'my_customer' alias for it
     *                                with "Invalid Input"
     * @param string|null $apiBase    Override the API URL (the GOOGLE_DIRECTORY_API_BASE
     *                                environment variable does the same; used for testing)
     */
    public function __construct(ServiceAccountKey $key, string $customerId = '', ?string $apiBase = null)
    {
        $this->key = $key;
        $this->customerId = $customerId !== '' ? $customerId : 'my_customer';
        $this->apiBase = rtrim($apiBase ?: (getenv('GOOGLE_DIRECTORY_API_BASE') ?: self::API_BASE), '/');
    }

    /**
     * All users in an org unit, including its sub-units.
     *
     * @return array[] ['google_id', 'email', 'name', 'org_unit', 'active' => bool]
     */
    public function listUsers(string $orgUnitPath): array
    {
        $people = [];
        $pageToken = null;

        do {
            $params = [
                'customer' => $this->customerId,
                'query' => "orgUnitPath='$orgUnitPath'",
                'maxResults' => 500,
                'projection' => 'basic',
                'fields' => 'nextPageToken,users(id,primaryEmail,name/fullName,orgUnitPath,suspended,archived)',
            ];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->request('GET', $this->apiBase . '/users?' . http_build_query($params), [
                'Authorization: Bearer ' . $this->accessToken(),
            ]);

            foreach ($response['users'] ?? [] as $user) {
                $people[] = [
                    'google_id' => (string)$user['id'],
                    'email' => (string)$user['primaryEmail'],
                    'name' => (string)($user['name']['fullName'] ?? $user['primaryEmail']),
                    'org_unit' => (string)($user['orgUnitPath'] ?? ''),
                    'active' => empty($user['suspended']) && empty($user['archived']),
                ];
            }

            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken);

        return $people;
    }

    /**
     * Exchanges a signed JWT for an access token (OAuth 2.0 service account flow).
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $now = time();
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64Url(json_encode([
            'iss' => $this->key->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $this->key->tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));

        if (!openssl_sign("$header.$claims", $signature, $this->key->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException("Couldn't sign the token request with the service account key.");
        }

        $response = $this->request('POST', $this->key->tokenUri, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => "$header.$claims." . self::base64Url($signature),
        ]));

        if (empty($response['access_token'])) {
            throw new RuntimeException('Google did not return an access token.');
        }
        return $this->accessToken = $response['access_token'];
    }

    /**
     * @return array Decoded JSON response
     */
    private function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $options = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => $this->timeout,
            'ignore_errors' => true, // Read error responses too, for Google's error message
        ];
        if ($body !== null) {
            $options['content'] = $body;
        }

        $raw = @file_get_contents($url, false, stream_context_create(['http' => $options]));
        $host = parse_url($url, PHP_URL_HOST);
        if ($raw === false) {
            throw new RuntimeException("Couldn't reach $host (check the kiosk's internet connection).");
        }

        // The last status line wins if there were redirects
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $status = (int)$matches[1];
            }
        }

        $data = json_decode($raw, true);
        if ($status >= 400 || !is_array($data)) {
            $message = $data['error']['message'] ?? $data['error_description'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null) ?? "unexpected response from $host";
            throw new RuntimeException("Google API error ($status): $message");
        }
        return $data;
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
