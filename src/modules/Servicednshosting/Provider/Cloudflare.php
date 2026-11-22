<?php

declare(strict_types=1);

/*
 * MIT License
 *
 * Copyright (c) 2024 Namingo
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * SPDX-License-Identifier: MIT
 */

namespace ClientFence\Mod\Servicednshosting\Provider;

final class Cloudflare extends Provider
{
    private const BASE = 'https://api.cloudflare.com/client/v4/zones';

    public function createZone(string $domain): array
    {
        $body = ['name' => $domain, 'jump_start' => false, 'account' => ['id' => $this->config['account_id']]];
        $zone = $this->call('POST', '', ['json' => $body])['result'] ?? [];
        $this->require(!empty($zone['id']));

        return ['id' => $zone['id'], 'nameservers' => $zone['name_servers'] ?? []];
    }

    public function deleteZone(string $zone): void
    {
        $this->call('DELETE', '/' . rawurlencode($zone), allowMissing: true);
    }

    public function records(string $zone, string $domain): array
    {
        $records = [];
        $page = 1;
        do {
            $answer = $this->call('GET', $this->recordsPath($zone), ['query' => ['page' => $page, 'per_page' => 100]]);
            $this->require(is_array($answer['result'] ?? null));
            foreach ($answer['result'] as $record) {
                $records[] = self::record(
                    $record['id'], self::relative($record['name'], $domain), $record['type'],
                    $record['type'] === 'TXT' ? self::rrRecord('', 'TXT', $record['content'], 0)['value'] : $record['content'],
                    $record['ttl'], $record['type'] === 'MX' ? (int) $record['priority'] : null,
                );
            }
        } while ($page++ < ($answer['result_info']['total_pages'] ?? 1));

        return $records;
    }

    public function addRecord(string $zone, string $domain, array $record): void
    {
        $this->call('POST', $this->recordsPath($zone), ['json' => $this->payload($domain, $record) + ['proxied' => false]]);
    }

    public function updateRecord(string $zone, string $domain, array $old, array $record): void
    {
        // PATCH keeps what the editor does not show (proxying, private routing, comments); PUT resets it.
        $this->call('PATCH', $this->recordsPath($zone, $old['id']), ['json' => $this->payload($domain, $record)]);
    }

    public function deleteRecord(string $zone, string $domain, array $record): void
    {
        $this->call('DELETE', $this->recordsPath($zone, $record['id']));
    }

    public function dnssec(string $zone): array
    {
        $status = $this->call('GET', '/' . rawurlencode($zone) . '/dnssec')['result'] ?? [];
        $this->require(isset($status['status']));
        $enabled = in_array($status['status'], ['active', 'pending'], true);
        $ds = [];
        if ($enabled && !empty($status['digest'])) {
            $ds[] = implode(' ', [$status['key_tag'], $status['algorithm'], $status['digest_type'], $status['digest']]);
        }

        return ['supported' => true, 'enabled' => $enabled, 'can_toggle' => true, 'ds' => $ds];
    }

    public function setDnssec(string $zone, bool $enabled): void
    {
        $result = $this->call('PATCH', '/' . rawurlencode($zone) . '/dnssec', [
            'json' => ['status' => $enabled ? 'active' : 'disabled'],
        ])['result'] ?? [];
        $this->require(in_array($result['status'] ?? '', $enabled ? ['active', 'pending'] : ['disabled', 'pending-disabled'], true));
    }

    private function recordsPath(string $zone, ?string $id = null): string
    {
        return '/' . rawurlencode($zone) . '/dns_records' . ($id === null ? '' : '/' . rawurlencode($id));
    }

    private function payload(string $domain, array $record): array
    {
        $payload = ['type' => $record['type'], 'name' => self::fqdn($record['name'], $domain), 'ttl' => $record['ttl']];
        if ($record['type'] === 'DS') {
            [$tag, $algorithm, $digestType, $digest] = explode(' ', $record['value']);
            $payload['data'] = ['key_tag' => (int) $tag, 'algorithm' => (int) $algorithm, 'digest_type' => (int) $digestType, 'digest' => $digest];
        } else {
            $payload['content'] = $record['type'] === 'TXT' ? self::rdata($record) : $record['value'];
        }
        if ($record['type'] === 'MX') {
            $payload['priority'] = $record['priority'];
        }

        return $payload;
    }

    private function call(string $method, string $path, array $options = [], bool $allowMissing = false): ?array
    {
        $token = $this->config['token'];
        if (str_contains($token, ':')) {
            [$email, $key] = explode(':', $token, 2);
            $headers = ['X-Auth-Email' => $email, 'X-Auth-Key' => $key];
        } else {
            $headers = ['Authorization' => 'Bearer ' . $token];
        }
        $answer = $this->request($method, self::BASE . $path, $options + ['headers' => $headers], null, $allowMissing);
        if ($allowMissing && $answer === null) {
            return null;
        }
        $this->require(($answer['success'] ?? false) === true);

        return $answer;
    }
}
