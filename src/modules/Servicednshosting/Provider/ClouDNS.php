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

final class ClouDNS extends Provider
{
    private const BASE = 'https://api.cloudns.net/dns/';

    public function createZone(string $domain): array
    {
        $this->write('register', $domain, ['zone-type' => 'master']);

        return ['id' => $domain, 'nameservers' => []];
    }

    public function deleteZone(string $zone): void
    {
        $answer = $this->call('delete', $zone);
        $this->require(($answer['status'] ?? '') === 'Success'
            || (($answer['status'] ?? '') === 'Failed' && ($answer['statusDescription'] ?? '') === 'Missing domain-name'));
    }

    public function records(string $zone, string $domain): array
    {
        $records = [];
        $answer = $this->call('records', $zone);
        $this->require(is_array($answer) && !isset($answer['status']));
        foreach ($answer as $id => $record) {
            $type = $record['type'];
            $value = $record['record'];
            if ($type === 'DS' && isset($record['key_tag'])) {
                $value = implode(' ', [$record['key_tag'], $record['algorithm'], $record['digest_type'], $value]);
            }
            $records[] = self::record((string) ($record['id'] ?? $id), self::relative($record['host'], $domain), $type, $value, (int) $record['ttl'], $type === 'MX' ? (int) $record['priority'] : null);
        }

        return $records;
    }

    public function addRecord(string $zone, string $domain, array $record): void
    {
        $this->write('add-record', $zone, $this->payload($record));
    }

    public function updateRecord(string $zone, string $domain, array $old, array $record): void
    {
        $this->write('mod-record', $zone, $this->payload($record) + ['record-id' => $old['id']]);
    }

    public function deleteRecord(string $zone, string $domain, array $record): void
    {
        $this->write('delete-record', $zone, ['record-id' => $record['id']]);
    }

    public function dnssec(string $zone): array
    {
        $available = $this->call('is-dnssec-available', $zone);
        $this->require(in_array($available, [0, 1, '0', '1'], true));
        $answer = $this->call('get-dnssec-ds-records', $zone);
        $ds = [];
        if (($answer['status'] ?? '') === 'Failed') {
            $reason = $answer['statusDescription'] ?? '';
            $this->require(str_contains(strtolower($reason), 'dnssec_not_active') || stripos($reason, 'DNSSEC is not active') !== false);
        } else {
            foreach ($answer['ds_records'] ?? [] as $entry) {
                $ds[] = implode(' ', [$entry['key_tag'], $entry['algorithm'], $entry['digest_type'], $entry['digest']]);
            }
            foreach ($answer['ds'] ?? [] as $line) {
                $ds[] = implode(' ', array_slice(preg_split('/\s+/', trim($line)), -4));
            }
        }

        return ['supported' => (bool) $available || $ds !== [], 'enabled' => $ds !== [], 'can_toggle' => (bool) $available, 'ds' => array_values(array_unique($ds))];
    }

    public function setDnssec(string $zone, bool $enabled): void
    {
        $this->write($enabled ? 'activate-dnssec' : 'deactivate-dnssec', $zone);
    }

    private function payload(array $record): array
    {
        $params = ['host' => $record['name'], 'record-type' => $record['type'], 'record' => $record['value'], 'ttl' => $record['ttl']];
        if ($record['type'] === 'MX') {
            $params['priority'] = $record['priority'];
        }
        if ($record['type'] === 'DS') {
            [$params['key_tag'], $params['algorithm'], $params['digest_type'], $params['record']] = explode(' ', $record['value']);
        }

        return $params;
    }

    private function write(string $endpoint, string $domain, array $params = []): void
    {
        $answer = $this->call($endpoint, $domain, $params);
        $this->require(($answer['status'] ?? '') === 'Success');
    }

    private function call(string $endpoint, string $domain, array $params = []): mixed
    {
        return $this->request('POST', self::BASE . $endpoint . '.json', ['body' => $params + [
            'domain-name' => $domain,
            'auth-id' => $this->config['auth_id'],
            'auth-password' => $this->config['password'],
        ]], 200);
    }
}
