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

final class DigitalOcean extends Provider
{
    private const BASE = 'https://api.digitalocean.com/v2/domains';

    public function createZone(string $domain): array
    {
        $answer = $this->call('POST', '', 201, ['json' => ['name' => $domain]]);
        $this->require(($answer['domain']['name'] ?? null) === $domain);

        return ['id' => $domain, 'nameservers' => ['ns1.digitalocean.com', 'ns2.digitalocean.com', 'ns3.digitalocean.com']];
    }

    public function deleteZone(string $zone): void
    {
        $this->call('DELETE', '/' . rawurlencode($zone), 204, allowMissing: true);
    }

    public function records(string $zone, string $domain): array
    {
        $records = [];
        $page = 1;
        do {
            $answer = $this->call('GET', $this->recordsPath($zone), 200, ['query' => ['page' => $page++, 'per_page' => 200]]);
            $items = $answer['domain_records'] ?? null;
            $this->require(is_array($items));
            foreach ($items as $record) {
                $records[] = self::record(
                    (string) $record['id'], self::relative($record['name'], $domain), $record['type'],
                    $record['data'], $record['ttl'], $record['type'] === 'MX' ? (int) $record['priority'] : null,
                );
            }
        } while (count($items) === 200);

        return $records;
    }

    public function addRecord(string $zone, string $domain, array $record): void
    {
        $answer = $this->call('POST', $this->recordsPath($zone), 201, ['json' => $this->payload($record)]);
        $this->require(isset($answer['domain_record']['id']));
    }

    public function updateRecord(string $zone, string $domain, array $old, array $record): void
    {
        $answer = $this->call('PATCH', $this->recordsPath($zone, $old['id']), 200, ['json' => $this->payload($record)]);
        $this->require(is_array($answer['domain_record'] ?? null));
    }

    public function deleteRecord(string $zone, string $domain, array $record): void
    {
        $this->call('DELETE', $this->recordsPath($zone, $record['id']), 204);
    }

    private function recordsPath(string $zone, ?string $id = null): string
    {
        return '/' . rawurlencode($zone) . '/records' . ($id === null ? '' : '/' . rawurlencode($id));
    }

    private function payload(array $record): array
    {
        $payload = ['type' => $record['type'], 'name' => $record['name'] === '' ? '@' : $record['name'], 'data' => $record['value'], 'ttl' => $record['ttl']];
        if ($record['type'] === 'MX') {
            $payload['priority'] = $record['priority'];
        }

        return $payload;
    }

    private function call(string $method, string $path, int $status, array $options = [], bool $allowMissing = false): ?array
    {
        return $this->request($method, self::BASE . $path, $options + [
            'headers' => ['Authorization' => 'Bearer ' . $this->config['token'], 'Accept' => 'application/json'],
        ], $status, $allowMissing);
    }
}
