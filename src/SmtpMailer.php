<?php

/**
 * Minimal SMTP client for sending plain-text mail through a relay such as Google's
 * smtp-relay.gmail.com. Supports STARTTLS, implicit SSL/TLS, and optional AUTH LOGIN
 * (leave the username blank when the relay authenticates by IP address).
 *
 * Throws RuntimeException with the server's reply when anything goes wrong.
 */
class SmtpMailer
{
    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string tls (STARTTLS), ssl (implicit TLS) or none */
    private $encryption;

    /** @var string */
    private $heloDomain;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var int Seconds to wait for the connection and each server reply */
    private $timeout;

    /** @var resource|null */
    private $socket = null;

    public function __construct(string $host, int $port, string $encryption, string $heloDomain, string $username = '', string $password = '', int $timeout = 15)
    {
        $this->host = $host;
        $this->port = $port;
        $this->encryption = $encryption;
        $this->heloDomain = $heloDomain;
        $this->username = $username;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    /**
     * @param string[] $recipients
     */
    public function send(string $fromAddress, string $fromName, array $recipients, string $subject, string $body): void
    {
        $this->connect();

        try {
            $this->command("MAIL FROM:<$fromAddress>", [250]);
            foreach ($recipients as $recipient) {
                $this->command("RCPT TO:<$recipient>", [250, 251]);
            }
            $this->command('DATA', [354]);
            $this->command($this->buildMessage($fromAddress, $fromName, $recipients, $subject, $body) . "\r\n.", [250], 'message data');

            try {
                $this->command('QUIT', [221]);
            } catch (RuntimeException $e) {
                // The message was accepted; a failed goodbye doesn't matter
            }
        } finally {
            $this->disconnect();
        }
    }

    private function connect(): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $context = stream_context_create(['ssl' => [
            'peer_name' => $this->host,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]);

        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new RuntimeException("Could not connect to {$this->host}:{$this->port}: " . ($errstr ?: 'connection failed') . " ($errno)");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        try {
            $this->expect([220], 'connection');
            $this->command("EHLO {$this->heloDomain}", [250]);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException("TLS negotiation with {$this->host} failed (check the encryption type and port).");
                }
                $this->command("EHLO {$this->heloDomain}", [250]); // Re-introduce over the encrypted channel
            }

            if ($this->username !== '') {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($this->username), [334], 'AUTH username');
                $this->command(base64_encode($this->password), [235], 'AUTH password');
            }
        } catch (RuntimeException $e) {
            $this->disconnect();
            throw $e;
        }
    }

    private function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Sends one command and checks the reply code.
     *
     * @param int[]       $expectedCodes
     * @param string|null $label How to name the command in errors (keeps credentials out of messages)
     */
    private function command(string $command, array $expectedCodes, ?string $label = null): void
    {
        if (fwrite($this->socket, $command . "\r\n") === false) {
            throw new RuntimeException("Lost connection to {$this->host} while sending " . ($label ?: $command) . '.');
        }
        $this->expect($expectedCodes, $label ?: $command);
    }

    /**
     * Reads a (possibly multi-line) reply and throws unless its code is expected.
     */
    private function expect(array $expectedCodes, string $after): void
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            // Multi-line replies use "250-..." for every line but the last ("250 ...")
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($reply === '') {
            $meta = stream_get_meta_data($this->socket);
            $problem = $meta['timed_out'] ? 'timed out waiting for' : 'closed the connection after';
            throw new RuntimeException("{$this->host} $problem $after.");
        }

        $code = (int)substr($reply, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            $reply = preg_replace('/\s+/', ' ', trim($reply));
            throw new RuntimeException("{$this->host} rejected $after: $reply");
        }
    }

    private function buildMessage(string $fromAddress, string $fromName, array $recipients, string $subject, string $body): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($fromAddress, $fromName),
            'To: ' . implode(', ', $recipients),
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->heloDomain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        $headers = str_replace(["\r", "\n"], '', $headers);

        // Base64 body: safe for any characters and never needs SMTP dot-stuffing
        $body = str_replace(["\r\n", "\n"], "\r\n", $body);
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    private function formatAddress(string $address, string $name): string
    {
        if ($name === '') {
            return $address;
        }
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return $this->encodeHeader($name) . " <$address>";
        }
        return '"' . addcslashes($name, '"\\') . "\" <$address>";
    }

    /**
     * RFC 2047 encoding for header text that isn't plain ASCII.
     */
    private function encodeHeader(string $text): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $text)) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
}
