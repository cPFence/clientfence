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

use ClientFence\InformationException;

final class Hetzner extends Provider
{
    private const BASE = 'https://api.hetzner.cloud/v1/zones';

    public function createZone(string $domain): array
    {
        $answer = $this->call('POST', '', null, ['json' => ['name' => $domain, 'mode' => 'primary']]);
        $this->waitForAction($answer['action'] ?? []);
        $this->require(!empty($answer['zone']['id']));

        return ['id' => $domain, 'nameservers' => $answer['zone']['authoritative_nameservers']['assigned'] ?? []];
    }

    public function deleteZone(string $zone): void
    {
        $answer = $this->call('DELETE', '/' . rawurlencode($zone), null, allowMissing: true);
        if ($answer !== null) {
            $this->waitForAction($answer['action'] ?? []);
        }
    }

    public function records(string $zone, string $domain): array
    {
        $records = [];
        $page = 1;
        do {
            $answer = $this->call('GET', $this->rrsetsPath($zone), 200, ['query' => ['page' => $page, 'per_page' => 50]]);
            $this->require(is_array($answer['rrsets'] ?? null));
            foreach ($answer['rrsets'] as $rrset) {
                foreach ($rrset['records'] as $record) {
                    $ttl = $rrset['ttl'] ?? ($zoneTtl ??= $this->call('GET', '/' . rawurlencode($zone), 200)['zone']['ttl']);
                    $records[] = self::rrRecord(self::relative($rrset['name'], $domain), $rrset['type'], $record['value'], $ttl);
                }
            }
            $page = $answer['meta']['pagination']['next_page'] ?? null;
        } while ($page !== null);

        return $records;
    }

    public function addRecord(string $zone, string $domain, array $record): void
    {
        $this->change($this->rrsetsPath($zone, $record), 'add_records', [
            'ttl' => $record['ttl'], 'records' => [['value' => self::rdata($record)]],
        ]);
    }

    public function updateRecord(string $zone, string $domain, array $old, array $record): void
    {
        $path = $this->rrsetsPath($zone, $old);
        $rrset = $this->call('GET', $path, 200)['rrset'];
        $records = $rrset['records'];
        $values = array_column($records, 'value');
        $index = array_search($old['rdata'], $values, true);
        if ($index === false) {
            throw new InformationException('DNS record not found. Refresh the page and try again.');
        }
        $value = self::rdata($record);
        if ($value !== $old['rdata']) {
            if (in_array($value, $values, true)) {
                throw new InformationException('This DNS record value already exists.');
            }
            $records[$index]['value'] = $value;
            $this->change($path, 'set_records', ['records' => $records]);
        }
        if (($rrset['ttl'] ?? 0) !== $record['ttl']) {
            $this->change($path, 'change_ttl', ['ttl' => $record['ttl']]);
        }
    }

    public function deleteRecord(string $zone, string $domain, array $record): void
    {
        $this->change($this->rrsetsPath($zone, $record), 'remove_records', ['records' => [['value' => $record['rdata']]]]);
    }

    private function rrsetsPath(string $zone, ?array $record = null): string
    {
        return '/' . rawurlencode($zone) . '/rrsets'
            . ($record === null ? '' : '/' . rawurlencode($record['name'] === '' ? '@' : $record['name']) . '/' . $record['type']);
    }

    private function change(string $path, string $action, array $body): void
    {
        $answer = $this->call('POST', $path . '/actions/' . $action, null, ['json' => $body]);
        $this->waitForAction($answer['action'] ?? []);
    }

    private function waitForAction(array $action): void
    {
        $deadline = microtime(true) + 60;
        while (($action['status'] ?? '') === 'running' && isset($action['id']) && microtime(true) < $deadline) {
            usleep(250000);
            $action = $this->call('GET', '/actions/' . rawurlencode((string) $action['id']), 200)['action'] ?? [];
        }
        if (($action['status'] ?? '') !== 'success') {
            throw new InformationException('Hetzner DNS did not complete the requested action.', null, 502, true);
        }
    }

    private function call(string $method, string $path, ?int $status, array $options = [], bool $allowMissing = false): ?array
    {
        return $this->request($method, self::BASE . $path, $options + [
            'headers' => ['Authorization' => 'Bearer ' . $this->config['token'], 'Accept' => 'application/json'],
        ], $status, $allowMissing);
    }
}
