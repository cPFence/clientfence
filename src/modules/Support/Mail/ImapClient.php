<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support\Mail;

/**
 * ClientFence: the few IMAP commands ticket email import needs (RFC 3501), over a plain PHP
 * stream: no imap extension (not bundled since PHP 8.4) and no library.
 *
 * Connect over TLS ("ssl", usually port 993), STARTTLS ("tls", port 143) or, for a mail server on
 * the same machine or a test server, plain ("none"). Certificates are checked unless
 * $verifyTls is false (a self-signed certificate on the same machine).
 *
 * Messages are addressed by UID; fetch() reads a whole message without marking it seen, and
 * delete() + expunge() remove it once imported (WHMCS deletes imported mail too).
 *
 * A broken or hostile server cannot hold the cron or its memory: every command has a deadline,
 * lines and literals have size limits (setMaxLiteral()), and text sent before STARTTLS completes
 * is refused.
 */
final class ImapClient
{
    public const ENCRYPTION = ['ssl', 'tls', 'none'];

    /** Longest line accepted from the server (a SEARCH answer of a large mailbox is one line). */
    private const MAX_LINE = 4 * 1024 * 1024;

    /** @var resource|null */
    private $stream;
    private int $tag = 0;
    private float $deadline = 0.0;
    private int $maxLiteral = 64 * 1024 * 1024;

    public function __construct(private readonly int $timeout = 20)
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    /** The largest message (literal) the server may send; larger ends the connection. */
    public function setMaxLiteral(int $bytes): void
    {
        $this->maxLiteral = max(1024, $bytes);
    }

    /** Whether the connection is still usable (a refused command leaves it so; a lost one not). */
    public function isConnected(): bool
    {
        return is_resource($this->stream) && !feof($this->stream);
    }

    public function connect(string $host, int $port, string $encryption, bool $verifyTls = true): void
    {
        if (!in_array($encryption, self::ENCRYPTION, true)) {
            throw new MailException('Unknown encryption: choose SSL/TLS, STARTTLS or none.');
        }
        if ($host === '' || preg_match('/[\s\/]/', $host) === 1 || $port < 1 || $port > 65535) {
            throw new MailException('The mail server host or port is not valid.');
        }
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
            'allow_self_signed' => !$verifyTls,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client($address, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($stream)) {
            $reason = $error !== '' ? $error : ($encryption === 'ssl' ? 'no answer, or the TLS handshake failed (with a self-signed certificate, turn off checking the certificate)' : 'no answer');

            throw new MailException('Could not connect to :host::port: :error', [':host' => $host, ':port' => $port, ':error' => $reason]);
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;
        $this->deadline = microtime(true) + $this->timeout * 3;

        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            $this->close();

            throw new MailException('The mail server did not greet as an IMAP server.');
        }
        if ($encryption === 'tls') {
            $this->command('STARTTLS');
            // Anything the server sent after its answer, before TLS, could be an attacker's.
            if (stream_get_meta_data($stream)['unread_bytes'] > 0) {
                $this->close();

                throw new MailException('STARTTLS failed: the mail server sent unexpected data.');
            }
            if (!@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                $this->close();

                throw new MailException('STARTTLS failed: the mail server certificate could not be checked or the server refused TLS.');
            }
        }
    }

    public function login(string $username, string $password): void
    {
        $this->command(['LOGIN ', self::astring($username), ' ', self::astring($password)], 'Login failed: check the username and password.');
    }

    /** Opens a folder for reading and deleting; returns how many messages it holds. */
    public function select(string $folder): int
    {
        $count = 0;
        foreach ($this->command('SELECT ' . self::quote($folder), 'The folder ":folder" could not be opened.', [':folder' => $folder]) as $line) {
            if (is_string($line) && preg_match('/^\* (\d+) EXISTS/i', $line, $m) === 1) {
                $count = (int) $m[1];
            }
        }

        return $count;
    }

    /**
     * UIDs of the folder's messages not marked deleted, oldest first.
     *
     * @return int[]
     */
    public function uids(): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH UNDELETED') as $line) {
            if (is_string($line) && str_starts_with(strtoupper($line), '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }
        sort($uids);

        return $uids;
    }

    /** A message's size in bytes (null when the server does not say). */
    public function size(int $uid): ?int
    {
        foreach ($this->command('UID FETCH ' . $uid . ' (RFC822.SIZE)') as $line) {
            if (is_string($line) && preg_match('/RFC822\.SIZE (\d+)/i', $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /** The whole message (headers and body) as sent; it stays unread. */
    public function fetch(int $uid): string
    {
        $lines = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        foreach ($lines as $line) {
            if (is_array($line)) {
                return $line['literal'];
            }
        }

        throw new MailException('Message :uid could not be read.', [':uid' => $uid]);
    }

    /** Only a message's headers (for a message too large to read whole). */
    public function fetchHeader(int $uid): string
    {
        foreach ($this->command('UID FETCH ' . $uid . ' (BODY.PEEK[HEADER])') as $line) {
            if (is_array($line)) {
                return $line['literal'];
            }
        }

        throw new MailException('Message :uid could not be read.', [':uid' => $uid]);
    }

    public function delete(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\Deleted)');
    }

    /** Removes the messages marked deleted. */
    public function expunge(): void
    {
        $this->command('EXPUNGE');
    }

    public function logout(): void
    {
        if ($this->stream !== null) {
            try {
                $this->command('LOGOUT');
            } catch (MailException) {
                // Leaving anyway.
            }
        }
        $this->close();
    }

    private function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    /**
     * Sends a command and reads its reply up to the tagged status. Untagged lines come back as
     * strings; a line that carried a literal ({n} followed by n bytes) as
     * ['line' => ..., 'literal' => ...]. A command given as parts may hold literals to send
     * (['literal' => bytes]), each after the server's go-ahead.
     *
     * @param string|array<int, string|array{literal: string}> $command
     *
     * @return array<int, string|array{line: string, literal: string}>
     */
    private function command(string|array $command, string $failure = 'The mail server refused a command.', array $vars = []): array
    {
        if (!is_resource($this->stream)) {
            throw new MailException('Not connected to the mail server.');
        }
        $this->deadline = microtime(true) + max(60, $this->timeout * 6);
        $tag = 'B' . str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        $buffer = $tag . ' ';
        foreach (is_array($command) ? $command : [$command] as $part) {
            if (is_string($part)) {
                $buffer .= $part;

                continue;
            }
            $this->write($buffer . '{' . strlen($part['literal']) . "}\r\n");
            if (!str_starts_with($this->readLine(), '+')) {
                throw new MailException($failure, $vars);
            }
            $buffer = $part['literal'];
        }
        $this->write($buffer . "\r\n");
        $lines = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}$/', $line, $m) === 1) {
                $literal = $this->readBytes((int) $m[1]);
                $rest = $this->readLine();
                $lines[] = ['line' => $line . $rest, 'literal' => $literal];

                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper(substr($line, strlen($tag) + 1, 2));
                if ($status !== 'OK') {
                    throw new MailException($failure, $vars);
                }

                return $lines;
            }
            $lines[] = $line;
        }
    }

    private function write(string $data): void
    {
        if (!is_resource($this->stream) || @fwrite($this->stream, $data) === false) {
            $this->close();

            throw new MailException('The connection to the mail server was lost.');
        }
    }

    private function readLine(): string
    {
        $line = '';
        while (!str_ends_with($line, "\n")) {
            $this->checkDeadline();
            $chunk = is_resource($this->stream) ? fgets($this->stream, 65536) : false;
            if ($chunk === false) {
                $timedOut = is_resource($this->stream) && stream_get_meta_data($this->stream)['timed_out'];
                $this->close();

                throw new MailException($timedOut ? 'The mail server did not answer in time.' : 'The connection to the mail server was lost.');
            }
            $line .= $chunk;
            if (strlen($line) > self::MAX_LINE) {
                $this->close();

                throw new MailException('The mail server sent a line that is too long.');
            }
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        if ($length > $this->maxLiteral) {
            $this->close();

            throw new MailException('The mail server sent more than the largest email allowed.');
        }
        $data = '';
        while (strlen($data) < $length) {
            $this->checkDeadline();
            $chunk = is_resource($this->stream) ? fread($this->stream, min(65536, $length - strlen($data))) : false;
            if ($chunk === false || $chunk === '') {
                $this->close();

                throw new MailException('The connection to the mail server was lost.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function checkDeadline(): void
    {
        if (microtime(true) > $this->deadline) {
            $this->close();

            throw new MailException('The mail server did not answer in time.');
        }
    }

    /** A value for a command: a quoted string, or a literal when it is not plain ASCII. */
    private static function astring(string $value): string|array
    {
        return preg_match('/^[\x20-\x7e]*$/', $value) === 1 ? self::quote($value) : ['literal' => $value];
    }

    /** An IMAP quoted string; line breaks and NUL cannot be sent. */
    public static function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new MailException('Line breaks are not allowed in mail server settings.');
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
