<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\InformationException;

/**
 * ClientFence: staff bell alerts forwarded to chat, as WHMCS's Slack notification rules. Super
 * Administrators add destinations (Slack or Discord incoming webhooks, or a Telegram bot and chat),
 * each with the bell kinds it gets (Notifications::staffKinds()). A Notifications::staff() call that
 * saved a bell row queues one message (the bell text in the install's default language and a link to
 * the admin page); the queue is sent after the page has answered. Settings are kept in
 * mod_clientfence_state; the webhook URL or bot token is encrypted and never shown again. Each
 * destination's last error is kept apart (STATUS), so a send never rewrites the settings.
 * Page: System → Settings → Email → Chat alerts.
 */
final class ChatAlerts
{
    public const STATE = 'chat_alerts';
    public const STATUS = 'chat_alerts_status';
    public const TYPES = ['slack', 'discord', 'telegram'];
    /** The only addresses a webhook may have (no other host is ever called). */
    private const WEBHOOKS = [
        'slack' => ['https://hooks.slack.com/'],
        'discord' => ['https://discord.com/api/webhooks/', 'https://discordapp.com/api/webhooks/'],
    ];
    private const TIMEOUT = 5;

    /** @var list<array{0: string, 1: array, 2: string}> kind, params and admin address of each alert of this request */
    private static array $queue = [];

    /** Queues a staff alert for the destinations that take its kind; sent once the page has answered. */
    public static function queue(\Pimple\Container $di, string $kind, array $params, string $url): void
    {
        if (self::takers(self::destinations($di), $kind) === []) {
            return;
        }
        if (self::$queue === []) {
            register_shutdown_function(static function () use ($di): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                self::flush($di);
            });
        }
        self::$queue[] = [$kind, $params, $url];
    }

    /** Sends the queued alerts; a failure is logged and kept as the destination's last error, never thrown. */
    public static function flush(\Pimple\Container $di): void
    {
        $queue = self::$queue;
        self::$queue = [];
        if ($queue === []) {
            return;
        }
        try {
            $destinations = self::destinations($di);
            $messages = \ClientFence\Translate::with((string) \ClientFence\Config::getProperty('i18n.locale', 'en_US'), static fn (): array => array_map(
                static fn (array $alert): array => [$alert[0], Notifications::text($alert[0], $alert[1]), $di['url']->adminLink($alert[2])],
                $queue
            ));
            $results = [];
            foreach ($messages as [$kind, $text, $link]) {
                foreach (self::takers($destinations, $kind) as $destination) {
                    $results[$destination['id']] = self::send($di, $destination, $text, $link);
                }
            }
            self::record($di, $results);
        } catch (\Throwable $e) {
            $di['logger']->warning('Chat alerts not sent: %s', $e->getMessage());
        }
    }

    /** Sends a test message to one destination now (on or off); a failure throws its reason. */
    public static function test(\Pimple\Container $di, int $id): void
    {
        $destination = self::find($di, $id);
        $error = self::send($di, $destination, __trans('Test message from ClientFence'), $di['url']->adminLink(''));
        self::record($di, [$id => $error]);
        if ($error !== null) {
            throw new InformationException('The test message was not sent: :error', [':error' => $error]);
        }
    }

    /** The destinations for the page: no secret, only a masked hint of it, with the last error. */
    public static function forStaff(\Pimple\Container $di): array
    {
        $status = self::state($di, self::STATUS);
        $out = [];
        foreach (self::destinations($di) as $destination) {
            $secret = (string) $di['crypt']->decrypt($destination['secret']);
            $out[] = ['hint' => $secret === '' ? '' : '…' . mb_substr($secret, -4)]
                + array_diff_key($destination, ['secret' => true])
                + ['error' => $status[$destination['id']]['error'] ?? null, 'error_at' => $status[$destination['id']]['at'] ?? null];
        }

        return ['destinations' => $out, 'kinds' => Notifications::staffKinds()];
    }

    /**
     * Adds (no `id`) or changes a destination: name, type, enabled, webhook_url (Slack, Discord) or
     * bot_token and chat_id (Telegram), kinds. An empty webhook URL or bot token keeps the saved one.
     */
    public static function save(\Pimple\Container $di, array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $old = $id > 0 ? self::find($di, $id) : null;
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InformationException('Enter a name of up to 100 characters.');
        }
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new InformationException('Choose Slack, Discord or Telegram.');
        }
        $telegram = $type === 'telegram';
        $secret = trim((string) ($data[$telegram ? 'bot_token' : 'webhook_url'] ?? ''));
        if ($secret === '') {
            // A saved secret belongs to its type: a Slack URL is no Telegram token.
            if ($old === null || $old['type'] !== $type) {
                throw new InformationException($telegram ? 'Enter the bot token.' : 'Enter the webhook URL.');
            }
            $stored = $old['secret'];
        } else {
            self::checkSecret($type, $secret);
            $stored = (string) $di['crypt']->encrypt($secret);
        }
        $chatId = $telegram ? trim((string) ($data['chat_id'] ?? '')) : '';
        if ($telegram && preg_match('/\A(-?\d{1,20}|@[A-Za-z0-9_]{5,32})\z/', $chatId) !== 1) {
            throw new InformationException('Enter the Telegram chat ID: a number, or @ and the channel name.');
        }
        $known = array_merge(...array_values(array_map('array_keys', Notifications::staffKinds())));
        $list = self::destinations($di);
        $id = $old === null ? max([0, ...array_column($list, 'id')]) + 1 : $id;
        $row = [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'enabled' => in_array(strtolower((string) ($data['enabled'] ?? '')), ['1', 'true', 'on', 'yes'], true),
            'secret' => $stored,
            'chat_id' => $chatId,
            'kinds' => array_values(array_intersect($known, array_map('strval', (array) ($data['kinds'] ?? [])))),
        ];
        $list = array_values(array_filter($list, static fn (array $d): bool => $d['id'] !== $id));
        $list[] = $row;
        usort($list, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        self::saveState($di, self::STATE, ['destinations' => $list]);

        return $id;
    }

    public static function delete(\Pimple\Container $di, int $id): void
    {
        self::find($di, $id);
        self::saveState($di, self::STATE, ['destinations' => array_values(array_filter(self::destinations($di), static fn (array $d): bool => $d['id'] !== $id))]);
        $status = self::state($di, self::STATUS);
        unset($status[$id]);
        self::saveState($di, self::STATUS, $status);
    }

    /** @return list<array{id: int, name: string, type: string, enabled: bool, secret: string, chat_id: string, kinds: list<string>}> */
    private static function destinations(\Pimple\Container $di): array
    {
        $list = self::state($di, self::STATE)['destinations'] ?? [];

        return is_array($list) ? array_values($list) : [];
    }

    private static function takers(array $destinations, string $kind): array
    {
        return array_filter($destinations, static fn (array $d): bool => $d['enabled'] && in_array($kind, $d['kinds'], true));
    }

    private static function find(\Pimple\Container $di, int $id): array
    {
        foreach (self::destinations($di) as $destination) {
            if ($destination['id'] === $id) {
                return $destination;
            }
        }

        throw new InformationException('This chat destination no longer exists.');
    }

    private static function checkSecret(string $type, string $secret): void
    {
        if ($type === 'telegram') {
            if (preg_match('/\A\d+:[A-Za-z0-9_-]+\z/', $secret) !== 1) {
                throw new InformationException('Enter the bot token BotFather gave you, such as 123456:ABC-DEF.');
            }

            return;
        }
        $allowed = array_filter(self::WEBHOOKS[$type], static fn (string $prefix): bool => str_starts_with($secret, $prefix));
        if ($allowed === [] || preg_match('/[\s\x00-\x1f\x7f]/', $secret) === 1) {
            throw new InformationException('The webhook URL must start with :prefix', [':prefix' => self::WEBHOOKS[$type][0]]);
        }
    }

    /** Posts one message (the text, then the link); the error (secret removed) or null when the service took it. */
    private static function send(\Pimple\Container $di, array $destination, string $text, string $link): ?string
    {
        $secret = (string) $di['crypt']->decrypt($destination['secret']);
        [$url, $body] = match ($destination['type']) {
            // Slack reads <, > and & as markup (links, @channel).
            'slack' => [$secret, ['text' => strtr($text . "\n" . $link, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'])]],
            // No @everyone pings and no preview of the admin page (flag 4: suppress embeds).
            // Markdown escaped too, so client text (a ticket subject) cannot pose as a link.
            'discord' => [$secret, ['content' => preg_replace('/[\\\\*_~`|\[\]()#>-]/', '\\\\$0', $text) . "\n" . $link, 'allowed_mentions' => ['parse' => []], 'flags' => 4]],
            default => ['https://api.telegram.org/bot' . $secret . '/sendMessage', ['chat_id' => $destination['chat_id'], 'text' => $text . "\n" . $link, 'disable_web_page_preview' => true]],
        };
        try {
            $response = $di['http_client']->request('POST', $url, ['json' => $body, 'timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT, 'max_redirects' => 0]);
            $status = $response->getStatusCode();
            if ($status < 300) {
                return null;
            }
            $error = 'HTTP ' . $status . ': ' . mb_substr(trim($response->getContent(false)), 0, 200);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        // Transport errors name the address, which holds the webhook secret or bot token.
        $error = $secret === '' ? $error : str_replace($secret, '…', $error);
        $di['logger']->warning('Chat alert to %s not sent: %s', $destination['name'], $error);

        return $error;
    }

    /** Keeps each destination's last error (null: it worked); written only when something changed. */
    private static function record(\Pimple\Container $di, array $results): void
    {
        $status = self::state($di, self::STATUS);
        $before = $status;
        foreach ($results as $id => $error) {
            if ($error === null) {
                unset($status[$id]);
            } else {
                $status[$id] = ['error' => $error, 'at' => date('Y-m-d H:i:s')];
            }
        }
        if ($status !== $before) {
            self::saveState($di, self::STATUS, $status);
        }
    }

    private static function state(\Pimple\Container $di, string $name): array
    {
        try {
            $row = $di['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [$name]);
        } catch (\Throwable) {
            return [];
        }
        $value = is_string($row) ? json_decode($row, true) : null;

        return is_array($value) ? $value : [];
    }

    private static function saveState(\Pimple\Container $di, string $name, array $value): void
    {
        $di['em']->getConnection()->executeStatement(
            'REPLACE INTO mod_clientfence_state (name, value, updated_at) VALUES (?, ?, ?)',
            [$name, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]
        );
    }
}
