<?php

declare(strict_types=1);

/**
 * ClientFence: HTTP access to an Enhance control panel's API (orchd). Every call is
 * authenticated with the server's API token and answers JSON; errors become \Server_Exception
 * with a message that is safe to show a client (Enhance's own reason when it gives one, never
 * the internal API address). GET answers are kept for up to a minute (and until this object
 * sends a change), so one page asks Enhance each question once and a long cron run still reads
 * fresh answers.
 */

namespace ClientFence\Mod\Servicehosting\Panel\Enhance;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Api
{
    public const PANEL = 'Enhance';

    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 200;
    // Seconds without data before a request is given up, and the most one request may take.
    private const IDLE_TIMEOUT = 20;
    private const MAX_DURATION = 60;
    private const GET_TRIES = 2;
    // Seconds a GET answer is kept.
    private const CACHE_SECONDS = 60;

    /** @var array<string, array{0: int, 1: mixed}> path and query => [time, answer] */
    private array $cache = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly bool $verifyTls = true,
        private readonly ?\ClientFence\Log $log = null,
    ) {
    }

    public function get(string $path, array $query = []): mixed
    {
        return $this->cached($path, $query, []);
    }

    /**
     * A GET that answers null instead of failing when the panel says the thing does not exist.
     */
    public function find(string $path, array $query = []): mixed
    {
        return $this->cached($path, $query, [404]);
    }

    public function post(string $path, array $body = [], array $query = [], array $tolerate = []): mixed
    {
        return $this->change('POST', $path, $body, $query, $tolerate);
    }

    public function put(string $path, array $body = [], array $tolerate = []): mixed
    {
        return $this->change('PUT', $path, $body, [], $tolerate);
    }

    public function patch(string $path, array $body = [], array $tolerate = []): mixed
    {
        return $this->change('PATCH', $path, $body, [], $tolerate);
    }

    public function delete(string $path, array $query = [], array $tolerate = [404]): mixed
    {
        return $this->change('DELETE', $path, null, $query, $tolerate);
    }

    /**
     * Where a GET that answers with a redirect (one-time sign-in links) points to; never followed.
     */
    public function location(string $path): string
    {
        $this->cache = [];
        $url = $this->send('GET', $path, null, [], [], true);
        if (!is_string($url) || !str_starts_with($url, 'https://')) {
            throw new \Server_Exception('The :panel: server did not give a sign-in link. Please try again later.', [':panel:' => self::PANEL]);
        }

        return $url;
    }

    /**
     * Every item of a paged listing (`items` and `total`), fetched page by page.
     *
     * @return \Generator<int, array>
     */
    public function each(string $path, array $query = []): \Generator
    {
        $offset = 0;
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $answer = $this->get($path, $query + ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
            $items = is_array($answer) && is_array($answer['items'] ?? null) ? $answer['items'] : [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }
            $offset += count($items);
            if ($items === [] || $offset >= (int) ($answer['total'] ?? 0)) {
                return;
            }
        }
    }

    /**
     * The `id` of a create answer, or a failure that names the step.
     */
    public static function id(mixed $answer, string $step): string
    {
        if (!is_array($answer) || !isset($answer['id']) || (string) $answer['id'] === '') {
            throw new \Server_Exception('The :panel: server did not confirm the step ":step:". Please try again later.', [':panel:' => self::PANEL, ':step:' => $step]);
        }

        return (string) $answer['id'];
    }

    private function cached(string $path, array $query, array $tolerate): mixed
    {
        $key = $path . '?' . http_build_query($query) . '|' . implode(',', $tolerate);
        if (!isset($this->cache[$key]) || $this->cache[$key][0] < time() - self::CACHE_SECONDS) {
            $this->cache[$key] = [time(), $this->send('GET', $path, null, $query, $tolerate)];
        }

        return $this->cache[$key][1];
    }

    private function change(string $method, string $path, ?array $body, array $query, array $tolerate): mixed
    {
        // A change makes earlier answers stale.
        $this->cache = [];

        return $this->send($method, $path, $body, $query, $tolerate);
    }

    private function send(string $method, string $path, ?array $body, array $query, array $tolerate = [], bool $redirect = false): mixed
    {
        $url = $this->baseUrl . '/api' . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'],
            'timeout' => self::IDLE_TIMEOUT,
            'max_duration' => self::MAX_DURATION,
            'verify_peer' => $this->verifyTls,
            'verify_host' => $this->verifyTls,
        ];
        if ($redirect) {
            $options['max_redirects'] = 0;
        }
        if ($body !== null) {
            // Enhance reads an empty JSON object, not an empty list.
            $options['body'] = $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $options['headers']['Content-Type'] = 'application/json';
        }

        // A GET is asked once more after a transport error (the panel drops a connection now and then).
        for ($try = 1; ; ++$try) {
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();
                $content = $response->getContent(false);
                $location = (string) ($response->getHeaders(false)['location'][0] ?? '');

                break;
            } catch (ExceptionInterface $e) {
                // Host, DNS, TLS or timeout: the full error goes to the log, its short reason to staff.
                $this->log?->error(sprintf('%s API %s %s could not be sent (try %d): %s', self::PANEL, $method, $path, $try, $e->getMessage()));
                if ($method !== 'GET' || $try >= self::GET_TRIES) {
                    throw self::unreachable($e->getMessage());
                }
                usleep(500_000);
            }
        }

        if ($status >= 400) {
            if (in_array($status, $tolerate, true)) {
                return null;
            }
            $reason = self::reason($content);
            $this->log?->error(sprintf('%s API %s %s answered %d: %s', self::PANEL, $method, $path, $status, substr($content, 0, 500)));
            if ($status === 401 || ($status === 403 && $reason === '')) {
                throw new \Server_Exception('The :panel: server refused the API token. Check the server settings (organization ID, token and its allowed IP addresses).', [':panel:' => self::PANEL]);
            }
            if ($reason !== '' && $status < 500) {
                throw new \Server_Exception(':reason', [':reason' => $reason]);
            }

            throw new \Server_Exception('The :panel: server could not complete the request. Please try again later.', [':panel:' => self::PANEL]);
        }

        // Errors (400 and up) were handled above.
        if ($redirect && $status >= 300) {
            return $location;
        }
        if ($status === 204 || trim($content) === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            // Some calls (sign-in links) answer a bare string.
            $text = trim($content);

            return str_starts_with($text, 'http') ? $text : throw new \Server_Exception('The :panel: server sent an answer that could not be read.', [':panel:' => self::PANEL]);
        }

        return $decoded;
    }

    /**
     * CF-181: the short reason the HTTP client (curl) could not reach the panel, with what to check, instead of only "try
     * again later". The client's error is not shown: it holds the request URL.
     */
    private static function unreachable(string $error): \Server_Exception
    {
        $panel = [':panel:' => self::PANEL];
        // Symfony adds ` for "<url>"`: the host name must not decide the reason (ssl.example.com).
        $error = explode(' for "', $error, 2)[0];

        return match (true) {
            preg_match('/resolve host|getaddrinfo|name or service not known|no such host/i', $error) === 1 => new \Server_Exception('Could not reach the :panel: server: its host name was not found. The Hostname must be the address the :panel: control panel opens at.', $panel),
            preg_match('/wrong version number|packet length too long|unknown protocol|record layer failure/i', $error) === 1 => new \Server_Exception('Could not reach the :panel: server: nothing answers HTTPS on this port. Check the Connection Port in the server settings.', $panel),
            preg_match('/refused|couldn\'t connect/i', $error) === 1 => new \Server_Exception('Could not reach the :panel: server: the connection was refused. Check the Connection Port, and that the panel\'s firewall lets this server connect.', $panel),
            preg_match('/timed out|timeout/i', $error) === 1 => new \Server_Exception('Could not reach the :panel: server: the connection timed out. Check the Hostname and the Connection Port, and that a firewall lets this server connect.', $panel),
            // Only a failed certificate check: a connection dropped inside TLS (SSL_read, SSL_ERROR_SYSCALL) is not one.
            preg_match('/certificate|subject name|self.signed|issuer|verify failed/i', $error) === 1 => new \Server_Exception('Could not reach the :panel: server: its TLS certificate could not be verified. Use the host name the certificate was issued for, or turn off Verify TLS Certificate in the server settings.', $panel),
            default => new \Server_Exception('Could not reach the :panel: server. Please try again later.', $panel),
        };
    }

    private static function reason(string $content): string
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return '';
        }
        $reason = $decoded['message'] ?? $decoded['detail'] ?? $decoded['error'] ?? '';

        return is_string($reason) ? trim($reason) : '';
    }
}
