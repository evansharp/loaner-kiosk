<?php

/**
 * Minimal SMTP client for sending mail (plain text, or HTML with a plain-text
 * alternative) through a relay such as Google's
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
     * @param string[]    $recipients
     * @param string      $textBody Plain-text body (the whole message, or the fallback for $htmlBody)
     * @param string|null $htmlBody HTML version; sent as multipart/alternative with $textBody
     * @param string[]    $replyTo  Addresses replies should go to (default: the From address)
     */
    public function send(string $fromAddress, string $fromName, array $recipients, string $subject, string $textBody, ?string $htmlBody = null, array $replyTo = []): void
    {
        $this->connect();

        try {
            $this->command("MAIL FROM:<$fromAddress>", [250]);
            foreach ($recipients as $recipient) {
                $this->command("RCPT TO:<$recipient>", [250, 251]);
            }
            $this->command('DATA', [354]);
            $this->command($this->buildMessage($fromAddress, $fromName, $recipients, $subject, $textBody, $htmlBody, $replyTo) . "\r\n.", [250], 'message data');

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

    private function buildMessage(string $fromAddress, string $fromName, array $recipients, string $subject, string $textBody, ?string $htmlBody, array $replyTo): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($fromAddress, $fromName),
            'To: ' . implode(', ', $recipients),
        ];
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . implode(', ', $replyTo);
        }
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->heloDomain . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Auto-Submitted: auto-generated';

        if ($htmlBody === null) {
            $headers = array_merge($headers, $this->partHeaders('text/plain'));
            $body = $this->encodeBody($textBody);
        } else {
            // Mail clients show the last alternative they can display: plain text first, then HTML
            $boundary = '=_' . bin2hex(random_bytes(12));
            $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
            $body = "--$boundary\r\n" . implode("\r\n", $this->partHeaders('text/plain')) . "\r\n\r\n" . $this->encodeBody($textBody) . "\r\n"
                . "--$boundary\r\n" . implode("\r\n", $this->partHeaders('text/html')) . "\r\n\r\n" . $this->encodeBody($htmlBody) . "\r\n"
                . "--$boundary--";
        }

        $headers = str_replace(["\r", "\n"], '', $headers);
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function partHeaders(string $mimeType): array
    {
        return ["Content-Type: $mimeType; charset=UTF-8", 'Content-Transfer-Encoding: base64'];
    }

    /**
     * Base64 bodies are safe for any characters and never need SMTP dot-stuffing.
     */
    private function encodeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\n"], "\r\n", $body);
        return rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
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
