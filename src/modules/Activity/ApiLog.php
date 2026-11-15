<?php

declare(strict_types=1);

namespace ClientFence\Mod\Activity;

use ClientFence\Config;
use ClientFence\PaginationOptions;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Request;

/**
 * ClientFence: the API request log (CF-123). Settings in Security > Settings > API log (config.php `api.log`, Super
 * Administrators only), the list in Activity > System Activity > API log (activity view permission).
 *
 * One row in activity_api for each request of a ticked source: staff API keys, payment gateway notices (ipn.php), the
 * staff pages' API calls and client and guest changes (POST, never reads). It keeps who, the call, the address, the
 * HTTP status and result, the time taken and the parameters with secrets hidden (at most MAX_PARAMS bytes), never the
 * response. The row is written after the response is sent; with every source off nothing is done at all (the settings
 * come from config.php, which the request has already read). Cron deletes rows older than the days kept.
 *
 * Also what a gateway notice keeps of the request on its transaction (noticeServer(), B11).
 */
final class ApiLog
{
    public const TABLE = 'activity_api';
    /** api_key: staff API keys; webhook: gateway notices; staff: the admin pages' API calls; client: client and guest changes. */
    public const SOURCES = ['api_key', 'webhook', 'staff', 'client'];
    public const DAYS = 30;
    public const MAX_DAYS = 3650;
    public const MAX_PARAMS = 4096;
    public const HIDDEN = '********';
    /** Field names whose values the log keeps (mask()). */
    private const SHOWN = '/^(id|ids|[a-z0-9_]+_id|[a-z0-9_]+_ids|status|state|type|kind|period|page|per_page|section|do|action|quantity|qty|currency|lang|days|sort|order|format|source|priority|mode|enabled|active)$/';
    /** Request fields a gateway notice keeps besides its headers. */
    private const NOTICE_FIELDS = ['REQUEST_METHOD', 'REQUEST_URI', 'QUERY_STRING', 'REMOTE_ADDR', 'CONTENT_TYPE', 'CONTENT_LENGTH'];
    /** Cron deletes at most PRUNE_BATCH x PRUNE_BATCHES rows a run, so a big log never locks the table for long. */
    private const PRUNE_BATCH = 5000;
    private const PRUNE_BATCHES = 20;

    /** @var list<array<string, mixed>> rows waiting for the end of the request */
    public static array $pending = [];

    /** Why ipn.php refused or failed the notice, for its row. */
    public static ?string $webhookError = null;

    /**
     * The settings from config.php `api.log`: each source on or off, and the days kept.
     *
     * @return array{sources: array<string, bool>, days: int}
     */
    public static function settings(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $days = (int) ($saved['days'] ?? self::DAYS);

        return [
            'sources' => array_combine(self::SOURCES, array_map(static fn (string $source): bool => !empty($saved[$source]), self::SOURCES)),
            'days' => $days >= 1 && $days <= self::MAX_DAYS ? $days : self::DAYS,
        ];
    }

    /** The source of an API request, or null when it is never logged (a client or guest read). */
    public static function source(string $role, bool $viaKey, bool $post, string $call): ?string
    {
        return match (true) {
            $role === 'admin' => $viaKey ? 'api_key' : 'staff',
            // The client area posts its reads too: a read is named ..._get, ..._list or ..._search (cart_get, invoice_get_list) or
            // ends in _status, _quote, _options or _user_session (id_document_status; not quote_accept).
            $post && preg_match('/_(get|list|search)(_|$)|_(status|quote|options|user_session)$/', $call) !== 1 => 'client',
            default => null,
        };
    }

    /**
     * After an API call (Api\Controller\Client::tryCall()): queues its row when its source is ticked.
     * $saved: config.php `api.log`, as the controller already holds it.
     */
    public static function api(\Pimple\Container $di, mixed $saved, string $role, string $call, array $params, int $status, ?string $error, bool $viaKey): void
    {
        $sources = self::settings($saved)['sources'];
        if (!in_array(true, $sources, true)) {
            return;
        }
        $request = $di['request'];
        $source = self::source($role, $viaKey, $request->isMethod('POST'), $call);
        if ($source === null || !$sources[$source]) {
            return;
        }
        $session = $di['session'];
        $admin = $role === 'admin' ? $session->get('admin') : null;
        $adminId = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
        $clientId = $role === 'admin' ? 0 : (int) $session->get('client_id');
        self::later($di, self::row($source, $request, $call, $params, $adminId ?: null, $clientId ?: null, $status, $error));
    }

    /** At the start of ipn.php: logs the notice once it is answered, when gateway notices are ticked. */
    public static function webhook(\Pimple\Container $di, Request $request): void
    {
        if (!self::settings(Config::getProperty('api.log', []))['sources']['webhook']) {
            return;
        }
        // ipn.php answers in several places (and may go on after answering): the row is made at the very end.
        register_shutdown_function(static function () use ($di, $request): void {
            $status = (int) (http_response_code() ?: 200);
            $gateway = (int) $request->query->get('gateway_id');
            self::$pending[] = self::row('webhook', $request, '/ipn.php' . ($gateway > 0 ? '?gateway_id=' . $gateway : ''), ['get' => $request->query->all(), 'post' => $request->request->all()], null, null, $status, self::$webhookError ?? ($status >= 400 ? 'HTTP ' . $status : null));
            self::flush($di);
        });
    }

    /** @return array<string, mixed> one activity_api row */
    public static function row(string $source, Request $request, string $call, array $params, ?int $adminId, ?int $clientId, int $status, ?string $error): array
    {
        $started = (float) $request->server->get('REQUEST_TIME_FLOAT', microtime(true));

        return [
            'source' => $source,
            'method' => substr($request->getMethod(), 0, 8),
            'api_call' => mb_substr($call, 0, 191),
            'admin_id' => $adminId,
            'client_id' => $clientId,
            'ip' => $request->getClientIp(),
            'status' => $status,
            'result' => $error === null ? 'ok' : mb_substr($error, 0, 255),
            'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
            'params' => self::params($params),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** The parameters as JSON with secrets hidden, cut to MAX_PARAMS bytes. */
    public static function params(array $params): string
    {
        $json = (string) json_encode(self::mask($params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return strlen($json) > self::MAX_PARAMS ? mb_strcut($json, 0, self::MAX_PARAMS - 3) . '...' : $json;
    }

    /**
     * Keeps every field name but only the values of harmless fields (ids, states, choices: SHOWN) and booleans; any
     * other value is hidden, so a secret in a field no list knows of (a ticket secret, a registrar token, a webhook
     * URL) never reaches the log. Card numbers are hidden even under a shown name.
     */
    public static function mask(array $data, int $depth = 10, bool $shown = false): array
    {
        foreach ($data as $key => $value) {
            $keep = is_int($key) ? $shown : preg_match(self::SHOWN, strtolower($key)) === 1;
            if (is_array($value)) {
                $data[$key] = $depth > 0 ? self::mask($value, $depth - 1, $keep) : self::HIDDEN;
            } elseif (!is_bool($value) && $value !== null && (!$keep || (is_string($value) && self::cardNumber($value)))) {
                $data[$key] = self::HIDDEN;
            }
        }

        return $data;
    }

    /**
     * A field name of a secret: those the event log hides (password, pass, token, key, apisecret, secret, api_token),
     * signatures, card data, PINs and codes, invoice hashes (they open the invoice), cookies and authorization.
     */
    public static function secret(string $name): bool
    {
        $name = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name));

        return preg_match('/pass|secret|token|signature|cvv|cvc|card|iban|authorization|cookie|^key|key$|pin$|hash$|^(otp|code|number|sig|cc)$/', $name) === 1;
    }

    /** 13 to 19 digits (spaces and dashes allowed) that pass the Luhn check. */
    private static function cardNumber(string $value): bool
    {
        $digits = (string) preg_replace('/[\s-]/', '', $value);
        if (preg_match('/^\d{13,19}$/', $digits) !== 1) {
            return false;
        }
        $sum = 0;
        foreach (str_split(strrev($digits)) as $i => $digit) {
            $digit = (int) $digit * ($i % 2 === 1 ? 2 : 1);
            $sum += $digit > 9 ? $digit - 9 : $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * B11: what a gateway notice keeps of the server array (Invoice\ServiceTransaction::create()): the request headers
     * and basic request fields, never the server's environment values. Secret headers (Authorization, Cookie) are
     * hidden, but signature headers stay: the adapters check them again when staff process the notice again
     * (Stripe-Signature, GoCardless Webhook-Signature, PayPal-Transmission-Sig).
     */
    public static function noticeServer(mixed $server): ?array
    {
        if (!is_array($server)) {
            return null;
        }
        $kept = [];
        foreach ($server as $name => $value) {
            $name = (string) $name;
            if (in_array($name, self::NOTICE_FIELDS, true) || str_starts_with($name, 'HTTP_')) {
                $kept[$name] = str_contains($name, 'SIG') || !self::secret($name) ? $value : self::HIDDEN;
            }
        }

        return $kept;
    }

    /** Queues a row, written once the response is sent (Symfony's send() ends the request before shutdown functions run). */
    private static function later(\Pimple\Container $di, array $row): void
    {
        if (self::$pending === []) {
            register_shutdown_function(static fn () => self::flush($di));
        }
        self::$pending[] = $row;
    }

    /** Writes the queued rows; a missing table (update not finalized yet) or a database error never reaches the caller. */
    public static function flush(\Pimple\Container $di): void
    {
        $rows = self::$pending;
        self::$pending = [];
        foreach ($rows as $row) {
            try {
                $di['dbal']->insert(self::TABLE, $row);
            } catch (\Throwable $e) {
                error_log('API log: ' . $e->getMessage());
            }
        }
    }

    /** Cron (Activity\Service::onBeforeAdminCronRun()): deletes the rows older than the days kept. Returns how many. */
    public static function prune(\Pimple\Container $di, mixed $saved = null): int
    {
        $deleted = 0;
        $settings = self::settings($saved ?? Config::getProperty('api.log', []));
        // Off = the cron does nothing; rows left from before stay until a source is on again.
        if (!in_array(true, $settings['sources'], true)) {
            return 0;
        }
        try {
            $before = date('Y-m-d H:i:s', time() - $settings['days'] * 86_400);
            $db = $di['dbal'];
            for ($batch = 0; $batch < self::PRUNE_BATCHES; ++$batch) {
                $ids = $db->fetchFirstColumn('SELECT id FROM ' . self::TABLE . ' WHERE created_at < ? ORDER BY id LIMIT ' . self::PRUNE_BATCH, [$before]);
                if ($ids === []) {
                    break;
                }
                $deleted += (int) $db->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            }
        } catch (\Throwable $e) {
            error_log('API log prune: ' . $e->getMessage());
        }

        return $deleted;
    }

    /** The list for Activity > System Activity > API log: newest first, filtered by source, staff, client, call and dates. */
    public static function search(\Pimple\Container $di, array $data): array
    {
        $where = [];
        $params = [];
        if (in_array($data['source'] ?? null, self::SOURCES, true)) {
            $where[] = 'l.source = :source';
            $params[':source'] = $data['source'];
        }
        foreach (['admin_id', 'client_id'] as $column) {
            if ((int) ($data[$column] ?? 0) > 0) {
                $where[] = "l.{$column} = :{$column}";
                $params[":{$column}"] = (int) $data[$column];
            }
        }
        if (is_string($data['call'] ?? null) && trim($data['call']) !== '') {
            $where[] = 'l.api_call LIKE :call';
            $params[':call'] = '%' . addcslashes(trim($data['call']), '%_\\') . '%';
        }
        foreach (['date_from' => ['>=', '00:00:00'], 'date_to' => ['<=', '23:59:59']] as $field => [$op, $time]) {
            $when = is_string($data[$field] ?? null) && $data[$field] !== '' ? strtotime($data[$field]) : false;
            if ($when !== false) {
                $where[] = "l.created_at {$op} :{$field}";
                $params[":{$field}"] = date('Y-m-d ', $when) . $time;
            }
        }
        $sql = 'SELECT l.*, a.name AS staff_name, c.first_name AS client_first_name, c.last_name AS client_last_name FROM ' . self::TABLE . ' l
            LEFT JOIN admin a ON a.id = l.admin_id LEFT JOIN client c ON c.id = l.client_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY l.id DESC';

        return $di['pager']->getPaginatedResultSet($sql, $params, PaginationOptions::fromArray($data));
    }
}
